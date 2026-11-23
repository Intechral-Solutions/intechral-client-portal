<?php

/*
 * Release 1.0 (ORG-1, ruling A2): the portal lifecycle transitions and what disabling does to outstanding invitations (ORG-0 record, sections 5.2 and 6.2).
 *
 *   - Disabling permanently REVOKES every open invitation of the Organization, in the same transaction, with the reason `organization_disabled`, the actor
 *     and one audit entry per invitation; accepted, earlier-revoked and expired invitations keep their history untouched.
 *   - A mail that was queued before is dropped at send time, the link is a dead link, and re-enabling revives nothing: a new invitation is a new credential.
 *   - The uniqueness slot decision (ORG-1 clears `live_email` on a disable revocation, as an operator's revocation does): issuing for the same address after a
 *     re-enable works from BOTH histories, the slot cleared by the disable and the slot left behind by an authority-loss hook, and the unique key is never hit.
 *   - Invalid transitions are controlled refusals, never a database CHECK error; and nothing in the locked part of the transaction asks the Gate or loads the
 *     permission cache.
 *
 * The races (the same operations against real second connections) are in OrganizationPortalStateConcurrencyTest.
 */

use App\Exceptions\OrganizationInvitationException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationAuthority;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationRelationships;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Auth/InvitationTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
    $this->org = Organization::factory()->create(['name' => 'Lifecycle Org', 'portal_enabled_by' => $this->operator->id]);
    $this->relationships = app(OrganizationRelationships::class);
});

function dlcOpen(Organization $org, string $email, ?User $issuer = null, array $state = []): array
{
    return regInvOpen($email, $org, $issuer ?? regInvIssuer(), $state);
}

function dlcRow(int $id): object
{
    return DB::table('organization_invitations')->where('id', $id)->first();
}

// ── Disable revokes the open invitations, permanently ────────────────────────

it('revokes every open invitation when the organization is disabled, with reason, actor, audit and history kept', function () {
    $issuer = regInvIssuer();
    [$open1] = dlcOpen($this->org, 'one@example.test', $issuer);
    [$open2] = dlcOpen($this->org, 'two@example.test', $issuer);
    [$accepted] = dlcOpen($this->org, 'accepted@example.test', $issuer, ['accepted_at' => now()->subDay(), 'accepted_user_id' => $issuer->id, 'live_email' => null]);
    [$revoked] = dlcOpen($this->org, 'revoked@example.test', $issuer, ['revoked_at' => now()->subDay(), 'revoked_by' => $issuer->id, 'revoked_reason' => 'operator', 'live_email' => null]);
    [$expired] = dlcOpen($this->org, 'expired@example.test', $issuer, ['expires_at' => now()->subDay()]);
    [$elsewhere] = dlcOpen(Organization::factory()->create(), 'other-org@example.test', $issuer);
    $untouched = collect([$accepted, $revoked, $expired, $elsewhere])->mapWithKeys(fn ($i) => [$i->id => (array) dlcRow($i->id)])->all();
    $activityBefore = DB::table('activity_log')->count();

    expect($this->relationships->disable($this->org, $this->operator))->toBeTrue();

    foreach ([$open1, $open2] as $invitation) {
        $row = dlcRow($invitation->id);
        expect($row->revoked_at)->not->toBeNull()
            ->and($row->revoked_reason)->toBe(OrganizationInvitations::REASON_ORGANIZATION_DISABLED)
            ->and((int) $row->revoked_by)->toBe($this->operator->id)
            ->and($row->live_email)->toBeNull()       // the slot decision: cleared, like an operator revocation
            ->and($row->accepted_at)->toBeNull()
            ->and($row->email)->toBe($invitation->email)
            ->and((int) $row->organization_id)->toBe($this->org->id);
    }

    // Terminal and untouched: accepted, previously revoked, expired (never relabelled revoked), and another organization's open invitation.
    foreach ($untouched as $id => $before) {
        expect((array) dlcRow($id))->toBe($before);
    }

    $log = DB::table('activity_log')->where('id', '>', 0)->orderBy('id')->get()->slice($activityBefore);
    $revocations = $log->where('description', OrganizationInvitations::REVOKED)->map(fn ($entry) => json_decode($entry->properties, true));
    $disable = $log->firstWhere('description', OrganizationRelationships::DISABLED);

    expect($revocations)->toHaveCount(2)
        ->and($revocations->pluck('reason')->unique()->all())->toBe([OrganizationInvitations::REASON_ORGANIZATION_DISABLED])
        ->and($revocations->pluck('source')->unique()->all())->toBe([OrganizationInvitations::SOURCE_ORGANIZATION_DISABLED])
        ->and($revocations->pluck('invitation_id')->sort()->values()->all())->toBe([$open1->id, $open2->id])
        ->and(json_decode($disable->properties, true)['revoked_invitation_ids'])->toBe([$open1->id, $open2->id])
        ->and((int) $disable->causer_id)->toBe($this->operator->id)
        // Never the token, its hash or an unrelated address.
        ->and(json_encode($log->pluck('properties')))->not->toContain('token')->not->toContain('other-org@example.test');
});

