<?php

/*
 * Release 1.0 (R1-3A): the organization-invitation foundation, on one connection. Design record:
 * docs/product/release-1.0-r1-3-organization-invitations.md. What a single connection CANNOT show (lock order under contention, who blocks
 * whom, both execution orders of every race) is in OrganizationInvitationConcurrencyTest, with real second connections. Passing here proves the
 * rules, never the concurrency.
 *
 * Tests that must make something go wrong inside the acceptance transaction do it from a DB::listen callback: it runs on the connection
 * that holds the locks, right after a named statement, so a failure or an interference lands at an exact step.
 */

use App\Exceptions\OrganizationInvitationException;
use App\Exceptions\OrganizationMembershipException;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\AccessGrants;
use App\Services\AccountLifecycleService;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Support\Database\DuplicateKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->sent = [];
    $this->logged = [];

    // Every mail that passed shouldSend() and was about to go out, with the token it carries (private to the notification).
    Event::listen(NotificationSending::class, function (NotificationSending $event) {
        if ($event->notification instanceof OrganizationInvitationNotification) {
            $reflection = new ReflectionObject($event->notification);
            $this->sent[] = [
                'id' => $reflection->getProperty('invitationId')->getValue($event->notification),
                'token' => $reflection->getProperty('token')->getValue($event->notification),
                'to' => $event->notifiable instanceof AnonymousNotifiable ? $event->notifiable->routes['mail'] : null,
            ];
        }
    });
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });

    $this->operator = oinvOperator();
    $this->org = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $this->operator->id]);
});

// ── Fixtures ─────────────────────────────────────────────────────────────────