it('keeps the link dead, drops its queued mail, and revives nothing on re-enable', function () {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
        $sent[] = $event->message;
    });
    $issuer = regInvIssuer();

    $invitation = app(OrganizationInvitations::class)->issue($this->org, 'queued@example.test', $issuer);
    expect(DB::table('jobs')->count())->toBe(1);

    // Disable BEFORE the worker runs: the mail was queued, the invitation is revoked, and the worker drops it.
    $this->relationships->disable($this->org, $this->operator);
    while (DB::table('jobs')->count() > 0) {
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
    }

    expect($sent)->toBe([])->and(DB::table('failed_jobs')->count())->toBe(0);

    // A token the test knows, for the dead-link checks.
    [$known, $token] = dlcOpen($this->org, 'known@example.test', $issuer);
    DB::table('organization_invitations')->where('id', $known->id)->update(['revoked_at' => now(), 'revoked_by' => $this->operator->id, 'revoked_reason' => OrganizationInvitations::REASON_ORGANIZATION_DISABLED, 'live_email' => null]);

    expect(app(OrganizationInvitations::class)->findOpen($token))->toBeNull();

    $this->relationships->enable($this->org, $this->operator);

    // Re-enabling revived neither: both stay revoked, the old link is still dead, and the mail would still be dropped.
    expect(dlcRow($invitation->id)->revoked_reason)->toBe(OrganizationInvitations::REASON_ORGANIZATION_DISABLED)
        ->and(app(OrganizationInvitations::class)->findOpen($token))->toBeNull()
        ->and(dlcRow($known->id)->revoked_at)->not->toBeNull();

    expect((new OrganizationInvitationNotification($known->id, $token))->shouldSend(new stdClass, 'mail'))->toBeFalse()
        ->and((new OrganizationInvitationNotification($invitation->id, 'x'))->shouldSend(new stdClass, 'mail'))->toBeFalse();
});

it('drops a queued mail for an Organization that is not Enabled even when the invitation row itself is still open', function () {
    $issuer = regInvIssuer();
    [$invitation, $token] = dlcOpen($this->org, 'open-row@example.test', $issuer);
    $notification = new OrganizationInvitationNotification($invitation->id, $token);

    expect($notification->shouldSend(new stdClass, 'mail'))->toBeTrue();

    DB::table('organizations')->where('id', $this->org->id)->update(['disabled_at' => now()]);
    expect($notification->shouldSend(new stdClass, 'mail'))->toBeFalse();

    DB::table('organizations')->where('id', $this->org->id)->update(['disabled_at' => null]);
    expect($notification->shouldSend(new stdClass, 'mail'))->toBeTrue();
});