/** An active account holding portal.operator AND crm.manage directly: a membership manager. */
function oinvOperator(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->givePermissionTo(['portal.operator', 'crm.manage']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function oinvCustomer(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('user');

    return $user;
}

function oinv(): OrganizationInvitations
{
    return app(OrganizationInvitations::class);
}

/** An open invitation written straight to the table, with the plaintext token the test knows. @return array{0: OrganizationInvitation, 1: string} */
function oinvRow(Organization $org, User $issuer, string $email = 'invitee@example.test', array $state = []): array
{
    $token = Str::random(64);
    $invitation = OrganizationInvitation::factory()->withToken($token)->forAddress($email)->state(['organization_id' => $org->id, 'invited_by' => $issuer->id] + $state)->create();

    return [$invitation, $token];
}

function oinvAccept(string $token, string $name = 'Newcomer', string $password = 'a-Strong-Passw0rd!'): User
{
    return oinv()->accept($token, InvitationAcceptance::withPassword($name, Hash::make($password)));
}

/** A snapshot of every table acceptance can write, so "writes nothing" is a comparison and not a feeling. */
function oinvFootprint(): array
{
    return [
        'users' => DB::table('users')->count(),
        'members' => DB::table('organization_members')->count(),
        'roles' => DB::table('model_has_roles')->count(),
        'histories' => DB::table('password_histories')->count(),
        'social' => DB::table('social_accounts')->count(),
        'activity' => DB::table('activity_log')->count(),
        'invitations' => DB::table('organization_invitations')->get()->map(fn ($r) => (array) $r)->all(),
    ];
}

function oinvRefusal(callable $action): ?OrganizationInvitationException
{
    try {
        $action();
    } catch (OrganizationInvitationException $e) {
        return $e;
    }

    return null;
}

/** Run $callback after the first query whose SQL matches $pattern has executed on this connection. */
function oinvAfterQuery(string $pattern, Closure $callback): void
{
    $done = false;
    DB::listen(function ($query) use ($pattern, $callback, &$done) {
        if (! $done && preg_match($pattern, $query->sql) === 1) {
            $done = true;
            $callback();
        }
    });
}

function oinvActivity(string $event): Collection
{
    return DB::table('activity_log')->where('description', $event)->orderBy('id')->get()->map(function ($row) {
        $row->properties = json_decode($row->properties, true);

        return $row;
    });
}

// ── Issue ────────────────────────────────────────────────────────────────────

describe('issue', function () {
    it('stores only the hash, a seven-day expiry and the slot, queues one mail whose token matches the hash, and audits without the token', function () {
        Carbon::setTestNow('2026-11-11 10:00:00');

        $invitation = oinv()->issue($this->org, 'Invitee@Example.test', $this->operator);

        $row = DB::table('organization_invitations')->first();
        expect($row->email)->toBe('invitee@example.test')
            ->and($row->live_email)->toBe('invitee@example.test')
            ->and((int) $row->organization_id)->toBe($this->org->id)
            ->and((int) $row->invited_by)->toBe($this->operator->id)
            ->and($row->expires_at)->toBe('2026-11-18 10:00:00')
            ->and($row->accepted_at)->toBeNull()->and($row->revoked_at)->toBeNull()->and($row->revoked_reason)->toBeNull()
            ->and($row->token_hash)->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($invitation->state())->toBe(OrganizationInvitation::PENDING);

        expect($this->sent)->toHaveCount(1)
            ->and($this->sent[0]['to'])->toBe('invitee@example.test')
            ->and($this->sent[0]['id'])->toBe($invitation->id)
            ->and(hash('sha256', $this->sent[0]['token']))->toBe($row->token_hash)
            ->and($this->sent[0]['token'])->toMatch('/\A[A-Za-z0-9]{64}\z/');

        $entry = oinvActivity(OrganizationInvitations::ISSUED)->sole();
        expect($entry->subject_type)->toBe(Organization::class)->and((int) $entry->subject_id)->toBe($this->org->id)
            ->and((int) $entry->causer_id)->toBe($this->operator->id)
            ->and($entry->properties)->toMatchArray(['invitation_id' => $invitation->id, 'email' => 'invitee@example.test', 'invited_by' => $this->operator->id])
            ->and(json_encode($entry->properties))->not->toContain($this->sent[0]['token'])->not->toContain($row->token_hash);
    });

    it('refuses every actor who is not a membership manager and writes nothing', function (string $case) {
        $actor = match ($case) {
            'a customer' => oinvCustomer(),
            'an approved organization administrator' => tap(oinvCustomer(), fn ($admin) => DB::table('organization_members')->insert(['organization_id' => $this->org->id, 'user_id' => $admin->id, 'role' => 'admin', 'admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id, 'created_at' => now(), 'updated_at' => now()])),
            'a holder of org.invite alone' => tap(User::factory()->create(), fn ($u) => $u->givePermissionTo('org.invite')),
            'a holder of crm.manage without eligibility' => tap(User::factory()->create(), fn ($u) => $u->givePermissionTo('crm.manage')),
            'a holder of portal.operator without crm.manage' => tap(User::factory()->create(), fn ($u) => $u->givePermissionTo('portal.operator')),
            'a suspended operator' => oinvOperator(['disabled_at' => now()]),
        };
        $before = oinvFootprint();

        expect(fn () => oinv()->issue($this->org, 'x@example.test', $actor))->toThrow(AuthorizationException::class);
        expect(oinvFootprint())->toBe($before)->and($this->sent)->toBe([]);
    })->with([
        'a customer', 'an approved organization administrator', 'a holder of org.invite alone',
        'a holder of crm.manage without eligibility', 'a holder of portal.operator without crm.manage', 'a suspended operator',
    ]);

    it('refuses an address that already belongs to an account, active or suspended, in any letter case, and writes nothing', function (array $account) {
        User::factory()->create($account + ['email' => 'Taken@Example.test']);
        $before = oinvFootprint();

        $refusal = oinvRefusal(fn () => oinv()->issue($this->org, ' taken@EXAMPLE.test ', $this->operator));

        expect($refusal?->reason)->toBe(OrganizationInvitationException::ACCOUNT_EXISTS)->and(oinvFootprint())->toBe($before)->and($this->sent)->toBe([]);
    })->with(['an active account' => [[]], 'a suspended account' => [['disabled_at' => '2026-01-01 00:00:00']]]);

    it('answers 404-style (not found) for an organization that does not exist', function () {
        $ghost = new Organization;
        $ghost->forceFill(['id' => 999999]);
        $ghost->exists = true;

        expect(oinvRefusal(fn () => oinv()->issue($ghost, 'x@example.test', $this->operator))?->reason)->toBe(OrganizationInvitationException::NOT_FOUND);
    });

    it('supersedes an open predecessor on re-issue: its token dies, its slot is released, exactly one row holds the slot', function () {
        $first = oinv()->issue($this->org, 'again@example.test', $this->operator);
        $firstToken = $this->sent[0]['token'];
        $second = oinv()->issue($this->org, 'again@example.test', $this->operator);

        $old = DB::table('organization_invitations')->find($first->id);
        expect($old->revoked_reason)->toBe('superseded')->and((int) $old->revoked_by)->toBe($this->operator->id)->and($old->revoked_at)->not->toBeNull()
            ->and($old->live_email)->toBeNull()
            ->and(DB::table('organization_invitations')->where('live_email', 'again@example.test')->pluck('id')->all())->toBe([$second->id])
            ->and(oinvRefusal(fn () => oinvAccept($firstToken))?->detail)->toBe('revoked');

        $entry = oinvActivity(OrganizationInvitations::SUPERSEDED)->sole();
        expect($entry->properties)->toMatchArray(['invitation_id' => $first->id, 'replaced_by' => $second->id, 'reason' => 'superseded', 'source' => 'reissue']);
    });

    it('re-issues after expiry with no cleanup: the expired row keeps its Expired state, only its slot is released', function () {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $first = oinv()->issue($this->org, 'late@example.test', $this->operator);
        Carbon::setTestNow('2026-11-19 10:00:00'); // eight days later: expired, still holding the slot (nothing ran at expiry)

        expect(DB::table('organization_invitations')->find($first->id)->live_email)->toBe('late@example.test');

        $second = oinv()->issue($this->org, 'late@example.test', $this->operator);

        $old = DB::table('organization_invitations')->find($first->id);
        expect($old->revoked_at)->toBeNull()->and($old->revoked_reason)->toBeNull()->and($old->revoked_by)->toBeNull()
            ->and($old->live_email)->toBeNull()
            ->and(OrganizationInvitation::find($first->id)->state())->toBe(OrganizationInvitation::EXPIRED)
            ->and(DB::table('organization_invitations')->where('live_email', 'late@example.test')->pluck('id')->all())->toBe([$second->id])
            ->and(oinvActivity(OrganizationInvitations::SUPERSEDED))->toHaveCount(0);
    });

    it('allows the same address in two organizations, independently', function () {
        $other = Organization::factory()->create(['owner_id' => $this->operator->id]);

        oinv()->issue($this->org, 'both@example.test', $this->operator);
        oinv()->issue($other, 'both@example.test', $this->operator);

        expect(DB::table('organization_invitations')->where('live_email', 'both@example.test')->count())->toBe(2);
    });

    it('re-checks authority from the tables under the lock, so a stale Gate answer cannot issue', function () {
        // The Gate answer is cached for the life of the permission cache; a path outside the application removes the capability.
        expect($this->operator->can('crm.manage'))->toBeTrue();
        DB::table('model_has_permissions')->where('model_id', $this->operator->id)->where('permission_id', Permission::findByName('crm.manage')->id)->delete();

        expect(fn () => oinv()->issue($this->org, 'x@example.test', $this->operator))->toThrow(AuthorizationException::class);
        expect(DB::table('organization_invitations')->count())->toBe(0);
    });
});

// ── Revoke ───────────────────────────────────────────────────────────────────

describe('revoke', function () {
    it('revokes an open invitation: state, actor, reason, slot, audit; the token and the mail die', function () {
        $invitation = oinv()->issue($this->org, 'r@example.test', $this->operator);
        $token = $this->sent[0]['token'];

        expect(oinv()->revoke($this->org, $invitation->id, $this->operator))->toBeTrue();

        $row = DB::table('organization_invitations')->find($invitation->id);
        expect($row->revoked_reason)->toBe('operator')->and((int) $row->revoked_by)->toBe($this->operator->id)->and($row->live_email)->toBeNull()
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::REVOKED)
            ->and(oinvRefusal(fn () => oinvAccept($token))?->detail)->toBe('revoked')
            ->and(oinv()->findOpen($token))->toBeNull();

        $entry = oinvActivity(OrganizationInvitations::REVOKED)->sole();
        expect($entry->properties)->toMatchArray(['invitation_id' => $invitation->id, 'reason' => 'operator', 'source' => 'operator', 'invited_by' => $this->operator->id]);
    });

    it('is idempotent on a terminal row: accepted, revoked and expired rows are left exactly as they are, with no second audit entry', function () {
        $recipient = oinvCustomer();
        [$accepted] = oinvRow($this->org, $this->operator, 'a@example.test', ['accepted_at' => now(), 'accepted_user_id' => $recipient->id, 'live_email' => null]);
        [$revoked] = oinvRow($this->org, $this->operator, 'b@example.test', ['revoked_at' => now()->subHour(), 'revoked_reason' => 'operator', 'live_email' => null]);
        [$expired] = oinvRow($this->org, $this->operator, 'c@example.test', ['expires_at' => now()->subMinute()]);
        $before = DB::table('organization_invitations')->orderBy('id')->get()->all();

        foreach ([$accepted, $revoked, $expired] as $row) {
            expect(oinv()->revoke($this->org, $row->id, $this->operator))->toBeFalse();
        }

        expect(DB::table('organization_invitations')->orderBy('id')->get()->all())->toEqual($before)
            ->and(oinvActivity(OrganizationInvitations::REVOKED))->toHaveCount(0);
    });

    it('does not find another organization\'s invitation, and refuses a customer', function () {
        $other = Organization::factory()->create(['owner_id' => $this->operator->id]);
        [$foreign] = oinvRow($other, $this->operator);

        expect(oinvRefusal(fn () => oinv()->revoke($this->org, $foreign->id, $this->operator))?->reason)->toBe(OrganizationInvitationException::NOT_FOUND);
        expect(fn () => oinv()->revoke($other, $foreign->id, oinvCustomer()))->toThrow(AuthorizationException::class);
        expect(DB::table('organization_invitations')->find($foreign->id)->revoked_at)->toBeNull();
    });
});

// ── Accept ───────────────────────────────────────────────────────────────────

describe('accept', function () {
    it('atomically creates the account, the ordinary unapproved membership, and consumes the invitation', function () {
        $invitation = oinv()->issue($this->org, 'New.Person@Example.test', $this->operator);
        $token = $this->sent[0]['token'];

        $user = oinvAccept($token, 'New Person', 'a-Strong-Passw0rd!');

        expect($user->email)->toBe('new.person@example.test')->and($user->name)->toBe('New Person')
            ->and(Hash::check('a-Strong-Passw0rd!', $user->fresh()->password))->toBeTrue()
            ->and((int) $user->invited_by)->toBe($this->operator->id)->and($user->invitation_id)->toBeNull()->and($user->disabled_at)->toBeNull()
            ->and($user->getRoleNames()->all())->toBe(['user'])
            ->and(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(1);

        $member = DB::table('organization_members')->where('user_id', $user->id)->get();
        expect($member)->toHaveCount(1)
            ->and($member[0]->role)->toBe('member')->and((int) $member[0]->organization_id)->toBe($this->org->id)
            ->and($member[0]->admin_approved_at)->toBeNull()->and($member[0]->admin_approved_by)->toBeNull();

        $row = DB::table('organization_invitations')->find($invitation->id);
        expect($row->accepted_at)->not->toBeNull()->and((int) $row->accepted_user_id)->toBe($user->id)->and($row->live_email)->toBeNull()->and($row->revoked_at)->toBeNull();

        $entry = oinvActivity(OrganizationMembership::ADDED_BY_INVITATION)->sole();
        expect((int) $entry->causer_id)->toBe($user->id)->and((int) $entry->subject_id)->toBe($this->org->id)
            ->and($entry->properties)->toMatchArray(['invitation_id' => $invitation->id, 'invited_by' => $this->operator->id, 'new' => ['role' => 'member', 'approved' => false]])
            ->and(json_encode($entry->properties))->not->toContain($token);

        // Single use.
        expect(oinvRefusal(fn () => oinvAccept($token))?->detail)->toBe('accepted');
    });

    it('answers every dead link with the same refusal and writes nothing', function (string $case, string $detail) {
        $token = match ($case) {
            'unknown' => Str::random(64),
            'malformed' => 'short',
            'accepted' => oinvRow($this->org, $this->operator, 'a@example.test', ['accepted_at' => now(), 'accepted_user_id' => oinvCustomer()->id, 'live_email' => null])[1],
            'revoked' => oinvRow($this->org, $this->operator, 'b@example.test', ['revoked_at' => now(), 'revoked_reason' => 'operator', 'live_email' => null])[1],
            'expired' => oinvRow($this->org, $this->operator, 'c@example.test', ['expires_at' => now()->subSecond()])[1],
            'slot lost' => oinvRow($this->org, $this->operator, 'd@example.test', ['live_email' => null])[1],
            'the address already has an account (any case)' => (function () {
                User::factory()->create(['email' => 'E@Example.test']);

                return oinvRow($this->org, $this->operator, 'e@example.test')[1];
            })(),
            'a suspended issuer, however it came about' => oinvRow($this->org, oinvOperator(['disabled_at' => now()]), 'f@example.test')[1],
            'an issuer who lost authority outside the application' => (function () {
                $issuer = oinvOperator();
                [, $token] = oinvRow($this->org, $issuer, 'g@example.test');
                DB::table('model_has_permissions')->where('model_id', $issuer->id)->delete();

                return $token;
            })(),
        };
        $before = oinvFootprint();

        $refusal = oinvRefusal(fn () => oinvAccept($token));

        expect($refusal?->reason)->toBe(OrganizationInvitationException::UNAVAILABLE)
            ->and($refusal->getMessage())->toBe('This invitation is no longer available.')
            ->and($refusal->detail)->toBe($detail)
            ->and(oinvFootprint())->toBe($before);
    })->with([
        ['unknown', 'unknown'], ['malformed', 'malformed'], ['accepted', 'accepted'], ['revoked', 'revoked'], ['expired', 'expired'],
        ['slot lost', 'slot_lost'], ['the address already has an account (any case)', 'account_exists'],
        ['a suspended issuer, however it came about', 'issuer_unauthorized'], ['an issuer who lost authority outside the application', 'issuer_unauthorized'],
    ]);

    it('refuses an invitation superseded by a re-issue', function () {
        oinv()->issue($this->org, 'sup@example.test', $this->operator);
        $old = $this->sent[0]['token'];
        oinv()->issue($this->org, 'sup@example.test', $this->operator);

        expect(oinvRefusal(fn () => oinvAccept($old))?->detail)->toBe('revoked')
            ->and(oinvAccept($this->sent[1]['token'])->email)->toBe('sup@example.test');
    });

    it('takes the issuer row (shared), then the organization row, then the invitation row, before any write, and stays off the cache tables', function () {
        [, $token] = oinvRow($this->org, $this->operator, 'order@example.test');

        DB::flushQueryLog();
        DB::enableQueryLog();
        oinvAccept($token);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        // The password hash is made before the transaction (oinvAccept), so the first statements of acceptance are the three locks.
        $locks = $log->filter(fn ($sql) => str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode'))->values();
        expect($locks[0])->toContain('from `users`')->toContain('lock in share mode')
            ->and($locks[1])->toContain('from `organizations`')->toContain('for update')
            ->and($locks[2])->toContain('from `organization_invitations`')->toContain('for update');

        // All three locks come BEFORE the first write: an invitation lock taken late (after the account is inserted) is not the canonical order.
        $invitationLock = $log->search(fn ($sql) => str_contains($sql, 'from `organization_invitations` where `id` = ?') && str_contains($sql, 'for update'));
        $accountInsert = $log->search(fn ($sql) => str_starts_with($sql, 'insert into `users`'));
        expect($invitationLock)->not->toBeFalse()->and($invitationLock)->toBeLessThan($accountInsert);

        $firstLock = $log->search(fn ($sql) => str_contains($sql, 'lock in share mode'));
        $firstWrite = $log->search(fn ($sql) => preg_match('/^(insert|update|delete)\b/i', $sql) === 1);
        $firstRead = $log->search(fn ($sql) => str_starts_with($sql, 'select') && ! str_contains($sql, 'for update') && ! str_contains($sql, 'lock in share mode') && ! str_contains($sql, 'organization_invitations` where `token_hash`'));
        expect($firstLock)->toBeLessThan($firstWrite)->and($firstLock)->toBeLessThan($firstRead);
        // Only the nomination (a plain read BEFORE the transaction) may precede the issuer lock.
        expect($log->take($firstLock)->count())->toBe(1);
        expect($log->filter(fn ($sql) => preg_match('/`(cache|cache_locks)`/', $sql) === 1))->toHaveCount(0);
    });

    it('rolls back everything when any step fails, leaving no account, membership or audit and the invitation open', function (string $pattern) {
        [$invitation, $token] = oinvRow($this->org, $this->operator, 'boom@example.test');
        $before = oinvFootprint();

        DB::listen(function ($query) use ($pattern) {
            if (preg_match($pattern, $query->sql) === 1) {
                throw new RuntimeException('injected failure');
            }
        });

        expect(fn () => oinvAccept($token))->toThrow(RuntimeException::class, 'injected failure');
        expect(oinvFootprint())->toBe($before)
            ->and(DB::table('organization_invitations')->find($invitation->id)->live_email)->toBe('boom@example.test');
    })->with([
        'the account insert' => ['/^insert into `users`/'],
        'the role assignment' => ['/^insert into `model_has_roles`/'],
        'the password history' => ['/^insert into `password_histories`/'],
        'the membership insert' => ['/^insert into `organization_members`/'],
        'the audit write' => ['/^insert into `activity_log`/'],
        'the invitation update' => ['/^update `organization_invitations` set `accepted_at`/'],
    ]);

    it('revalidates inside the membership seam, under the orchestrator\'s locks, when something changes after the orchestrator looked', function (string $case, string $reason) {
        [$invitation, $token] = oinvRow($this->org, $this->operator, 'late@example.test');
        $before = oinvFootprint();

        // After the account and its history exist, and just before the membership is written.
        oinvAfterQuery('/^insert into `password_histories`/', function () use ($case, $invitation) {
            match ($case) {
                'the invitation was revoked' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['revoked_at' => now(), 'live_email' => null]),
                'the invitation lost its slot' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['live_email' => null]),
                'the invitation expired' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['expires_at' => now()->subSecond()]),
                'the issuer was suspended' => DB::table('users')->where('id', $this->operator->id)->update(['disabled_at' => now()]),
                'the issuer lost a capability' => DB::table('model_has_permissions')->where('model_id', $this->operator->id)->delete(),
                'the new account no longer carries the invited address' => DB::table('users')->where('email', 'late@example.test')->update(['email' => 'someone.else@example.test']),
                'the new account was suspended' => DB::table('users')->where('email', 'late@example.test')->update(['disabled_at' => now()]),
            };
        });

        $failure = null;
        try {
            oinvAccept($token);
        } catch (Throwable $e) {
            $failure = $e;
        }

        expect($failure)->not->toBeNull()->and(oinvFootprint())->toBe($before);
        if ($reason === 'suspended') {
            expect($failure)->toBeInstanceOf(OrganizationMembershipException::class)->and($failure->reason)->toBe(OrganizationMembershipException::ACCOUNT_SUSPENDED);
        } else {
            expect($failure)->toBeInstanceOf(OrganizationInvitationException::class)->and($failure->detail)->toBe($reason);
        }
    })->with([
        ['the invitation was revoked', 'not_open'], ['the invitation lost its slot', 'not_open'], ['the invitation expired', 'not_open'],
        ['the issuer was suspended', 'issuer_unauthorized'], ['the issuer lost a capability', 'issuer_unauthorized'],
        ['the new account no longer carries the invited address', 'recipient_address'], ['the new account was suspended', 'suspended'],
    ]);

    it('refuses to add a member from an invitation unless the acceptance orchestrator holds the locks, however the caller got a transaction', function () {
        [$invitation] = oinvRow($this->org, $this->operator, 'direct@example.test');
        $recipient = User::factory()->create(['email' => 'direct@example.test']);

        // This test is itself inside a transaction: an open transaction is exactly what is not proof.
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        expect(fn () => app(OrganizationMembership::class)->addFromInvitation($invitation->id, $recipient))->toThrow(LogicException::class, 'only from OrganizationInvitations::accept()');
        expect(fn () => DB::transaction(fn () => app(OrganizationMembership::class)->addFromInvitation($invitation->id, $recipient)))->toThrow(LogicException::class);
        expect(DB::table('organization_members')->where('user_id', $recipient->id)->count())->toBe(0);
    });

    it('refuses the same call made from inside acceptance but at another transaction level (the proof is bound to the level that took the locks)', function () {
        [, $token] = oinvRow($this->org, $this->operator, 'nested@example.test');
        $before = oinvFootprint();

        oinvAfterQuery('/^insert into `password_histories`/', function () {
            $user = User::query()->where('email', 'nested@example.test')->firstOrFail();
            DB::transaction(fn () => app(OrganizationMembership::class)->addFromInvitation(
                (int) DB::table('organization_invitations')->value('id'),
                $user,
            ));
        });

        expect(fn () => oinvAccept($token))->toThrow(LogicException::class);
        expect(oinvFootprint())->toBe($before);
    });

    it('lets the seam take no role and no approval, and never writes either', function () {
        $method = new ReflectionMethod(OrganizationMembership::class, 'addFromInvitation');

        expect(array_map(fn ($p) => $p->getName(), $method->getParameters()))->toBe(['invitationId', 'recipient'])
            ->and($method->getDocComment())->toContain('@internal');

        [, $token] = oinvRow($this->org, $this->operator, 'plain@example.test');
        $user = oinvAccept($token);
        $row = DB::table('organization_members')->where('user_id', $user->id)->first();
        expect($row->role)->toBe('member')->and($row->admin_approved_at)->toBeNull()->and($row->admin_approved_by)->toBeNull();
    });

    it('accepts through an identity provider on the same core, comparing the provider address by the same normalizer', function () {
        [$invitation, $token] = oinvRow($this->org, $this->operator, 'sso@example.test');
        $identity = ['name' => 'Sso Person', 'email' => ' SSO@Example.TEST ', 'provider_id' => 'google-123', 'token' => 'provider-token', 'refresh_token' => null, 'token_expires_at' => null];

        $user = oinv()->acceptByHash(OrganizationInvitations::hashToken($token), InvitationAcceptance::withProvider('google', $identity));

        expect($user->email)->toBe('sso@example.test')->and($user->password)->toBeNull()
            ->and(DB::table('social_accounts')->where('user_id', $user->id)->where('provider', 'google')->count())->toBe(1)
            ->and(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(0)
            ->and(DB::table('organization_members')->where('user_id', $user->id)->value('role'))->toBe('member')
            ->and(DB::table('organization_invitations')->find($invitation->id)->accepted_user_id)->toBe($user->id);
    });

    it('refuses a provider identity with another address or an identity already linked, writing nothing and keeping the invitation open', function () {
        [$invitation, $token] = oinvRow($this->org, $this->operator, 'sso2@example.test');
        $hash = OrganizationInvitations::hashToken($token);
        $before = oinvFootprint();

        $mismatch = ['name' => 'X', 'email' => 'other@example.test', 'provider_id' => 'g-1', 'token' => 't', 'refresh_token' => null, 'token_expires_at' => null];
        expect(oinvRefusal(fn () => oinv()->acceptByHash($hash, InvitationAcceptance::withProvider('google', $mismatch)))?->reason)->toBe(OrganizationInvitationException::EMAIL_MISMATCH);
        expect(oinvFootprint())->toBe($before);

        // The provider identity is already linked to another account: the violated key is read, and it is "unavailable".
        $other = User::factory()->create();
        DB::table('social_accounts')->insert(['user_id' => $other->id, 'provider' => 'google', 'provider_id' => 'g-taken', 'created_at' => now(), 'updated_at' => now()]);
        $before = oinvFootprint();
        $linked = ['name' => 'X', 'email' => 'sso2@example.test', 'provider_id' => 'g-taken', 'token' => 't', 'refresh_token' => null, 'token_expires_at' => null];
        $refusal = oinvRefusal(fn () => oinv()->acceptByHash($hash, InvitationAcceptance::withProvider('google', $linked)));
        expect($refusal?->reason)->toBe(OrganizationInvitationException::UNAVAILABLE)->and($refusal->detail)->toBe('identity_taken')
            ->and(oinvFootprint())->toBe($before)
            ->and(DB::table('organization_invitations')->find($invitation->id)->live_email)->toBe('sso2@example.test');
    });

    it('rethrows a duplicate on any key it does not expect instead of calling the link unavailable', function () {
        [, $token] = oinvRow($this->org, $this->operator, 'weird@example.test');
        $before = oinvFootprint();

        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `organization_members`')) {
                throw new QueryException('mysql', $query->sql, [], new PDOException("Duplicate entry '1-2' for key 'organization_members_organization_id_user_id_unique'"));
            }
        });

        // A hand-built QueryException carries no errorInfo, so it is an UNREADABLE duplicate: rethrown as it is.
        expect(fn () => oinvAccept($token))->toThrow(QueryException::class);
        expect(oinvFootprint())->toBe($before);
    });

    it('reads the violated key from the END of the driver message only, so a duplicated value cannot name a key for the server', function () {
        $make = function (string $message, string $state = '23000', int $code = 1062, bool $withMessage = true): QueryException {
            $pdo = new PDOException($message);
            $pdo->errorInfo = $withMessage ? [$state, $code, $message] : [$state, $code];

            return new QueryException('mysql', 'insert into `x` values (?)', [], $pdo);
        };

        expect(DuplicateKey::name($make("Duplicate entry 'a@x.test' for key 'users_email_unique'")))->toBe('users_email_unique')
            ->and(DuplicateKey::name($make("Duplicate entry 'a@x.test' for key 'users.users_email_unique'")))->toBe('users_email_unique')
            // The VALUE contains a fake key clause; the real key is the last quoted name at the end.
            ->and(DuplicateKey::name($make("Duplicate entry 'x\' for key \'users_email_unique' for key 'organization_invitations_org_live_email_unique'")))->toBe('organization_invitations_org_live_email_unique')
            ->and(DuplicateKey::name($make("Duplicate entry 'x for key users_email_unique' for key 'social_accounts_provider_provider_id_unique'")))->toBe('social_accounts_provider_provider_id_unique')
            // Anything after the key, a different error, or no driver message: unreadable, so the caller rethrows.
            ->and(DuplicateKey::name($make("Duplicate entry 'x' for key 'users_email_unique' (and more)")))->toBeNull()
            ->and(DuplicateKey::name($make("Duplicate entry 'x' for key 'users_email_unique'", '23000', 1451)))->toBeNull()
            ->and(DuplicateKey::name($make("Duplicate entry 'x' for key 'users_email_unique'", '42S02')))->toBeNull()
            ->and(DuplicateKey::name($make("Duplicate entry 'x' for key 'users_email_unique'", withMessage: false)))->toBeNull();
    });

    it('classifies a duplicate by the violated key name, on the real driver, for every key the design lists', function () {
        $org = $this->org;
        $issuer = $this->operator;
        $key = function (callable $insert): ?string {
            try {
                $insert();
            } catch (QueryException $e) {
                return DuplicateKey::name($e);
            }

            return 'no-error';
        };

        // users.email (case-insensitive collation)
        User::factory()->create(['email' => 'k1@example.test']);
        expect($key(fn () => DB::table('users')->insert(['name' => 'x', 'email' => 'K1@Example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()])))->toBe(OrganizationInvitations::KEY_USER_EMAIL);

        // The three unique keys of the new table.
        [$a] = oinvRow($org, $issuer, 'k2@example.test');
        $dupe = fn (array $override) => fn () => DB::table('organization_invitations')->insert($override + [
            'organization_id' => $org->id, 'email' => 'k3@example.test', 'token_hash' => hash('sha256', Str::random(8)), 'invited_by' => $issuer->id,
            'expires_at' => now()->addDay(), 'live_email' => 'k3@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        expect($key($dupe(['token_hash' => $a->token_hash])))->toBe(OrganizationInvitations::KEY_TOKEN_HASH);
        expect($key($dupe(['live_email' => 'k2@example.test', 'email' => 'k2@example.test'])))->toBe(OrganizationInvitations::KEY_SLOT);
        $user = User::factory()->create();
        DB::table('organization_invitations')->where('id', $a->id)->update(['accepted_user_id' => $user->id, 'live_email' => null]);
        expect($key($dupe(['accepted_user_id' => $user->id, 'live_email' => null])))->toBe('organization_invitations_accepted_user_id_unique');

        // The membership key and the provider-identity key.
        DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        expect($key(fn () => DB::table('organization_members')->insert(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()])))->toBe('organization_members_organization_id_user_id_unique');
        DB::table('social_accounts')->insert(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'p1', 'created_at' => now(), 'updated_at' => now()]);
        $other = User::factory()->create();
        expect($key(fn () => DB::table('social_accounts')->insert(['user_id' => $other->id, 'provider' => 'google', 'provider_id' => 'p1', 'created_at' => now(), 'updated_at' => now()])))->toBe(OrganizationInvitations::KEY_SOCIAL_IDENTITY);
    });
});

// ── The slot: database backstop (real MariaDB, bypassing the service) ─────────

describe('the uniqueness slot', function () {
    function oinvInsert(array $override): void
    {
        DB::table('organization_invitations')->insert($override + [
            'email' => 'slot@example.test', 'token_hash' => hash('sha256', Str::random(16)), 'expires_at' => now()->addDay(),
            'live_email' => 'slot@example.test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    it('(a) refuses two holders of one (organization, address) with a duplicate on that key', function () {
        oinvInsert(['organization_id' => $this->org->id, 'invited_by' => $this->operator->id]);

        try {
            oinvInsert(['organization_id' => $this->org->id, 'invited_by' => $this->operator->id]);
            $this->fail('the second holder was accepted');
        } catch (QueryException $e) {
            expect(DuplicateKey::name($e))->toBe(OrganizationInvitations::KEY_SLOT);
        }
    });

    it('(b) lets any number of released rows coexist, (c) the same address in two organizations, (d) a new holder once the slot is cleared', function () {
        $other = Organization::factory()->create(['owner_id' => $this->operator->id]);
        $base = ['organization_id' => $this->org->id, 'invited_by' => $this->operator->id];

        foreach (range(1, 4) as $_) {
            oinvInsert($base + ['live_email' => null]);
        }
        oinvInsert($base);
        oinvInsert(['organization_id' => $other->id, 'invited_by' => $this->operator->id]);
        expect(DB::table('organization_invitations')->where('email', 'slot@example.test')->count())->toBe(6);

        DB::table('organization_invitations')->where('organization_id', $this->org->id)->where('live_email', 'slot@example.test')->update(['live_email' => null]);
        oinvInsert($base);
        expect(DB::table('organization_invitations')->where('organization_id', $this->org->id)->where('live_email', 'slot@example.test')->count())->toBe(1);
    });

    it('(f) the service releases the slot before it inserts, so the key is only a backstop: a caller that skipped the release would be refused by it', function () {
        // Service path: expiry then re-issue succeeds (the release happened). The backstop is exercised directly by (a).
        Carbon::setTestNow('2026-11-11 10:00:00');
        oinv()->issue($this->org, 'slot@example.test', $this->operator);
        Carbon::setTestNow('2026-11-30 10:00:00');
        oinv()->issue($this->org, 'slot@example.test', $this->operator);

        expect(DB::table('organization_invitations')->where('live_email', 'slot@example.test')->count())->toBe(1)
            ->and(DB::table('organization_invitations')->where('email', 'slot@example.test')->count())->toBe(2);
    });
});

// ── The revocation hooks (issuer authority loss) ─────────────────────────────

describe('issuer authority loss', function () {
    /** A custom role carrying both capabilities, and issuers who hold it. @return array{0: Role, 1: list<User>} */
    function oinvRoleIssuers(int $count = 2): array
    {
        $role = Role::create(['name' => 'directory-'.Str::random(6), 'guard_name' => 'web']);
        $role->givePermissionTo(['portal.operator', 'crm.manage']);
        $users = collect(range(1, $count))->map(fn () => tap(User::factory()->create(), fn ($u) => $u->assignRole($role)))->all();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$role, $users];
    }

    function oinvAdmin(): User
    {
        // An account administrator, so the last-administrator guard has someone to protect and the actor of a change.
        $admin = User::factory()->create();
        $admin->givePermissionTo(['users.view', 'users.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    it('revokes the suspended issuer\'s open invitations, permanently, leaving others, accepted and expired rows alone', function () {
        $admin = oinvAdmin();
        $issuer = oinvOperator();
        [$open1] = oinvRow($this->org, $issuer, 'o1@example.test');
        [$open2] = oinvRow($this->org, $issuer, 'o2@example.test');
        [$other] = oinvRow($this->org, $this->operator, 'other@example.test');
        [$accepted] = oinvRow($this->org, $issuer, 'acc@example.test', ['accepted_at' => now(), 'accepted_user_id' => oinvCustomer()->id, 'live_email' => null]);
        [$expired] = oinvRow($this->org, $issuer, 'exp@example.test', ['expires_at' => now()->subMinute()]);
        $acceptedBefore = (array) DB::table('organization_invitations')->find($accepted->id);
        $expiredBefore = (array) DB::table('organization_invitations')->find($expired->id);

        app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test');

        foreach ([$open1, $open2] as $row) {
            $now = DB::table('organization_invitations')->find($row->id);
            // O4/R1: a hook never writes the uniqueness slot. The revoked row keeps it, like an expired one, until the next issuance releases it.
            expect($now->revoked_reason)->toBe('issuer_suspended')->and((int) $now->revoked_by)->toBe($admin->id)->and($now->live_email)->toBe($row->email);
        }
        expect(DB::table('organization_invitations')->find($other->id)->revoked_at)->toBeNull()
            ->and((array) DB::table('organization_invitations')->find($accepted->id))->toBe($acceptedBefore)
            ->and((array) DB::table('organization_invitations')->find($expired->id))->toBe($expiredBefore);

        $entries = oinvActivity(OrganizationInvitations::REVOKED);
        expect($entries->pluck('properties.invitation_id')->sort()->values()->all())->toBe([$open1->id, $open2->id])
            ->and($entries->pluck('properties.source')->unique()->all())->toBe([OrganizationInvitations::SOURCE_SUSPENSION])
            ->and($entries->pluck('properties.reason')->unique()->all())->toBe(['issuer_suspended'])
            ->and($entries->pluck('causer_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([$admin->id]);

        // Reactivation does not bring them back.
        $revokedAt = DB::table('organization_invitations')->find($open1->id)->revoked_at;
        app(AccountLifecycleService::class)->reactivate($issuer->fresh(), $admin);
        expect(DB::table('organization_invitations')->find($open1->id)->revoked_at)->toBe($revokedAt)
            ->and(DB::table('organization_invitations')->find($open1->id)->revoked_reason)->toBe('issuer_suspended');
    });

    it('revokes with issuer_reinstated an invitation still open when the account is reactivated (one that raced the suspension)', function () {
        $admin = oinvAdmin();
        $issuer = oinvOperator(['disabled_at' => now()]);
        [$raced] = oinvRow($this->org, $issuer, 'raced@example.test');

        app(AccountLifecycleService::class)->reactivate($issuer, $admin);

        $row = DB::table('organization_invitations')->find($raced->id);
        expect($row->revoked_reason)->toBe('issuer_reinstated')->and((int) $row->revoked_by)->toBe($admin->id)
            ->and(oinvActivity(OrganizationInvitations::REVOKED)->sole()->properties['source'])->toBe(OrganizationInvitations::SOURCE_REACTIVATION);
    });

    it('revokes when an application role change removes the capability, and a restoration does not revive it', function () {
        $admin = oinvAdmin();
        [$role, [$issuer]] = oinvRoleIssuers(1);
        [$invitation, $token] = oinvRow($this->org, $issuer, 'role@example.test');

        app(AccessGrants::class)->syncUserRoles($issuer, [], $admin);

        $row = DB::table('organization_invitations')->find($invitation->id);
        expect($row->revoked_reason)->toBe('issuer_authority_lost')->and((int) $row->revoked_by)->toBe($admin->id);

        app(AccessGrants::class)->syncUserRoles($issuer, [$role->name], $admin);
        expect(DB::table('organization_invitations')->find($invitation->id)->revoked_at)->toBe($row->revoked_at)
            ->and(oinvRefusal(fn () => oinvAccept($token))?->detail)->toBe('revoked');
    });

    it('revokes nothing when a role change leaves both capabilities where they were', function () {
        $admin = oinvAdmin();
        $issuer = oinvOperator(); // direct permissions, independent of any role
        [$invitation] = oinvRow($this->org, $issuer, 'steady@example.test');
        $extra = Role::create(['name' => 'extra-'.Str::random(5), 'guard_name' => 'web']);

        app(AccessGrants::class)->syncUserRoles($issuer, [$extra->name], $admin);
        app(AccessGrants::class)->syncUserRoles($issuer, [], $admin);

        expect(DB::table('organization_invitations')->find($invitation->id)->revoked_at)->toBeNull();
    });

    it('sweeps every issuer of a role when its permissions change, in one statement over ascending keys, and spares an issuer who holds the capabilities another way', function () {
        $admin = oinvAdmin();
        [$role, [$a, $b]] = oinvRoleIssuers(2);
        $direct = oinvOperator();
        $rows = [
            oinvRow($this->org, $b, 'b1@example.test')[0],
            oinvRow($this->org, $a, 'a1@example.test')[0],
            oinvRow($this->org, $a, 'a2@example.test')[0],
            oinvRow($this->org, $direct, 'd1@example.test')[0],
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccessGrants::class)->syncRolePermissions($role, ['tickets.view'], $admin);
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update `organization_invitations`'))->values();
        DB::disableQueryLog();

        expect($updates)->toHaveCount(1);
        // The integers bound, in order: first the actor (revoked_by), then the ascending primary keys.
        $integers = collect($updates[0]['bindings'])->filter(fn ($b) => is_int($b))->values()->all();
        $ids = array_slice($integers, 1);
        expect($integers[0])->toBe($admin->id)->and($ids)->toBe(collect($ids)->sort()->values()->all())->toHaveCount(3);

        expect(DB::table('organization_invitations')->whereIn('id', [$rows[0]->id, $rows[1]->id, $rows[2]->id])->pluck('revoked_reason')->unique()->all())->toBe(['issuer_authority_lost'])
            ->and(DB::table('organization_invitations')->find($rows[3]->id)->revoked_at)->toBeNull()
            ->and(oinvActivity(OrganizationInvitations::REVOKED))->toHaveCount(3)
            ->and(oinvActivity(OrganizationInvitations::REVOKED)->pluck('properties.source')->unique()->all())->toBe([OrganizationInvitations::SOURCE_ROLE_PERMISSIONS]);
    });

    it('revokes an invitation that raced the loss when authority is restored (issuer_reinstated), and a restoration revives nothing already revoked', function () {
        $admin = oinvAdmin();
        [$role, [$issuer]] = oinvRoleIssuers(1);
        [$first] = oinvRow($this->org, $issuer, 'first@example.test');
        app(AccessGrants::class)->syncRolePermissions($role, [], $admin);
        expect(DB::table('organization_invitations')->find($first->id)->revoked_reason)->toBe('issuer_authority_lost');

        // An invitation committed by the issuer in a race with the loss (written directly: the service would refuse an unauthorized issuer).
        [$raced] = oinvRow($this->org, $issuer, 'raced@example.test');
        app(AccessGrants::class)->syncRolePermissions($role, ['portal.operator', 'crm.manage'], $admin);

        expect(DB::table('organization_invitations')->find($raced->id)->revoked_reason)->toBe('issuer_reinstated')
            ->and(DB::table('organization_invitations')->find($first->id)->revoked_reason)->toBe('issuer_authority_lost');
    });

    it('revokes with no actor when the built-in synchronization removes the capability (roles:sync)', function () {
        $stray = User::factory()->create();
        $stray->assignRole('user');
        // A stray grant on the built-in `user` role, which the definitions do not give it: the synchronization will revoke it.
        Role::findByName('user', 'web')->givePermissionTo(['portal.operator', 'crm.manage']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [$invitation] = oinvRow($this->org, $stray, 'sync@example.test');

        $sync = app(BuiltInRoleSynchronizer::class);
        $plan = $sync->plan();
        expect($plan->hasRevocations())->toBeTrue();
        $sync->apply($plan->fingerprint(), true, false);

        $row = DB::table('organization_invitations')->find($invitation->id);
        expect($row->revoked_reason)->toBe('issuer_authority_lost')->and($row->revoked_by)->toBeNull()
            ->and(oinvActivity(OrganizationInvitations::REVOKED)->sole()->properties['source'])->toBe(OrganizationInvitations::SOURCE_ROLES_SYNC)
            ->and(oinvActivity(OrganizationInvitations::REVOKED)->sole()->causer_id)->toBeNull();
    });

    it('never relabels an expired invitation as revoked, including when it expires between the selection and the update', function () {
        $admin = oinvAdmin();
        $issuer = oinvOperator();
        Carbon::setTestNow('2026-11-11 10:00:00');
        [$soon] = oinvRow($this->org, $issuer, 'soon@example.test', ['expires_at' => '2026-11-11 10:00:30']);
        [$already] = oinvRow($this->org, $issuer, 'already@example.test', ['expires_at' => '2026-11-11 09:00:00']);
        [$later] = oinvRow($this->org, $issuer, 'later@example.test', ['expires_at' => '2026-11-12 10:00:00']);

        // Time passes right after the candidate rows were SELECTED and before they are locked and written.
        $moved = false;
        DB::listen(function ($query) use (&$moved) {
            if (! $moved && str_contains($query->sql, 'from `organization_invitations` where `invited_by` in')) {
                $moved = true;
                Carbon::setTestNow('2026-11-11 10:01:00');
            }
        });

        app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test');

        expect($moved)->toBeTrue();
        foreach ([$soon, $already] as $row) {
            $now = DB::table('organization_invitations')->find($row->id);
            expect($now->revoked_at)->toBeNull()->and($now->revoked_reason)->toBeNull()->and($now->live_email)->not->toBeNull();
        }
        expect(DB::table('organization_invitations')->find($later->id)->revoked_reason)->toBe('issuer_suspended')
            ->and(oinvActivity(OrganizationInvitations::REVOKED)->pluck('properties.invitation_id')->all())->toBe([$later->id]);
    });

    it('still refuses acceptance and drops the mail when authority was lost by a path the hooks do not see', function () {
        $issuer = oinvOperator();
        $invitation = oinv()->issue($this->org, 'blind@example.test', $issuer);
        $token = $this->sent[0]['token'];
        $this->sent = [];

        DB::table('model_has_permissions')->where('model_id', $issuer->id)->delete(); // a direct database write, no hook runs
        expect(DB::table('organization_invitations')->find($invitation->id)->revoked_at)->toBeNull()
            ->and(oinvRefusal(fn () => oinvAccept($token))?->detail)->toBe('issuer_unauthorized')
            ->and(oinv()->findOpen($token))->toBeNull();

        $notification = new OrganizationInvitationNotification($invitation->id, $token);
        expect($notification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
    });

    it('never writes live_email or organization_id from any hook, and the revoked row keeps its slot until an authorized issuance releases it', function (string $path) {
        $admin = oinvAdmin();
        [$role, [$issuer]] = oinvRoleIssuers(1);

        if ($path === 'built-in synchronization') {
            // An issuer whose only authority is a stray grant on the built-in `user` role, which the synchronization will revoke.
            $issuer = User::factory()->create();
            $issuer->assignRole('user');
            Role::findByName('user', 'web')->givePermissionTo(['portal.operator', 'crm.manage']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        [$invitation] = oinvRow($this->org, $issuer, 'kept@example.test');

        DB::flushQueryLog();
        DB::enableQueryLog();
        match ($path) {
            'suspension' => app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test'),
            'user roles' => app(AccessGrants::class)->syncUserRoles($issuer, [], $admin),
            'role permissions' => app(AccessGrants::class)->syncRolePermissions($role, [], $admin),
            'built-in synchronization' => app(BuiltInRoleSynchronizer::class)->apply(app(BuiltInRoleSynchronizer::class)->plan()->fingerprint(), true, false),
        };
        $statements = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_starts_with($q, 'update `organization_invitations`'));
        DB::disableQueryLog();

        $row = DB::table('organization_invitations')->find($invitation->id);
        expect($row->revoked_at)->not->toBeNull()
            ->and($statements)->not->toBeEmpty()
            ->and($statements->filter(fn ($q) => str_contains($q, '`live_email`') || str_contains($q, '`organization_id`')))->toBeEmpty()
            ->and($row->live_email)->toBe('kept@example.test')
            ->and((int) $row->organization_id)->toBe($this->org->id);

        // The slot is still held by a terminal row; the next authorized issuance for the address releases it and takes it.
        $new = oinv()->issue($this->org, 'kept@example.test', $this->operator);
        expect(DB::table('organization_invitations')->find($invitation->id)->live_email)->toBeNull()
            ->and(DB::table('organization_invitations')->find($invitation->id)->revoked_at)->toBe($row->revoked_at)
            ->and(DB::table('organization_invitations')->where('organization_id', $this->org->id)->where('live_email', 'kept@example.test')->pluck('id')->all())->toBe([$new->id]);
    })->with(['suspension', 'user roles', 'role permissions', 'built-in synchronization']);

    it('locks the actor, then the invitations by ascending primary key, BEFORE the mutation touches a role or permission table (R2, R3)', function (string $path) {
        $admin = oinvAdmin();
        [$role, [$a, $b]] = oinvRoleIssuers(2);
        $rows = [
            oinvRow($this->org, $b, 'b1@example.test')[0],
            oinvRow($this->org, $a, 'a1@example.test')[0],
            oinvRow($this->org, $b, 'b2@example.test')[0],
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();
        match ($path) {
            'user roles' => app(AccessGrants::class)->syncUserRoles($a, [], $admin),
            'role permissions' => app(AccessGrants::class)->syncRolePermissions($role, [], $admin),
        };
        $entries = collect(DB::getQueryLog())->values();
        $log = $entries->pluck('query')->map(fn ($q) => preg_replace('/\s+/', ' ', $q))->values();
        DB::disableQueryLog();

        $first = fn (string $pattern) => $log->search(fn ($q) => preg_match($pattern, $q) === 1);
        $actor = $first('/from `users` where `id` = \? limit 1 lock in share mode/');
        $invitations = $first('/from `organization_invitations` where `id` in .* order by `id` asc for update/');
        $mutation = $first('/^(delete from|insert into) `(model_has_roles|role_has_permissions)`/');

        expect($actor)->not->toBeFalse()->and($invitations)->not->toBeFalse()->and($mutation)->not->toBeFalse()
            ->and($actor)->toBeLessThan($invitations)->and($invitations)->toBeLessThan($mutation);

        // R4: a mutation that reads the permission mapping replaces the cache epoch AFTER the actor and BEFORE the invitation locks; a user's
        // role assignment never touches the permission cache at all.
        $cache = $log->filter(fn ($q) => str_contains($q, '`cache`'));
        if ($path === 'role permissions') {
            $epoch = $first('/^insert into `cache`/');
            expect($epoch)->not->toBeFalse()->and($actor)->toBeLessThan($epoch)->and($epoch)->toBeLessThan($invitations);
        } else {
            expect($cache)->toBeEmpty();
        }

        // The ids locked: the open invitations of the candidates (the one issuer for a user's roles, every issuer for a role), ascending.
        $locked = collect($entries[$invitations]['bindings'])->map(fn ($id) => (int) $id)->all();
        $expected = $path === 'user roles' ? [$rows[1]->id] : collect($rows)->pluck('id')->sort()->values()->all();
        expect($locked)->toBe($expected);
    })->with(['user roles', 'role permissions']);

    it('share-locks the actor before it locks the invitations when it suspends or reactivates an account (R3)', function (string $path) {
        $admin = oinvAdmin();
        $issuer = oinvOperator($path === 'reactivation' ? ['disabled_at' => now()] : []);
        oinvRow($this->org, $issuer, 'order@example.test');

        DB::flushQueryLog();
        DB::enableQueryLog();
        match ($path) {
            'suspension' => app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test'),
            'reactivation' => app(AccountLifecycleService::class)->reactivate($issuer, $admin),
        };
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => preg_replace('/\\s+/', ' ', $q))->values();
        DB::disableQueryLog();

        $actor = $log->search(fn ($q) => preg_match('/from `users` where `id` = \\? limit 1 lock in share mode/', $q) === 1);
        $invitations = $log->search(fn ($q) => preg_match('/from `organization_invitations` where `id` in .* order by `id` asc for update/', $q) === 1);
        $update = $log->search(fn ($q) => str_starts_with($q, 'update `organization_invitations`'));

        expect($actor)->not->toBeFalse()->and($invitations)->not->toBeFalse()->and($update)->not->toBeFalse()
            ->and($actor)->toBeLessThan($invitations)->and($invitations)->toBeLessThan($update);
    })->with(['suspension', 'reactivation']);

    it('refuses an unknown reason code and any call outside the transaction of the change it accompanies', function () {
        expect(fn () => oinv()->revokeIssuedBy([$this->operator->id => 'because'], 'test'))->toThrow(LogicException::class, 'Unknown invitation revocation reason');
    });
});

// ── The notification ─────────────────────────────────────────────────────────

describe('the notification', function () {
    it('mails only while the invitation is the live one, read fresh at send time', function (string $case) {
        $invitation = oinv()->issue($this->org, 'live@example.test', $this->operator);
        $token = $this->sent[0]['token'];
        $notification = new OrganizationInvitationNotification($invitation->id, $token);
        $route = new AnonymousNotifiable;

        expect($notification->shouldSend($route, 'mail'))->toBeTrue();

        match ($case) {
            'accepted' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'accepted_user_id' => oinvCustomer()->id, 'live_email' => null]),
            'revoked' => oinv()->revoke($this->org, $invitation->id, $this->operator),
            'expired' => Carbon::setTestNow(now()->addDays(8)),
            'superseded' => oinv()->issue($this->org, 'live@example.test', $this->operator),
            'an account took the address' => User::factory()->create(['email' => 'live@example.test']),
            'issuer suspended' => DB::table('users')->where('id', $this->operator->id)->update(['disabled_at' => now()]),
            'issuer lost authority' => DB::table('model_has_permissions')->where('model_id', $this->operator->id)->delete(),
            'deleted' => DB::table('organization_invitations')->where('id', $invitation->id)->delete(),
        };

        expect($notification->shouldSend($route, 'mail'))->toBeFalse();
    })->with(['accepted', 'revoked', 'expired', 'superseded', 'an account took the address', 'issuer suspended', 'issuer lost authority', 'deleted']);

    it('refuses a token that does not match the stored hash even when the invitation is open', function () {
        $invitation = oinv()->issue($this->org, 'hash@example.test', $this->operator);

        expect((new OrganizationInvitationNotification($invitation->id, Str::random(64)))->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
    });

    it('does not swallow a database error: it propagates so the queue retries', function () {
        $invitation = oinv()->issue($this->org, 'err@example.test', $this->operator);
        $notification = new OrganizationInvitationNotification($invitation->id, $this->sent[0]['token']);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'organization_invitations')) {
                throw new RuntimeException('connection lost');
            }
        });

        expect(fn () => $notification->shouldSend(new AnonymousNotifiable, 'mail'))->toThrow(RuntimeException::class, 'connection lost');
    });

    it('names the organization and the inviter with user-authored text literal, links the invitation page and states the lifetime from the constant', function () {
        $org = Organization::factory()->create(['name' => 'Evil *Corp* <b>x</b>', 'owner_id' => $this->operator->id]);
        $inviter = oinvOperator(['name' => '[Mallory](http://evil.test)']);
        $invitation = oinv()->issue($org, 'mailbody@example.test', $inviter);
        $token = $this->sent[0]['token'];

        $messages = [];
        Event::listen(MessageSent::class, function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        Notification::route('mail', 'mailbody@example.test')->notifyNow(new OrganizationInvitationNotification($invitation->id, $token));

        expect($messages)->toHaveCount(1);
        $html = (string) $messages[0]->getHtmlBody();
        $text = (string) $messages[0]->getTextBody();
        $visible = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
        $link = route('invitation.show', ['token' => $token]);

        // What was typed is shown exactly as typed, and none of it became markup.
        expect($visible)->toContain('[Mallory](http://evil.test)')->toContain('Evil *Corp* <b>x</b>')
            ->and($html)->not->toContain('<b>x</b>')->not->toContain('<em>Corp</em>')->not->toContain('href="http://evil.test"')
            ->and($text)->toContain('[Mallory](http://evil.test)')->toContain('Evil *Corp* <b>x</b>')
            ->and($html)->toContain('href="'.$link.'"')->and($text)->toContain($link)
            ->and($visible)->toContain('expires in '.OrganizationInvitations::LIFETIME_DAYS.' days');
    });

    it('is encrypted, after-commit and queued with the retry contract; the queue connection default is not what guarantees after-commit', function () {
        $notification = new OrganizationInvitationNotification(1, str_repeat('a', 64));

        expect($notification)->toBeInstanceOf(ShouldBeEncrypted::class)->toBeInstanceOf(ShouldQueueAfterCommit::class)
            ->and(config('queue.connections.database.after_commit'))->toBeFalse();
    });

    it('enqueues nothing when the surrounding transaction rolls back, and exactly one encrypted job when it commits (real database queue)', function () {
        config(['queue.default' => 'database']);
        $before = DB::table('jobs')->count();

        try {
            DB::transaction(function () {
                oinv()->issue($this->org, 'queue@example.test', $this->operator);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }
        expect(DB::table('jobs')->count())->toBe($before)->and($this->sent)->toBe([]);

        DB::transaction(fn () => oinv()->issue($this->org, 'queue2@example.test', $this->operator));
        $jobs = DB::table('jobs')->get();
        expect($jobs)->toHaveCount($before + 1);

        // The token never appears in the stored payload; it is only recoverable by decrypting with the application key.
        $stored = DB::table('organization_invitations')->where('email', 'queue2@example.test')->first();
        $payload = json_decode($jobs->last()->payload, true);
        expect($payload['data']['command'])->not->toContain('OrganizationInvitationNotification')
            ->and($jobs->last()->payload)->not->toContain($stored->token_hash);
        $command = unserialize(Crypt::decrypt($payload['data']['command']));
        $notification = $command->notification;
        $token = (new ReflectionObject($notification))->getProperty('token')->getValue($notification);
        expect(hash('sha256', $token))->toBe($stored->token_hash)->and($jobs->last()->payload)->not->toContain($token);
    });
});

describe('key rotation', function () {
    it('keeps an in-flight encrypted invitation job readable when the retired key stays in APP_PREVIOUS_KEYS, and loses it when the key is dropped', function () {
        $cipher = config('app.cipher');
        $retired = Encrypter::generateKey($cipher);
        $current = Encrypter::generateKey($cipher);

        // What a worker would have stored under the old key.
        $payload = (new Encrypter($retired, $cipher))->encrypt(serialize(new OrganizationInvitationNotification(7, str_repeat('t', 64))));

        $kept = (new Encrypter($current, $cipher))->previousKeys([$retired]);
        $dropped = new Encrypter($current, $cipher);

        expect(unserialize($kept->decrypt($payload)))->toBeInstanceOf(OrganizationInvitationNotification::class);
        expect(fn () => $dropped->decrypt($payload))->toThrow(DecryptException::class);
        // The application is wired to honour the setting: config/app.php reads APP_PREVIOUS_KEYS into previous_keys.
        expect(array_key_exists('previous_keys', config('app')))->toBeTrue();
    });
});

// ── Token confidentiality ────────────────────────────────────────────────────

describe('token confidentiality', function () {
    it('never puts the plaintext token or its hash into the application tables\' wrong places, the activity log or the application log', function () {
        $invitation = oinv()->issue($this->org, 'secret@example.test', $this->operator);
        $token = $this->sent[0]['token'];
        $hash = OrganizationInvitations::hashToken($token);

        oinv()->findOpen($token);
        oinvAccept($token);
        oinvRefusal(fn () => oinvAccept($token));
        oinv()->issue($this->org, 'secret2@example.test', $this->operator);
        oinv()->revoke($this->org, DB::table('organization_invitations')->where('email', 'secret2@example.test')->value('id'), $this->operator);

        // The table holds the hash and nothing resembling the plaintext.
        expect(json_encode(DB::table('organization_invitations')->get()))->not->toContain($token);
        // The hash is only in its own column.
        $columns = collect((array) DB::table('organization_invitations')->find($invitation->id))->filter(fn ($v) => $v === $hash)->keys()->all();
        expect($columns)->toBe(['token_hash']);
        // No audit entry carries either, and no log line does.
        expect(json_encode(DB::table('activity_log')->get()))->not->toContain($token)->not->toContain($hash);
        expect(implode("\n", $this->logged))->not->toContain($token)->not->toContain($hash);
        // The model never serializes the hash.
        expect(OrganizationInvitation::find($invitation->id)->toJson())->not->toContain($hash);
    });
});

// ── Source-level fences ──────────────────────────────────────────────────────

describe('single-writer fences', function () {
    function oinvPhpFiles(): Collection
    {
        return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
            ->mapWithKeys(fn (SplFileInfo $file) => [str_replace(app_path().'/', '', $file->getPathname()) => file_get_contents($file->getPathname())]);
    }

    it('lets only the invitation service write organization_invitations', function () {
        $write = '/table\(\s*[\'"]organization_invitations[^\'"]*[\'"]\s*\)[^;]*->\s*(insert|insertGetId|insertOrIgnore|update|delete|upsert|truncate)\(|OrganizationInvitation::\s*(forceCreate|create|insert|update|delete|upsert|truncate|firstOrCreate|updateOrCreate|query\(\)\s*->\s*(forceCreate|create|insert|update|delete|upsert|truncate))\(|(insert\s+into|update|delete\s+from)\s+`?organization_invitations/is';

        $writers = oinvPhpFiles()->filter(fn ($source) => preg_match($write, $source) === 1)->keys()->sort()->values()->all();

        // Positive control: the pattern does see the one legitimate writer. The one NAMED exception is the browser suite's own cleanup
        // (R1-3C, `e2e:cleanup-invitees`): it deletes only, only in local and testing, only the rows of its reserved address in the suite's
        // own organizations (the next test pins "delete only"; CleanupE2eInviteesTest pins the rest).
        expect($writers)->toBe(['Console/Commands/CleanupE2eInvitees.php', 'Services/OrganizationInvitations.php']);
    });

    it('lets the one named exception, the E2E invitee cleanup, only DELETE: it never inserts, updates or upserts organization_invitations or organization_members', function () {
        $source = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', oinvPhpFiles()['Console/Commands/CleanupE2eInvitees.php']);

        expect($source)->not->toMatch('/(organization_invitations|organization_members)[^;]*->\s*(insert|insertGetId|insertOrIgnore|update|upsert|truncate|increment|decrement)\(/is')
            ->and($source)->not->toMatch('/(insert\s+into|update|truncate)\s+`?(organization_invitations|organization_members)/i')
            ->and($source)->not->toContain('OrganizationInvitation::')->and($source)->not->toContain('OrganizationMembership');
    });

    it('lets only the invitation service call addFromInvitation', function () {
        $callers = oinvPhpFiles()->filter(fn ($source) => str_contains($source, 'addFromInvitation('))->keys()->sort()->values()->all();

        expect($callers)->toBe(['Services/OrganizationInvitations.php', 'Services/OrganizationMembership.php']);
        expect(substr_count(oinvPhpFiles()['Services/OrganizationInvitations.php'], '->addFromInvitation('))->toBe(1);
    });

    it('requires every public mutating method of OrganizationMembership to authorize first, except the invitation-derived one by name', function () {
        $class = new ReflectionClass(OrganizationMembership::class);
        $lines = file($class->getFileName());
        $checked = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $firstStatement = trim(Str::after($body, '{'));

            if ($method->getName() === 'addFromInvitation') {
                expect($firstStatement)->not->toStartWith('$this->authorize(');

                continue;
            }

            expect($firstStatement)->toStartWith('$this->authorize(', "{$method->getName()} must authorize first");
            $checked[] = $method->getName();
        }

        expect($checked)->toBe(['add', 'promote', 'demote', 'remove', 'approve', 'revokeApproval']);
    });

    it('lets no production code outside the guarded paths change roles or permissions, so every authority change reaches the revocation hooks', function () {
        $call = '/->\s*(syncRoles|assignRole|removeRole|syncPermissions|givePermissionTo|revokePermissionTo)\(/';
        $callers = oinvPhpFiles()->filter(fn ($source) => preg_match($call, $source) === 1)->keys()->sort()->values()->all();

        expect($callers)->toBe([
            'Http/Controllers/Admin/RoleController.php',  // store(): a NEW role has no holders, so it changes nobody's authority
            'Services/AccessGrants.php',                  // the guarded, hooked paths (the methods are pinned below)
            'Services/OperatorBootstrap.php',             // grants a NEW account its role
            'Services/OrganizationInvitations.php',       // a NEW account's built-in `user` role (the method is pinned below)
        ]);

        // And inside the two files that matter, only the METHODS that are meant to: a new method in AccessGrants that changed a role would
        // otherwise pass the file-level list above without reaching the hooks.
        $methodsCalling = function (string $class) use ($call): array {
            $reflection = new ReflectionClass($class);
            $lines = file($reflection->getFileName());

            return collect($reflection->getMethods())
                ->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $class)
                ->filter(fn (ReflectionMethod $m) => preg_match($call, implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1))) === 1)
                ->map(fn (ReflectionMethod $m) => $m->getName())->sort()->values()->all();
        };

        // deleteUnassignedRole() detaches nothing: it refuses a role that has holders, and a role with none changes nobody's authority.
        expect($methodsCalling(AccessGrants::class))->toBe(['syncRolePermissions', 'syncUserRoles'])
            ->and($methodsCalling(OrganizationInvitations::class))->toBe(['acceptLocked']);
    });

    it('never clears revoked_at anywhere in production code: a revoked invitation stays revoked', function () {
        $clears = oinvPhpFiles()->filter(fn ($source) => preg_match('/[\'"]revoked_at[\'"]\s*=>\s*null|revoked_at\s*=\s*null|->revoked_at\s*=\s*null/i', $source) === 1)->keys()->all();

        expect($clears)->toBe([]);
    });
});