it('re-issues for the same address after a re-enable, from the slot the disable cleared, as a new credential', function () {
    $issuer = regInvIssuer();
    $first = app(OrganizationInvitations::class)->issue($this->org, 'again@example.test', $issuer);

    $this->relationships->disable($this->org, $this->operator);
    expect(dlcRow($first->id)->live_email)->toBeNull();

    $this->relationships->enable($this->org, $this->operator);
    $second = app(OrganizationInvitations::class)->issue($this->org, 'again@example.test', $issuer);

    expect($second->id)->not->toBe($first->id)
        ->and($second->token_hash)->not->toBe($first->token_hash)
        ->and(dlcRow($second->id)->live_email)->toBe('again@example.test')
        ->and(dlcRow($first->id)->revoked_reason)->toBe(OrganizationInvitations::REASON_ORGANIZATION_DISABLED)
        ->and(DB::table('organization_invitations')->where('organization_id', $this->org->id)->where('email', 'again@example.test')->count())->toBe(2);
});

it('re-issues just as well when an earlier authority-loss revocation left its slot behind (the other history)', function () {
    $issuer = regInvIssuer();
    // A hook revoked this one earlier and, by design, left its slot held. It is not open, so disable leaves it alone.
    [$hooked] = dlcOpen($this->org, 'hooked@example.test', $issuer, ['revoked_at' => now()->subHour(), 'revoked_reason' => 'issuer_suspended']);
    [$open] = dlcOpen($this->org, 'plain@example.test', $issuer);
    expect(dlcRow($hooked->id)->live_email)->toBe('hooked@example.test');

    $this->relationships->disable($this->org, $this->operator);

    expect(dlcRow($hooked->id)->revoked_reason)->toBe('issuer_suspended')           // untouched: not relabelled
        ->and(dlcRow($hooked->id)->live_email)->toBe('hooked@example.test')         // the slot it kept
        ->and(dlcRow($open->id)->live_email)->toBeNull();                           // the slot the disable cleared

    $this->relationships->enable($this->org, $this->operator);
    $service = app(OrganizationInvitations::class);

    // Both addresses can be invited again: issuance releases the held slot under the Organization lock, and the unique backstop is never reached.
    $viaHeld = $service->issue($this->org, 'hooked@example.test', $issuer);
    $viaCleared = $service->issue($this->org, 'plain@example.test', $issuer);

    expect(dlcRow($viaHeld->id)->live_email)->toBe('hooked@example.test')
        ->and(dlcRow($hooked->id)->live_email)->toBeNull()
        ->and(dlcRow($hooked->id)->revoked_reason)->toBe('issuer_suspended')
        ->and(dlcRow($viaCleared->id)->live_email)->toBe('plain@example.test');
});

it('refuses an acceptance independently of the revocation: an open row in a Disabled organization is still a dead link', function () {
    $issuer = regInvIssuer();
    [$invitation, $token] = dlcOpen($this->org, 'missed@example.test', $issuer);
    DB::table('organizations')->where('id', $this->org->id)->update(['disabled_at' => now()]); // as if the revocation had missed the row

    try {
        app(OrganizationInvitations::class)->accept($token, InvitationAcceptance::withPassword('N', Hash::make('Str0ng!Password99')));
        throw new RuntimeException('accepted');
    } catch (OrganizationInvitationException $e) {
        expect($e->detail)->toBe('organization_disabled');
    }

    expect(dlcRow($invitation->id)->accepted_at)->toBeNull()
        ->and(DB::table('users')->where('email', 'missed@example.test')->exists())->toBeFalse()
        ->and(DB::table('organization_members')->where('organization_id', $this->org->id)->count())->toBe(0);
});

it('does not close an account, remove a membership or end a session by disabling', function () {
    $member = User::factory()->create();
    DB::table('organization_members')->insert(['organization_id' => $this->org->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $epoch = DB::table('users')->where('id', $member->id)->value('session_epoch');

    $this->relationships->disable($this->org, $this->operator);

    expect(DB::table('users')->where('id', $member->id)->first(['disabled_at', 'session_epoch']))->toMatchObject(['disabled_at' => null, 'session_epoch' => $epoch])
        ->and(DB::table('organization_members')->where('organization_id', $this->org->id)->where('user_id', $member->id)->exists())->toBeTrue();
});

// ── Transitions ──────────────────────────────────────────────────────────────

it('moves Enabled to Disabled to Enabled and treats a repeat as a no-op that writes nothing', function () {
    $activity = fn () => DB::table('activity_log')->count();

    expect($this->relationships->enable($this->org, $this->operator))->toBeFalse();           // Enabled: nothing to re-enable
    expect($this->relationships->disable($this->org, $this->operator))->toBeTrue();

    $disabledAt = DB::table('organizations')->where('id', $this->org->id)->value('disabled_at');
    $entries = $activity();

    expect($this->relationships->disable($this->org, $this->operator))->toBeFalse()           // Disabled: nothing to disable; the original time is kept
        ->and($activity())->toBe($entries)
        ->and(DB::table('organizations')->where('id', $this->org->id)->value('disabled_at'))->toBe($disabledAt)
        ->and($this->relationships->enable($this->org, $this->operator))->toBeTrue()
        ->and(DB::table('organizations')->where('id', $this->org->id)->value('disabled_at'))->toBeNull();
});

it('refuses to disable or re-enable an organization that has never had portal access, with a controlled error and no CHECK violation', function () {
    $directoryOnly = Organization::factory()->directoryOnly()->create();
    $before = DB::table('organizations')->where('id', $directoryOnly->id)->first();
    $entries = DB::table('activity_log')->count();

    foreach (['disable', 'enable'] as $act) {
        try {
            $this->relationships->{$act}($directoryOnly, $this->operator);
            throw new RuntimeException("{$act} was not refused");
        } catch (OrganizationRelationshipException $e) {
            expect($e->reason)->toBe(OrganizationRelationshipException::PORTAL_NOT_ENABLED);
        }
    }

    expect(DB::table('organizations')->where('id', $directoryOnly->id)->first())->toEqual($before)
        ->and(DB::table('activity_log')->count())->toBe($entries);

    $this->actingAs($this->operator)->post(route('organizations.disable', $directoryOnly->id))->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->post(route('organizations.enable', $directoryOnly->id))->assertSessionHasErrors('relationship');
});

it('keeps disable and re-enable on manageRelationships and initial enablement on manageMembership: separate abilities', function () {
    $service = file_get_contents(app_path('Services/OrganizationRelationships.php'));
    $directory = file_get_contents(app_path('Services/OrganizationDirectory.php'));
    $controller = file_get_contents(app_path('Http/Controllers/Organization/OrganizationController.php'));

    // The lifecycle transitions authorize 'manageRelationships' only; initial enablement authorizes 'manageMembership' only, in the service and the route.
    preg_match('/public function disable\(.*?\n    }\n/s', $service, $disable);
    preg_match('/public function enable\(.*?\n    }\n/s', $service, $enable);
    preg_match('/public function enablePortalAccess\(.*?\n    }\n/s', $directory, $promote);

    expect($disable[0])->toContain('$this->authorize($actor)')->toContain('requireRelationshipManager')->not->toContain('isMembershipManager')
        ->and($enable[0])->toContain('requireRelationshipManager')->not->toContain('isMembershipManager')
        ->and($service)->toContain("'manageRelationships'")->not->toContain("'manageMembership'")
        ->and($promote[0])->toContain("'manageMembership'")->toContain('isMembershipManager')->not->toContain("'manageRelationships'")->not->toContain('isRelationshipManager')
        ->and($controller)->toContain("'manageMembership'")->not->toContain("'manageRelationships'");
});

it('answers both table predicates exactly as the two policy abilities do, and keeps them as two methods', function (string $kind) {
    $user = match ($kind) {
        'an active operator' => User::factory()->create()->assignRole('operator'),
        'a suspended operator' => tap(User::factory()->create()->assignRole('operator'), fn (User $u) => DB::table('users')->where('id', $u->id)->update(['disabled_at' => now()])),
        'portal.operator only' => User::factory()->create()->givePermissionTo('portal.operator'),
        'crm.manage only' => User::factory()->create()->givePermissionTo('crm.manage'),
        'a customer' => User::factory()->create()->assignRole('user'),
        'a custom role holding both' => tap(User::factory()->create(), fn (User $u) => $u->assignRole(Role::create(['name' => 'dlc-directory', 'guard_name' => 'web'])->givePermissionTo(['portal.operator', 'crm.manage']))),
    };
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::findOrFail($user->id);

    expect(OrganizationAuthority::isMembershipManager($user->id))->toBe(Gate::forUser($user)->allows('manageMembership', Organization::class))
        ->and(OrganizationAuthority::isRelationshipManager($user->id))->toBe(Gate::forUser($user)->allows('manageRelationships', Organization::class));
})->with(['an active operator', 'a suspended operator', 'portal.operator only', 'crm.manage only', 'a customer', 'a custom role holding both']);

// ── Initial enablement (Not enabled -> Enabled, one transition) ──────────────

it('enables portal access once, recording who and when, with an audit entry that shares the instant', function () {
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Fresh Co', 'created_by' => $this->operator->id]);
    $this->travel(10)->minutes();   // the Organization was created earlier: enablement is a later instant

    $organization = app(OrganizationDirectory::class)->enablePortalAccess($company, $this->operator);
    $row = DB::table('organizations')->where('id', $organization->id)->first();
    $entry = DB::table('activity_log')->where('description', OrganizationDirectory::PORTAL_ENABLED)->where('subject_id', $organization->id)->first();
    $properties = json_decode($entry->properties, true);

    // Enablement is a transition of an EXISTING row: its creation instant and creator are its own, and the enablement instant is the audit entry's.
    expect($row->portal_enabled_at)->not->toBe($row->created_at)
        ->and((int) $row->created_by)->toBe($this->operator->id)
        ->and((int) $row->portal_enabled_by)->toBe($this->operator->id)
        ->and($row->disabled_at)->toBeNull()
        ->and($entry->created_at)->toBe($row->portal_enabled_at)
        ->and((int) $entry->causer_id)->toBe($this->operator->id)
        ->and($properties['new']['portal_state'])->toBe('enabled')->and($properties['old']['portal_state'])->toBe('not_enabled')
        ->and(DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $this->operator->id)->value('role'))->toBe('admin');
});

it('refuses a second initial enablement as already_enabled with no side effect, and refuses one for an organization that is Disabled without clearing it', function () {
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Twice Co', 'created_by' => $this->operator->id]);
    $service = app(OrganizationDirectory::class);

    $first = $service->enablePortalAccess($company, $this->operator);
    $entries = DB::table('activity_log')->count();
    $snapshot = (array) DB::table('organizations')->where('id', $first->id)->first();
    $members = DB::table('organization_members')->count();

    try {
        $service->enablePortalAccess($company->fresh(), $this->operator);
        throw new RuntimeException('a second initial enablement was not refused');
    } catch (OrganizationRelationshipException $e) {
        expect($e->reason)->toBe(OrganizationRelationshipException::ALREADY_ENABLED);
    }

    expect(DB::table('activity_log')->count())->toBe($entries)
        ->and((array) DB::table('organizations')->where('id', $first->id)->first())->toBe($snapshot)
        ->and(DB::table('organization_members')->count())->toBe($members);

    $this->relationships->disable($first, $this->operator);
    $disabledAt = DB::table('organizations')->where('id', $first->id)->value('disabled_at');
    $entries = DB::table('activity_log')->count();

    try {
        $service->enablePortalAccess($company->fresh(), $this->operator);
        throw new RuntimeException('enablement was not refused');
    } catch (OrganizationRelationshipException $e) {
        expect($e->reason)->toBe(OrganizationRelationshipException::ENABLEMENT_REFUSED);
    }

    expect(DB::table('organizations')->where('id', $first->id)->value('disabled_at'))->toBe($disabledAt)
        ->and(DB::table('activity_log')->count())->toBe($entries);

    $this->actingAs($this->operator)->post(route('organizations.promote', $company))->assertSessionHasErrors('company');
});

it('requires manageMembership at the route even for an account the route middleware lets through: exactly 404 for a Directory-only Organization this account cannot see, in every surface mode', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);

    $stray = User::factory()->create()->assignRole('user');
    $stray->givePermissionTo('crm.manage');
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Guarded Co', 'created_by' => $this->operator->id]);

    // A Directory-only Organization has no members, so no customer can see it: the binding finds nothing (404) whichever mode answers first, and nothing is
    // enabled. (closed and pilot: the surface gate's 404; legacy: the route-model binding's 404. A visible Organization reaching the controller's own 403 is
    // the next test.) The service's own refusal for an account that does reach it is proved in OrganizationPortalStateFenceTest.
    expect($this->actingAs($stray)->post(route('organizations.promote', $company))->getStatusCode())->toBe(404, $mode);

    // The control: the operator is not refused in the same mode.
    $this->actingAs($this->operator)->post(route('organizations.promote', $company))->assertRedirect();

    expect(DB::table('organizations')->where('id', $company->id)->value('portal_enabled_at'))->not->toBeNull();
})->with(['closed', 'pilot', 'legacy']);

it('answers a customer who can see the Organization but holds only a stray crm.manage 403 in legacy and 404 in closed and pilot when asked to enable it', function (string $mode, int $status) {
    config(['portal.customer_surface_mode' => $mode]);

    $stray = User::factory()->create()->assignRole('user');
    $stray->givePermissionTo('crm.manage');
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Visible Co', 'created_by' => $this->operator->id]);
    DB::table('organization_members')->insert(['organization_id' => $company->id, 'user_id' => $stray->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);   // visible to it (a state the application cannot produce on a Directory-only Organization; inserted to reach the controller's own check)

    expect($this->actingAs($stray)->post(route('organizations.promote', $company))->getStatusCode())->toBe($status, $mode)
        ->and(DB::table('organizations')->where('id', $company->id)->value('portal_enabled_at'))->toBeNull();
})->with([['closed', 404], ['pilot', 404], ['legacy', 403]]);

// ── What the locked part of disable does not do ──────────────────────────────

it('asks neither the Gate nor the permission cache inside the disabling transaction', function () {
    $issuer = regInvIssuer();
    dlcOpen($this->org, 'lock-a@example.test', $issuer);
    dlcOpen($this->org, 'lock-b@example.test', $issuer);

    $inside = [];
    DB::listen(function (QueryExecuted $query) use (&$inside) {
        // The test's own transaction is level 1; the disabling transaction is the savepoint above it.
        if (DB::transactionLevel() > 1) {
            $inside[] = preg_replace('/\s+/', ' ', $query->sql);
        }
    });
    $gateCalls = 0;
    Gate::after(function () use (&$gateCalls) {
        if (DB::transactionLevel() > 1) {
            $gateCalls++;
        }
    });

    $this->relationships->disable($this->org, $this->operator);

    $cacheOrRegistrar = array_values(array_filter($inside, fn (string $sql) => str_contains($sql, '`cache`') || preg_match('/^select \* from `permissions`/', $sql) === 1 || str_contains($sql, 'from `roles`')));

    expect($gateCalls)->toBe(0)->and($cacheOrRegistrar)->toBe([]);

    // The order the contract states: the actor's user row (shared) first, then the Organization (exclusive), then the invitation rows (ascending).
    $locks = array_values(array_filter($inside, fn (string $sql) => str_contains($sql, 'lock in share mode') || str_contains($sql, 'for update')));
    expect($locks[0])->toContain('from `users`')->toContain('lock in share mode')
        ->and($locks[1])->toContain('from `organizations`')->toContain('for update')
        ->and($locks[2])->toContain('from `organization_invitations`')->toContain('order by `id` asc')->toContain('for update');
});
