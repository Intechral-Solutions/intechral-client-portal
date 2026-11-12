<?php

/*
 * Release 1.0 (R1-3C): the operator's side of organization invitations, through the real HTTP routes. The lifecycle rules and the
 * concurrency contract are R1-3A's (OrganizationInvitationFlowTest, OrganizationInvitationConcurrencyTest) and the recipient's side is
 * R1-3B's (InvitationTest, SocialiteInvitationTest); this file proves that the first production caller reaches the service correctly,
 * and only for the right actor:
 *
 *   - the Invitations card and the two routes (issue, which doubles as Resend, and revoke) are `manageMembership` and nothing else;
 *   - the request supplies an address and nothing more, and every invitation is looked up WITHIN the organization;
 *   - the mail goes out from the real database queue after the commit, carries the link that opens exactly this invitation, and a
 *     superseded or revoked link is dead (and its still-queued mail is dropped at send time);
 *   - an unexpected database failure reaches the log with no address, token or hash;
 *   - the whole operator-to-recipient journey, ending in an ordinary unapproved member who can raise a request for the organization.
 *
 * The queue is the real `database` queue and the mailer the array transport, as in NotificationSendTimeEligibilityTest: "sent" is a
 * MessageSent event carrying the rendered message.
 */

use App\Http\Controllers\Organization\OrganizationInvitationController;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\OrganizationInvitationList;
use App\Services\OrganizationInvitations;
use App\Support\InvitationIssueThrottle;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bootstrap\SetRequestForConsole;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Mime\RawMessage;

require_once __DIR__.'/../Auth/InvitationTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config(['queue.default' => 'database', 'mail.default' => 'array']);

    $this->sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) {
        $this->sent[] = $event->message;
    });

    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });

    $this->operator = opInvOperator();
    $this->org = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $this->operator->id]);
});

// ── Fixtures ─────────────────────────────────────────────────────────────────

/** An active account holding portal.operator AND crm.manage directly: a membership manager. */
function opInvOperator(array $attributes = []): User
{
    return regInvIssuer($attributes);
}

/** A plain customer in the organization, optionally holding the stray `crm.manage` that opens the route middleware. */
function opInvCustomer(Organization $organization, array $permissions = [], string $role = 'member'): User
{
    $user = User::factory()->create();
    $user->assignRole('user');

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    $organization->members()->attach($user->id, ['role' => $role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

/** Run one queued job as a worker does (tries and backoff come from the job). */
function opInvWorker(): void
{
    Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
}

function opInvDrainQueue(): void
{
    while (DB::table('jobs')->count() > 0) {
        opInvWorker();
    }
}

/** The plaintext token in the invitation link of a rendered message (the link is the only place the recipient gets it). */
function opInvTokenIn(RawMessage $message): string
{
    $body = $message->toString();

    // The mail is quoted-printable encoded: undo soft line breaks and =3D before looking for the link.
    $decoded = quoted_printable_decode($body);
    preg_match('#/invitation/([A-Za-z0-9]{64})#', $decoded, $match);

    expect($match)->not->toBeEmpty('the message carries no invitation link');

    return $match[1];
}

/** Issue through the real route as the given operator. */
function opInvIssue(object $test, User $actor, Organization $organization, string $email): TestResponse
{
    return $test->actingAs($actor)->post(route('organizations.invitations.store', $organization), ['email' => $email]);
}

function opInvRows(?Organization $organization = null): Collection
{
    return DB::table('organization_invitations')
        ->when($organization, fn ($query) => $query->where('organization_id', $organization->id))
        ->orderBy('id')->get();
}

/** Everything a refused operator request must not touch. */
function opInvFootprint(): array
{
    return [
        'invitations' => opInvRows()->map(fn ($row) => (array) $row)->all(),
        'jobs' => DB::table('jobs')->count(),
        'activity' => DB::table('activity_log')->count(),
        'users' => DB::table('users')->count(),
        'members' => DB::table('organization_members')->count(),
    ];
}

// ── The card ─────────────────────────────────────────────────────────────────

describe('the Invitations card', function () {
    it('is shown to an operator, with the form, an empty state and no credential', function () {
        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))
            ->assertOk()
            ->assertSee('Invitations')
            ->assertSee(route('organizations.invitations.store', $this->org), false)
            ->assertSee('Email address to invite')
            ->assertSee('No invitations yet.')
            ->assertSee('Invited people join as ordinary members; administrators are made separately.');
    });

    it('lists this organization\'s invitations only, newest first, with the invitee, the inviter, the status and the expiry', function () {
        $other = Organization::factory()->create(['name' => 'Globex']);
        regInvOpen('mine-old@example.test', $this->org, $this->operator);
        regInvOpen('mine-new@example.test', $this->org, $this->operator);
        regInvOpen('theirs@example.test', $other, $this->operator);

        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->assertOk()->getContent();

        expect($html)->toContain('mine-new@example.test')->and($html)->toContain('mine-old@example.test')->and($html)->not->toContain('theirs@example.test')
            ->and(strpos($html, 'mine-new@example.test'))->toBeLessThan(strpos($html, 'mine-old@example.test'))
            ->and($html)->toContain('Invited by '.$this->operator->name)
            ->and($html)->toContain('Pending')
            ->and($html)->toContain('Expires '.now()->addDays(OrganizationInvitations::LIFETIME_DAYS)->format('M j, Y'));
    });

    it('never renders a token or a hash, in any state', function () {
        [$open, $openToken] = regInvOpen('open@example.test', $this->org, $this->operator);
        [$revoked, $revokedToken] = regInvOpen('revoked@example.test', $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now(), 'revoked_by' => $this->operator->id, 'revoked_reason' => 'operator']);
        [$expired, $expiredToken] = regInvOpen('expired@example.test', $this->org, $this->operator, ['expires_at' => now()->subDay()]);

        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->assertOk()->getContent();

        foreach ([$open, $revoked, $expired] as $row) {
            expect($html)->not->toContain($row->token_hash);
        }

        foreach ([$openToken, $revokedToken, $expiredToken] as $token) {
            expect($html)->not->toContain($token);
        }

        expect($html)->not->toContain('/invitation/');
    });

    it('names each state and a fixed label for every revocation reason', function () {
        $reasons = [
            'operator' => 'Revoked by an operator',
            'superseded' => 'Replaced by a newer invitation',
            'issuer_suspended' => 'The inviting operator was suspended',
            'issuer_authority_lost' => 'The inviting operator lost the authority to invite',
            'issuer_reinstated' => 'The inviting operator\'s authority changed',
        ];

        foreach ($reasons as $code => $label) {
            regInvOpen("reason-{$code}@example.test", $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now(), 'revoked_by' => $this->operator->id, 'revoked_reason' => $code]);
        }

        regInvOpen('unknown-code@example.test', $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now(), 'revoked_reason' => 'something_else']);
        regInvOpen('no-code@example.test', $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now()]);
        regInvOpen('expired@example.test', $this->org, $this->operator, ['expires_at' => now()->subDay()]);
        $member = User::factory()->create(['name' => 'Joined Person']);
        regInvOpen('joined@example.test', $this->org, $this->operator, ['live_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $member->id]);

        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->assertOk()->getContent();

        expect(OrganizationInvitationList::REASON_LABELS)->toBe($reasons);

        foreach ($reasons as $label) {
            expect($html)->toContain(e($label));
        }

        // A code the application does not know, or none at all, is plainly Revoked: `revoked_at` is the state, the code is a label.
        expect($html)->toContain('Revoked')->and($html)->toContain('Expired')->and($html)->toContain('Accepted')->and($html)->toContain('Joined Person')
            ->and($html)->not->toContain('something_else');
    });

    it('shows Resend only on the newest pending or expired row of an address, and Revoke only on a pending row', function () {
        regInvOpen('old@example.test', $this->org, $this->operator, ['expires_at' => now()->subDays(3), 'live_email' => null]);
        regInvOpen('old@example.test', $this->org, $this->operator);
        regInvOpen('lapsed@example.test', $this->org, $this->operator, ['expires_at' => now()->subDay()]);
        regInvOpen('revoked@example.test', $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now(), 'revoked_reason' => 'operator']);

        $list = OrganizationInvitationList::for($this->org->id)['rows']->keyBy('email');
        $oldRows = OrganizationInvitationList::for($this->org->id)['rows']->where('email', 'old@example.test')->sortBy('id')->values();

        expect($oldRows[0]->state)->toBe('expired')->and($oldRows[0]->canResend)->toBeFalse()->and($oldRows[0]->canRevoke)->toBeFalse()
            ->and($oldRows[1]->state)->toBe('pending')->and($oldRows[1]->canResend)->toBeTrue()->and($oldRows[1]->canRevoke)->toBeTrue()
            ->and($list['lapsed@example.test']->canResend)->toBeTrue()->and($list['lapsed@example.test']->canRevoke)->toBeFalse()
            ->and($list['revoked@example.test']->canResend)->toBeFalse()->and($list['revoked@example.test']->canRevoke)->toBeFalse();
    });

    it('decides which row is the newest of an address within THIS organization only, even when another organization invited the same address later', function () {
        // One address, invited by two organizations: A first (lower id), B afterwards (higher id). If "newest row of the address" were
        // computed across organizations, A's row would look superseded by B's and lose its Resend. Each organization's card is about its
        // own invitations, so each shows Resend on its own row.
        $other = Organization::factory()->create(['name' => 'Beta Partners', 'owner_id' => $this->operator->id]);
        [$inA] = regInvOpen('shared@example.test', $this->org, $this->operator);
        [$inB] = regInvOpen('shared@example.test', $other, $this->operator);

        expect($inB->id)->toBeGreaterThan($inA->id);

        $rowsA = OrganizationInvitationList::for($this->org->id)['rows'];
        $rowsB = OrganizationInvitationList::for($other->id)['rows'];

        expect($rowsA)->toHaveCount(1)->and($rowsA->first()->id)->toBe($inA->id)
            ->and($rowsA->first()->canResend)->toBeTrue()->and($rowsA->first()->canRevoke)->toBeTrue()
            ->and($rowsB)->toHaveCount(1)->and($rowsB->first()->id)->toBe($inB->id)
            ->and($rowsB->first()->canResend)->toBeTrue();

        // The same on the rendered card: the Resend control is present for the older organization's row.
        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))
            ->assertOk()->assertSee('aria-label="Resend the invitation to shared@example.test"', false);

        // Within ONE organization the rule still holds (the control for the rule above): an older row of the address is not offered
        // Resend once a newer one exists in the SAME organization.
        regInvOpen('shared@example.test', $this->org, $this->operator, ['expires_at' => now()->subDay(), 'live_email' => null]);
        $again = OrganizationInvitationList::for($this->org->id)['rows']->where('email', 'shared@example.test')->sortBy('id')->values();

        expect($again)->toHaveCount(2)->and($again[0]->canResend)->toBeFalse()->and($again[1]->canResend)->toBeTrue();
    });

    it('says plainly when a pending link cannot be used: the address now has an account, or the inviter can no longer invite', function () {
        regInvOpen('taken@example.test', $this->org, $this->operator);
        User::factory()->create(['email' => 'taken@example.test']);
        $lapsedIssuer = opInvOperator();
        regInvOpen('orphan@example.test', $this->org, $lapsedIssuer);
        // Authority removed by a path the revocation hooks do not cover (a direct table write): the row still reads Pending by the clock.
        DB::table('model_has_permissions')->where('model_id', $lapsedIssuer->id)->delete();
        regInvOpen('fine@example.test', $this->org, $this->operator);

        $rows = OrganizationInvitationList::for($this->org->id)['rows']->keyBy('email');

        expect($rows['taken@example.test']->unusable)->toBe(OrganizationInvitationList::UNUSABLE_ACCOUNT_EXISTS)
            ->and($rows['orphan@example.test']->unusable)->toBe(OrganizationInvitationList::UNUSABLE_ISSUER)
            ->and($rows['fine@example.test']->unusable)->toBeNull()
            ->and($rows['taken@example.test']->state)->toBe('pending');

        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))
            ->assertSee(OrganizationInvitationList::UNUSABLE_ACCOUNT_EXISTS)
            ->assertSee(OrganizationInvitationList::UNUSABLE_ISSUER);
    });

    it('shows the most recent rows only, and says how many there are', function () {
        $limit = OrganizationInvitationList::LIMIT;

        foreach (range(1, $limit + 3) as $n) {
            regInvOpen("bulk-{$n}@example.test", $this->org, $this->operator);
        }

        $list = OrganizationInvitationList::for($this->org->id);

        expect($list['rows'])->toHaveCount($limit)->and($list['total'])->toBe($limit + 3)->and($list['truncated'])->toBeTrue()
            ->and($list['rows']->first()->email)->toBe('bulk-'.($limit + 3).'@example.test');

        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->assertSee('Showing the '.$limit.' most recent of '.($limit + 3).' invitations.');
    });

    it('names the accepted person, linking to them only for a viewer who may open user pages', function () {
        $member = User::factory()->create(['name' => 'Joined Person']);
        regInvOpen('joined@example.test', $this->org, $this->operator, ['live_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $member->id]);

        // The test operator holds only portal.operator and crm.manage: no users.view, so a name and no link.
        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))
            ->assertSee('Joined Person')->assertDontSee(route('users.show', $member), false);

        $viewer = opInvOperator();
        $viewer->givePermissionTo('users.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer)->get(route('organizations.show', $this->org))->assertSee(route('users.show', $member), false);
    });

    it('is absent for an account that cannot manage membership, and for a customer\'s own organization page', function () {
        regInvOpen('private@example.test', $this->org, $this->operator);

        // A customer holding a stray crm.manage can open the page of an organization they belong to; the card (and its addresses) is not built.
        $stray = opInvCustomer($this->org, ['crm.manage']);
        $this->actingAs($stray)->get(route('organizations.show', $this->org))
            ->assertOk()->assertDontSee('Invitations')->assertDontSee('private@example.test')
            ->assertDontSee(route('organizations.invitations.store', $this->org), false);

        // portal.operator without crm.manage never reaches the page.
        $half = User::factory()->create();
        $half->givePermissionTo('portal.operator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($half)->get(route('organizations.show', $this->org))->assertForbidden();
    });
});

// ── Authority ────────────────────────────────────────────────────────────────

describe('authority', function () {
    it('refuses every account that is not an operator with the directory capability, changing nothing', function (string $kind) {
        [$invitation] = regInvOpen('pending@example.test', $this->org, $this->operator);
        $actor = match ($kind) {
            // An approved organization administrator holds NO operator authority: approval is not an input.
            'approved administrator' => (function () {
                $admin = opInvCustomer($this->org, ['crm.manage'], 'admin');
                DB::table('organization_members')->where('organization_id', $this->org->id)->where('user_id', $admin->id)
                    ->update(['admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id]);

                return $admin;
            })(),
            'unapproved administrator' => opInvCustomer($this->org, ['crm.manage'], 'admin'),
            'member holding crm.manage' => opInvCustomer($this->org, ['crm.manage']),
            // The retired global invitation permission and the organization one grant nothing here.
            'holder of org.invite and crm.manage' => opInvCustomer($this->org, ['org.invite', 'crm.manage']),
            'portal.operator without crm.manage' => (function () {
                $user = User::factory()->create();
                $user->givePermissionTo('portal.operator');

                return $user;
            })(),
            'ordinary customer' => opInvCustomer($this->org),
            'partner administrator' => (function () {
                $partner = Organization::factory()->create();
                DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => true]);
                DB::table('organizations')->where('id', $this->org->id)->update(['managed_by_organization_id' => $partner->id]);

                return opInvCustomer($partner, ['crm.manage'], 'admin');
            })(),
        };
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $before = opInvFootprint();

        // 403 where the account can see the organization and lacks the capability; 404 where the tenant scope already hides the
        // organization from it (a partner's administrator is not a member of its customer). Never a success, never a write.
        $responses = [
            $this->actingAs($actor)->post(route('organizations.invitations.store', $this->org), ['email' => 'new@example.test']),
            $this->actingAs($actor)->delete(route('organizations.invitations.destroy', [$this->org, $invitation->id])),
            $this->actingAs($actor)->postJson(route('organizations.invitations.store', $this->org), ['email' => 'new@example.test']),
        ];

        foreach ($responses as $response) {
            expect($response->getStatusCode())->toBeIn([403, 404]);
        }

        if ($kind !== 'partner administrator') {
            foreach ($responses as $response) {
                $response->assertForbidden();
            }
        }

        expect(opInvFootprint())->toBe($before);
    })->with([
        'approved administrator', 'unapproved administrator', 'member holding crm.manage', 'holder of org.invite and crm.manage',
        'portal.operator without crm.manage', 'ordinary customer', 'partner administrator',
    ]);

    it('refuses a suspended operator, even one acting through a stale session', function () {
        $suspended = opInvOperator(['disabled_at' => now()]);
        $before = opInvFootprint();

        $this->actingAs($suspended)->post(route('organizations.invitations.store', $this->org), ['email' => 'new@example.test'])->assertForbidden();

        expect(opInvFootprint())->toBe($before);
    });

    it('sends a guest to sign in', function () {
        $this->post(route('organizations.invitations.store', $this->org), ['email' => 'new@example.test'])->assertRedirect(route('login'));
        $this->delete(route('organizations.invitations.destroy', [$this->org, 1]))->assertRedirect(route('login'));
    });

    it('leaves both routes in the web group, whose request-forgery protection the application excludes nothing from, and the forms carry the token', function () {
        // The framework skips the check itself while unit tests run, so the proof is structural: the routes are in the `web` group, the
        // application declares no forgery exception, and every form the card renders carries the token (what a browser would submit).
        foreach (['organizations.invitations.store', 'organizations.invitations.destroy'] as $name) {
            expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('web')->toContain('auth')->toContain('can:crm.manage');
        }

        expect(file_get_contents(base_path('bootstrap/app.php')))->not->toMatch('/csrf|forgery/i');

        regInvOpen('pending@example.test', $this->org, $this->operator);
        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->getContent();
        preg_match_all('#<form[^>]*action="[^"]*/invitations[^"]*"[^>]*>(.*?)</form>#s', $html, $forms);

        expect($forms[1])->toHaveCount(3);

        foreach ($forms[1] as $body) {
            expect($body)->toContain('name="_token"');
        }
    });

    it('lets only the two routes through to the service: no other verb or path exists', function () {
        $names = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_contains((string) $route->getName(), 'invitations.') && str_starts_with((string) $route->getName(), 'organizations.'))
            ->mapWithKeys(fn ($route) => [$route->getName() => [$route->methods()[0], $route->uri()]])->all();

        expect($names)->toBe([
            'organizations.invitations.store' => ['POST', 'organizations/{organization}/invitations'],
            'organizations.invitations.destroy' => ['DELETE', 'organizations/{organization}/invitations/{invitation}'],
        ]);
    });
});

// ── Issuance ─────────────────────────────────────────────────────────────────

describe('issuing an invitation', function () {
    it('creates one open, organization-bound invitation, audits it, queues one mail after the commit and says what happened', function () {
        $response = opInvIssue($this, $this->operator, $this->org, '  New.Person@Example.TEST ');

        $response->assertRedirect(route('organizations.show', $this->org));
        $row = opInvRows()->sole();

        expect($row->organization_id)->toBe($this->org->id)
            ->and($row->email)->toBe('new.person@example.test')
            ->and($row->live_email)->toBe('new.person@example.test')
            ->and($row->invited_by)->toBe($this->operator->id)
            ->and($row->accepted_at)->toBeNull()->and($row->revoked_at)->toBeNull()
            ->and(Carbon::parse($row->expires_at)->isSameDay(now()->addDays(OrganizationInvitations::LIFETIME_DAYS)))->toBeTrue()
            ->and(DB::table('jobs')->count())->toBe(1);

        $this->get(route('organizations.show', $this->org))->assertSee('Invitation sent to new.person@example.test.')
            ->assertSee('It expires in '.OrganizationInvitations::LIFETIME_DAYS.' days');

        $audit = DB::table('activity_log')->where('description', OrganizationInvitations::ISSUED)->sole();
        expect($audit->subject_id)->toBe($this->org->id)->and($audit->causer_id)->toBe($this->operator->id)
            ->and($audit->properties)->not->toContain($row->token_hash);
    });

    it('takes the address and nothing else: the organization, the inviter, the role and the expiry are not request inputs', function () {
        $other = Organization::factory()->create(['name' => 'Globex']);
        $someoneElse = opInvOperator();

        $this->actingAs($this->operator)->post(route('organizations.invitations.store', $this->org), [
            'email' => 'new@example.test', 'organization_id' => $other->id, 'role' => 'admin', 'invited_by' => $someoneElse->id,
            'expires_at' => now()->addYears(5)->toDateTimeString(), 'live_email' => 'other@example.test', 'accepted_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $row = opInvRows()->sole();

        expect($row->organization_id)->toBe($this->org->id)->and($row->invited_by)->toBe($this->operator->id)
            ->and($row->email)->toBe('new@example.test')->and($row->live_email)->toBe('new@example.test')
            ->and($row->accepted_at)->toBeNull()
            ->and(Carbon::parse($row->expires_at)->lt(now()->addDays(OrganizationInvitations::LIFETIME_DAYS + 1)))->toBeTrue();
    });

    it('refuses an invalid address, writing nothing and queueing nothing', function (mixed $email) {
        $before = opInvFootprint();

        $this->actingAs($this->operator)->from(route('organizations.show', $this->org))
            ->post(route('organizations.invitations.store', $this->org), ['email' => $email])
            ->assertRedirect(route('organizations.show', $this->org))->assertSessionHasErrors('email');

        expect(opInvFootprint())->toBe($before);
    })->with([
        'missing' => [null], 'empty' => [''], 'not an address' => ['not-an-address'], 'two addresses' => ['a@example.test, b@example.test'],
        'too long' => [str_repeat('a', 250).'@example.test'], 'an array' => [['a@example.test']],
    ]);

    it('shows the error beside the field, with the attempted address kept, and the field named by its label', function () {
        $html = $this->actingAs($this->operator)->followingRedirects()->from(route('organizations.show', $this->org))
            ->post(route('organizations.invitations.store', $this->org), ['email' => 'nonsense'])->getContent();

        expect($html)->toContain('id="invitation-email-error"')->and($html)->toContain('aria-invalid="true"')
            ->and($html)->toContain('aria-describedby="invitation-email-error"')->and($html)->toContain('value="nonsense"')
            ->and($html)->toMatch('/<label\s+for="invitation-email"/');
    });

    it('refuses an address that already belongs to an account, active or suspended, telling the operator to use Add member', function (?string $disabled) {
        User::factory()->create(['email' => 'Existing@Example.test', 'disabled_at' => $disabled ? now() : null]);
        $before = opInvFootprint();

        // A redirect back to the page with the refusal in the session (not a 500 page that happens to quote the message).
        $this->actingAs($this->operator)->post(route('organizations.invitations.store', $this->org), ['email' => 'existing@example.test'])
            ->assertRedirect(route('organizations.show', $this->org))
            ->assertSessionHasErrors(['email' => 'That address already belongs to an account. Use Add member on the organization page instead of an invitation.']);

        expect(opInvFootprint())->toBe($before);

        // And the page the operator lands on shows it, beside the field.
        $this->followingRedirects()->post(route('organizations.invitations.store', $this->org), ['email' => 'existing@example.test'])
            ->assertSee('That address already belongs to an account. Use Add member')->assertSee('id="invitation-email-error"', false);
    })->with(['an active account' => [null], 'a suspended account' => ['yes']]);

    it('tells the operator to try again when another invitation took the slot at the same moment, writing nothing', function () {
        $racing = false;
        DB::beforeExecuting(function (string $sql, array $bindings) use (&$racing) {
            // A competing issuance commits its row for the same address between this one's slot read and its insert.
            if (! $racing && str_starts_with(ltrim($sql), 'insert into `organization_invitations`')) {
                $racing = true;
                DB::table('organization_invitations')->insert([
                    'organization_id' => $this->org->id, 'email' => 'race@example.test', 'live_email' => 'race@example.test', 'token_hash' => hash('sha256', 'competitor'),
                    'invited_by' => $this->operator->id, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        $before = opInvFootprint();

        $this->actingAs($this->operator)->post(route('organizations.invitations.store', $this->org), ['email' => 'race@example.test'])
            ->assertRedirect(route('organizations.show', $this->org))
            ->assertSessionHasErrors(['email' => 'Another invitation for this address was issued at the same moment. Check the invitations list and try again.']);

        expect($racing)->toBeTrue()->and(opInvFootprint())->toBe($before);
    });

    it('answers a missing organization as not found', function () {
        $this->actingAs($this->operator)->post(route('organizations.invitations.store', 99999), ['email' => 'new@example.test'])->assertNotFound();
    });

    it('resends: the earlier open link is replaced, dies, and its still-queued mail is dropped at send time', function () {
        opInvIssue($this, $this->operator, $this->org, 'again@example.test')->assertRedirect();
        opInvIssue($this, $this->operator, $this->org, 'AGAIN@example.test')->assertRedirect();

        [$first, $second] = opInvRows()->all();

        expect($first->revoked_reason)->toBe(OrganizationInvitations::REASON_SUPERSEDED)->and($first->revoked_by)->toBe($this->operator->id)
            ->and($first->live_email)->toBeNull()->and($second->live_email)->toBe('again@example.test')->and($second->revoked_at)->toBeNull()
            ->and(DB::table('jobs')->count())->toBe(2);

        // Both mails were queued before either was delivered: only the live one may go out.
        opInvDrainQueue();

        expect($this->sent)->toHaveCount(1);
        $token = opInvTokenIn($this->sent[0]);
        expect(OrganizationInvitations::hashToken($token))->toBe($second->token_hash);

        auth()->logout();
        $this->get(route('invitation.show', $token))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-register'));
        expect(DB::table('activity_log')->where('description', OrganizationInvitations::SUPERSEDED)->count())->toBe(1);
    });

    it('kills the first link by GET and by POST once a resend replaces it, whether or not its mail had been delivered', function () {
        opInvIssue($this, $this->operator, $this->org, 'delivered@example.test')->assertRedirect();
        opInvDrainQueue();
        $firstToken = opInvTokenIn($this->sent[0]);

        // The recipient already holds the first link; the operator resends.
        opInvIssue($this, $this->operator, $this->org, 'delivered@example.test')->assertRedirect();
        opInvDrainQueue();
        $secondToken = opInvTokenIn($this->sent[1]);
        auth()->logout();
        $this->flushSession();

        expect($firstToken)->not->toBe($secondToken);

        $footprint = regInvFootprint();
        $this->get(route('invitation.show', $firstToken))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
        $this->post(route('invitation.register', $firstToken), regInvForm())->assertRedirect(route('invitation.show', $firstToken));
        $this->assertGuest();
        expect(regInvFootprint())->toBe($footprint);

        $this->get(route('invitation.show', $secondToken))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-register'));
    });

    it('resends from the card: the Resend button posts the row\'s address to the same route', function () {
        regInvOpen('resend-me@example.test', $this->org, $this->operator, ['expires_at' => now()->subHour()]);

        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->getContent();

        expect($html)->toContain('aria-label="Resend the invitation to resend-me@example.test"')
            ->and($html)->toContain('<input type="hidden" name="email" value="resend-me@example.test">');

        opInvIssue($this, $this->operator, $this->org, 'resend-me@example.test')->assertRedirect();

        $rows = opInvRows();
        // The expired row stays Expired (it is never relabelled revoked); exactly one row holds the slot.
        expect($rows)->toHaveCount(2)->and($rows[0]->revoked_at)->toBeNull()->and($rows[0]->live_email)->toBeNull()
            ->and($rows[1]->live_email)->toBe('resend-me@example.test');
    });

    it('delivers a mail whose link opens exactly this invitation, for this organization, and only the hash is stored', function () {
        opInvIssue($this, $this->operator, $this->org, 'new@example.test')->assertRedirect();
        // Nothing is mailed until the commit has happened and a worker has run.
        expect($this->sent)->toBe([]);
        opInvDrainQueue();

        expect($this->sent)->toHaveCount(1);
        $message = $this->sent[0];
        $token = opInvTokenIn($message);
        $row = opInvRows()->sole();

        expect($message->getTo()[0]->getAddress())->toBe('new@example.test')
            ->and(OrganizationInvitations::hashToken($token))->toBe($row->token_hash)
            ->and($row->token_hash)->not->toBe($token)
            ->and(DB::table('organization_invitations')->get()->toJson())->not->toContain($token);

        // The decoded text names the organization and the inviter.
        $text = quoted_printable_decode($message->toString());
        expect($text)->toContain('Acme Holdings')->and($text)->toContain($this->operator->name)->and($text)->toContain((string) OrganizationInvitations::LIFETIME_DAYS.' days');

        // The link opens the registration page of THIS organization, for this address, and names no role.
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-register')->where('invitation.organizationName', 'Acme Holdings')->where('invitation.email', 'new@example.test'));
    });

    it('builds the link in the mail from the configured APP_URL, never from the Host the issuing request arrived on', function () {
        config(['app.url' => 'https://portal.example.test']);

        // The operator's request arrives under a hostile Host and forwarded-host. The job is queued; nothing about the request may
        // survive into the mail.
        $this->actingAs($this->operator)
            ->withServerVariables(['HTTP_HOST' => 'attacker.example', 'HTTP_X_FORWARDED_HOST' => 'attacker.example'])
            ->post(route('organizations.invitations.store', $this->org), ['email' => 'new@example.test'])
            ->assertRedirect();

        // The queued job is encrypted and carries the invitation id and the token; nothing from the request's host.
        expect(DB::table('jobs')->sole()->payload)->not->toContain('attacker.example');

        // The worker is a console process: the framework gives it a request built from app.url (SetRequestForConsole), exactly as
        // `php artisan queue:work` boots. Do the same here, then deliver.
        (new SetRequestForConsole)->bootstrap(app());
        opInvDrainQueue();

        expect($this->sent)->toHaveCount(1);
        $text = quoted_printable_decode($this->sent[0]->toString());
        preg_match_all('#https?://[^\s<>"\)\]]+/invitation/[A-Za-z0-9]{64}#', $text, $links);

        expect($links[0])->not->toBeEmpty();

        foreach (array_unique($links[0]) as $link) {
            expect(parse_url($link, PHP_URL_SCHEME).'://'.parse_url($link, PHP_URL_HOST))->toBe('https://portal.example.test');
        }

        expect($text)->not->toContain('attacker.example');
    });

    it('leaves no row and queues no mail when the issuing transaction fails, and reports nothing sensitive', function () {
        $captured = (object) ['hash' => null];
        DB::beforeExecuting(function (string $sql, array $bindings) use ($captured) {
            if (str_starts_with(ltrim($sql), 'insert into `organization_invitations`')) {
                $captured->hash = collect($bindings)->first(fn ($value) => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1);
            }

            // The audit write is the last statement of the transaction: everything before it must roll back with it.
            if (str_starts_with(ltrim($sql), 'insert into `activity_log`')) {
                throw new QueryException('mysql', $sql, $bindings, new PDOException('SQLSTATE[HY000]: forced failure'));
            }
        });

        $response = opInvIssue($this, $this->operator, $this->org, 'secret.person@example.test');

        $response->assertStatus(500);
        expect(opInvRows())->toBeEmpty()->and(DB::table('jobs')->count())->toBe(0)->and($captured->hash)->toBeString();

        $everything = implode("\n", $this->logged).$response->getContent();

        foreach (['secret.person@example.test', $captured->hash] as $needle) {
            expect($everything)->not->toContain($needle);
        }

        expect(implode("\n", $this->logged))->toContain('invitation_database_failure');
    });

    it('reports a database failure on the invitation insert itself without the address or the hash', function () {
        $captured = (object) ['hash' => null];
        DB::beforeExecuting(function (string $sql, array $bindings) use ($captured) {
            if (str_starts_with(ltrim($sql), 'insert into `organization_invitations`')) {
                $captured->hash = collect($bindings)->first(fn ($value) => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1);

                throw new QueryException('mysql', $sql, $bindings, new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'secret.person@example.test\' for key \'something_else\''));
            }
        });

        $response = opInvIssue($this, $this->operator, $this->org, 'secret.person@example.test');

        $response->assertStatus(500);
        $everything = implode("\n", $this->logged).$response->getContent();

        foreach (['secret.person@example.test', $captured->hash] as $needle) {
            expect($everything)->not->toContain($needle);
        }

        expect(opInvRows())->toBeEmpty()->and(DB::table('jobs')->count())->toBe(0);
    });

    it('reports a failure to queue the mail without the address or the token, leaving a Pending row the operator can resend', function () {
        DB::beforeExecuting(function (string $sql, array $bindings) {
            if (str_starts_with(ltrim($sql), 'insert into `jobs`')) {
                throw new QueryException('mysql', $sql, $bindings, new PDOException('SQLSTATE[HY000]: forced failure'));
            }
        });

        $response = opInvIssue($this, $this->operator, $this->org, 'secret.person@example.test');

        $response->assertStatus(500);
        $row = opInvRows()->sole();
        $everything = implode("\n", $this->logged).$response->getContent();

        // The invitation committed before the dispatch (accepted risk R4 of the design record); it reads Pending and Resend recovers it.
        expect($row->revoked_at)->toBeNull()->and($row->live_email)->toBe('secret.person@example.test')
            ->and($everything)->not->toContain('secret.person@example.test')->and($everything)->not->toContain($row->token_hash);
    });
});

// ── Revocation ───────────────────────────────────────────────────────────────

describe('revoking an invitation', function () {
    it('revokes an open invitation: state, reason, actor, slot, audit, and the link is dead', function () {
        [$invitation, $token] = regInvOpen('pending@example.test', $this->org, $this->operator);

        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $invitation->id]))
            ->assertRedirect(route('organizations.show', $this->org));

        $row = opInvRows()->sole();
        expect($row->revoked_at)->not->toBeNull()->and($row->revoked_reason)->toBe(OrganizationInvitations::REASON_OPERATOR)
            ->and($row->revoked_by)->toBe($this->operator->id)->and($row->live_email)->toBeNull();

        $audit = DB::table('activity_log')->where('description', OrganizationInvitations::REVOKED)->sole();
        expect($audit->subject_id)->toBe($this->org->id)->and($audit->causer_id)->toBe($this->operator->id);

        $this->get(route('organizations.show', $this->org))->assertSee('Invitation revoked. Its link no longer works.')->assertSee('Revoked by an operator');
        auth()->logout();

        // Dead by GET and by POST, with the one uniform page and nothing written.
        $footprint = regInvFootprint();
        $this->get(route('invitation.show', $token))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));
        $this->assertGuest();
        expect(regInvFootprint())->toBe($footprint);
    });

    it('drops the still-queued mail of a revoked invitation', function () {
        opInvIssue($this, $this->operator, $this->org, 'new@example.test')->assertRedirect();
        $id = opInvRows()->sole()->id;

        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $id]))->assertRedirect();
        opInvDrainQueue();

        expect($this->sent)->toBe([])->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('is idempotent: a second revoke, and a revoke of an accepted, expired or already superseded row, change nothing and audit nothing', function () {
        $member = User::factory()->create();
        [$accepted] = regInvOpen('accepted@example.test', $this->org, $this->operator, ['live_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $member->id]);
        [$expired] = regInvOpen('expired@example.test', $this->org, $this->operator, ['expires_at' => now()->subDay()]);
        [$superseded] = regInvOpen('superseded@example.test', $this->org, $this->operator, ['live_email' => null, 'revoked_at' => now()->subHour(), 'revoked_by' => $this->operator->id, 'revoked_reason' => 'superseded']);
        [$open] = regInvOpen('open@example.test', $this->org, $this->operator);

        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $open->id]))->assertRedirect();
        $afterFirst = opInvFootprint();

        foreach ([$open, $accepted, $expired, $superseded] as $row) {
            $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $row->id]))
                ->assertRedirect(route('organizations.show', $this->org))
                ->assertSessionHas('success', fn (string $message) => str_contains($message, 'no longer pending'));
        }

        expect(opInvFootprint())->toBe($afterFirst)
            ->and(DB::table('activity_log')->where('description', OrganizationInvitations::REVOKED)->count())->toBe(1)
            // An expired row is never relabelled "revoked", an accepted one never un-accepted, a superseded one keeps its reason.
            ->and(DB::table('organization_invitations')->find($expired->id)->revoked_at)->toBeNull()
            ->and(DB::table('organization_invitations')->find($accepted->id)->revoked_at)->toBeNull()
            ->and(DB::table('organization_invitations')->find($superseded->id)->revoked_reason)->toBe('superseded');
    });

    it('answers another organization\'s invitation, and a missing one, as not found, changing nothing', function () {
        $other = Organization::factory()->create(['name' => 'Globex']);
        [$theirs, $theirToken] = regInvOpen('theirs@example.test', $other, $this->operator);
        $before = opInvFootprint();

        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $theirs->id]))->assertNotFound();
        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, 987654]))->assertNotFound();
        // A non-numeric id never reaches the controller.
        $this->actingAs($this->operator)->delete('/organizations/'.$this->org->id.'/invitations/abc')->assertNotFound();

        expect(opInvFootprint())->toBe($before);
        auth()->logout();
        $this->get(route('invitation.show', $theirToken))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-register'));
    });

    it('reports a database failure on revoke without the address', function () {
        [$invitation] = regInvOpen('secret.person@example.test', $this->org, $this->operator);
        DB::beforeExecuting(function (string $sql, array $bindings) {
            if (str_starts_with(ltrim($sql), 'insert into `activity_log`')) {
                throw new QueryException('mysql', $sql, $bindings, new PDOException('SQLSTATE[HY000]: forced failure'));
            }
        });

        $response = $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $invitation->id]));

        $response->assertStatus(500);
        $everything = implode("\n", $this->logged).$response->getContent();

        expect($everything)->not->toContain('secret.person@example.test')->and($everything)->not->toContain($invitation->token_hash)
            ->and(opInvRows()->sole()->revoked_at)->toBeNull();
    });
});

// ── The send-time and lifecycle contract, reached through the routes ─────────

describe('issuance throttling', function () {
    /** Spend the operator's allowance with cheap, real attempts (an invalid address: validation answers, nothing is created). */
    function opInvExhaust(object $test, User $actor, Organization $organization): void
    {
        for ($i = 0; $i < InvitationIssueThrottle::ATTEMPTS_PER_HOUR; $i++) {
            $test->actingAs($actor)->post(route('organizations.invitations.store', $organization), ['email' => 'not-an-address'])->assertRedirect();
        }
    }

    it('allows 30 attempts an hour and answers the 31st with 429, creating, changing, auditing and mailing nothing', function () {
        for ($i = 1; $i <= InvitationIssueThrottle::ATTEMPTS_PER_HOUR; $i++) {
            opInvIssue($this, $this->operator, $this->org, "person{$i}@example.test")->assertRedirect(route('organizations.show', $this->org));
        }

        expect(opInvRows())->toHaveCount(30)->and(DB::table('jobs')->count())->toBe(30);

        $before = opInvFootprint();
        $audits = DB::table('activity_log')->count();

        $limited = opInvIssue($this, $this->operator, $this->org, 'person31@example.test');
        $limited->assertStatus(429);
        expect($limited->headers->get('Retry-After'))->not->toBeNull()
            ->and(opInvFootprint())->toBe($before)
            ->and(DB::table('activity_log')->count())->toBe($audits)
            ->and(opInvRows()->where('email', 'person31@example.test'))->toHaveCount(0);

        // A Resend is the same route, so it is limited too, and it too changes nothing: the first invitation stays the live one.
        $first = opInvRows()->first();
        opInvIssue($this, $this->operator, $this->org, 'person1@example.test')->assertStatus(429);
        expect(opInvRows()->firstWhere('id', $first->id)->live_email)->toBe('person1@example.test')
            ->and(opInvFootprint())->toBe($before);

        // Nothing was mailed for either refused attempt: the 30 queued mails are all there is.
        opInvDrainQueue();
        expect($this->sent)->toHaveCount(30);
    });

    it('counts every attempt, a refused or invalid one included, so a bad request is not free', function () {
        opInvExhaust($this, $this->operator, $this->org);

        opInvIssue($this, $this->operator, $this->org, 'valid@example.test')->assertStatus(429);
        expect(opInvRows())->toHaveCount(0)->and(DB::table('jobs')->count())->toBe(0);
    });

    it('keeps a separate allowance for each operator', function () {
        $second = opInvOperator();
        opInvExhaust($this, $this->operator, $this->org);

        opInvIssue($this, $this->operator, $this->org, 'first@example.test')->assertStatus(429);
        // The other operator is untouched, from the same client address.
        opInvIssue($this, $second, $this->org, 'second@example.test')->assertRedirect(route('organizations.show', $this->org));

        expect(opInvRows()->pluck('invited_by')->all())->toBe([$second->id]);
    });

    it('does not let another account spend an operator\'s allowance, and still refuses it as before', function () {
        $customer = opInvCustomer($this->org, ['crm.manage']);

        // Thirty refused attempts by a customer leave the operator's allowance whole...
        for ($i = 0; $i < InvitationIssueThrottle::ATTEMPTS_PER_HOUR; $i++) {
            $this->actingAs($customer)->post(route('organizations.invitations.store', $this->org), ['email' => 'x@example.test'])->assertForbidden();
        }

        opInvIssue($this, $this->operator, $this->org, 'operator-still-can@example.test')->assertRedirect(route('organizations.show', $this->org));
        expect(opInvRows())->toHaveCount(1);

        // ...and below the limit every refusal is exactly the one it always was (not a 429 standing in for it).
        $fresh = opInvCustomer($this->org, ['crm.manage']);
        $this->actingAs($fresh)->post(route('organizations.invitations.store', $this->org), ['email' => 'x@example.test'])->assertForbidden();
        $this->post(route('organizations.invitations.store', $this->org), ['email' => 'not-an-address'])->assertForbidden();
    });

    it('does not limit revocation: an operator who has used the allowance can still kill a link', function () {
        [$invitation, $token] = regInvOpen('pending@example.test', $this->org, $this->operator);
        opInvExhaust($this, $this->operator, $this->org);
        opInvIssue($this, $this->operator, $this->org, 'blocked@example.test')->assertStatus(429);

        $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$this->org, $invitation->id]))
            ->assertRedirect(route('organizations.show', $this->org));

        expect(opInvRows()->sole()->revoked_at)->not->toBeNull();
        // The page that carries the card is not throttled either.
        $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->assertOk()->assertSee('Revoked by an operator');
        auth()->logout();
        $this->get(route('invitation.show', $token))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
    });

    it('is attached to the issuing route only', function () {
        $middleware = fn (string $name) => collect(Route::getRoutes()->getByName($name)->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'))->values()->all();

        expect($middleware('organizations.invitations.store'))->toBe(['throttle:'.InvitationIssueThrottle::NAME])
            ->and($middleware('organizations.invitations.destroy'))->toBe([])
            ->and(InvitationIssueThrottle::ATTEMPTS_PER_HOUR)->toBe(30);
    });

    it('keys the allowance on the account, and the browser suite\'s reset clears exactly that key', function () {
        opInvExhaust($this, $this->operator, $this->org);
        opInvIssue($this, $this->operator, $this->org, 'blocked@example.test')->assertStatus(429);

        $other = opInvOperator();
        InvitationIssueThrottle::clearFor($other->id);
        opInvIssue($this, $this->operator, $this->org, 'still-blocked@example.test')->assertStatus(429);

        InvitationIssueThrottle::clearFor($this->operator->id);
        opInvIssue($this, $this->operator, $this->org, 'unblocked@example.test')->assertRedirect(route('organizations.show', $this->org));
        expect(opInvRows()->pluck('email')->all())->toBe(['unblocked@example.test']);
    });
});

describe('what a queued mail does after the operator\'s act', function () {
    it('is not sent when the inviting operator is suspended before the worker runs', function () {
        $issuer = opInvOperator();
        $admin = opInvOperator();
        opInvIssue($this, $issuer, $this->org, 'new@example.test')->assertRedirect();

        // Through the application path: the suspension revokes the issuer's open invitations (issuer_suspended).
        app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test');
        opInvDrainQueue();

        expect($this->sent)->toBe([])
            ->and(opInvRows()->sole()->revoked_reason)->toBe(OrganizationInvitations::REASON_ISSUER_SUSPENDED);
    });

    it('is not sent when the address becomes an account before the worker runs', function () {
        opInvIssue($this, $this->operator, $this->org, 'new@example.test')->assertRedirect();
        User::factory()->create(['email' => 'new@example.test']);
        opInvDrainQueue();

        expect($this->sent)->toBe([]);
    });

    it('is not sent when the inviter loses the authority by a path no hook covers', function () {
        $issuer = opInvOperator();
        opInvIssue($this, $issuer, $this->org, 'new@example.test')->assertRedirect();
        DB::table('model_has_permissions')->where('model_id', $issuer->id)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        opInvDrainQueue();

        expect($this->sent)->toBe([])->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});

// ── The whole journey ────────────────────────────────────────────────────────

describe('the operator-to-recipient journey', function () {
    it('creates an ordinary unapproved member of this organization only, who lands signed in and can raise a request for it', function () {
        $other = Organization::factory()->create(['name' => 'Globex']);
        $existingMember = opInvCustomer($this->org);
        $theirs = opInvCustomer($other);
        auth()->logout();

        opInvIssue($this, $this->operator, $this->org, 'Newcomer@Example.test')->assertRedirect();
        opInvDrainQueue();
        $token = opInvTokenIn($this->sent[0]);
        auth()->logout();
        $this->flushSession();

        // The recipient chooses a name and a password. An address, an organization, a role or an approval in the body is not an input.
        $this->post(route('invitation.register', $token), regInvForm([
            'name' => 'Nina Newcomer', 'email' => 'someone.else@example.test', 'organization_id' => $other->id, 'role' => 'admin',
            'admin_approved_at' => now()->toDateTimeString(),
        ]))->assertRedirect();

        $user = User::where('email', 'newcomer@example.test')->sole();
        $this->assertAuthenticatedAs($user);

        expect(User::where('email', 'someone.else@example.test')->exists())->toBeFalse()
            ->and($user->getRoleNames()->all())->toBe(['user'])
            ->and($user->getDirectPermissions())->toHaveCount(0)
            ->and($user->invited_by)->toBe($this->operator->id);

        $memberships = DB::table('organization_members')->where('user_id', $user->id)->get();
        expect($memberships)->toHaveCount(1)
            ->and($memberships[0]->organization_id)->toBe($this->org->id)
            ->and($memberships[0]->role)->toBe('member')
            ->and($memberships[0]->admin_approved_at)->toBeNull()->and($memberships[0]->admin_approved_by)->toBeNull();

        $row = opInvRows()->sole();
        expect($row->accepted_user_id)->toBe($user->id)->and($row->accepted_at)->not->toBeNull()->and($row->live_email)->toBeNull();

        // The new member can raise a request, attributed to the organization that invited them and to no other.
        $this->post(route('tickets.store'), ['title' => 'First request', 'description' => 'Hello.', 'category' => 'General', 'priority' => 'low'])->assertRedirect();
        $ticket = Ticket::sole();
        expect($ticket->organization_id)->toBe($this->org->id)->and($ticket->user_id)->toBe($user->id);

        // They see their own request and nobody else's.
        $this->get(route('tickets.index'))->assertOk();
        expect(Ticket::query()->where('user_id', '<>', $user->id)->count())->toBe(0);
        $this->get(route('tickets.show', $ticket))->assertOk();

        // The link is single use.
        auth()->logout();
        $this->flushSession();
        $this->get(route('invitation.show', $token))->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));

        // Another organization's member list is untouched, and the operator now sees the outcome on the card.
        expect(DB::table('organization_members')->where('organization_id', $other->id)->pluck('user_id')->all())->toBe([$theirs->id])
            ->and(DB::table('organization_members')->where('organization_id', $this->org->id)->count())->toBe(2)
            ->and($existingMember->exists)->toBeTrue();

        $html = $this->actingAs($this->operator)->get(route('organizations.show', $this->org))->getContent();
        expect($html)->toContain('Accepted')->and($html)->toContain('Nina Newcomer')->and($html)->toContain('newcomer@example.test')
            ->and($html)->not->toContain('aria-label="Revoke the invitation to newcomer@example.test"');
    });

    it('lets a link for an organization create a membership in that organization even when the same address is invited to two', function () {
        $other = Organization::factory()->create(['name' => 'Globex']);

        opInvIssue($this, $this->operator, $this->org, 'both@example.test')->assertRedirect();
        opInvIssue($this, $this->operator, $other, 'both@example.test')->assertRedirect();
        opInvDrainQueue();

        $tokens = collect($this->sent)->map(fn ($message) => opInvTokenIn($message));
        expect($tokens)->toHaveCount(2);
        auth()->logout();
        $this->flushSession();

        // The first acceptance creates the account; the other link then finds the address taken and is a dead link.
        $this->post(route('invitation.register', $tokens[0]), regInvForm())->assertRedirect();
        $user = User::where('email', 'both@example.test')->sole();
        auth()->logout();
        $this->flushSession();
        $this->post(route('invitation.register', $tokens[1]), regInvForm())->assertRedirect(route('invitation.show', $tokens[1]));
        $this->get(route('invitation.show', $tokens[1]))->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));

        expect(DB::table('organization_members')->where('user_id', $user->id)->pluck('organization_id')->all())->toBe([$this->org->id]);
    });

    it('cannot be accepted by an existing account: the address is taken, nothing changes for that account', function () {
        opInvIssue($this, $this->operator, $this->org, 'late@example.test')->assertRedirect();
        opInvDrainQueue();
        $token = opInvTokenIn($this->sent[0]);

        $existing = User::factory()->create(['email' => 'late@example.test', 'password' => Hash::make('Original-Passw0rd!')]);
        auth()->logout();
        $this->flushSession();

        $footprint = regInvFootprint();
        $this->post(route('invitation.register', $token), regInvForm(['name' => 'Hijack']))->assertRedirect(route('invitation.show', $token));
        $this->assertGuest();

        expect(regInvFootprint())->toBe($footprint)
            ->and(Hash::check('Original-Passw0rd!', $existing->fresh()->password))->toBeTrue()->and($existing->fresh()->name)->not->toBe('Hijack');
    });
});

// ── Structure ────────────────────────────────────────────────────────────────

describe('structure', function () {
    it('authorizes manageMembership as the first statement of both actions, before it reads the request or calls the service', function () {
        $class = new ReflectionClass(OrganizationInvitationController::class);
        $lines = file($class->getFileName());

        foreach (['store', 'destroy'] as $action) {
            $method = $class->getMethod($action);
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            expect(trim(Str::after($body, '{')))->toStartWith("\$this->authorize('manageMembership', Organization::class);", "{$action} must authorize first");
        }

        // And every public action of the controller is one of those two: nothing else is reachable through it.
        expect(collect($class->getMethods(ReflectionMethod::IS_PUBLIC))->filter(fn (ReflectionMethod $m) => $m->getDeclaringClass()->getName() === $class->getName() && ! $m->isConstructor())
            ->map(fn (ReflectionMethod $m) => $m->getName())->sort()->values()->all())->toBe(['destroy', 'store']);
    });

    it('keeps the controller from writing, locking or opening a transaction: it only calls the service', function () {
        $source = file_get_contents(app_path('Http/Controllers/Organization/OrganizationInvitationController.php'));
        // Strip comments so documentation cannot satisfy or trip the scan.
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (['DB::', '->save(', '->delete(', '->update(', '->insert(', 'create(', 'transaction', 'lockForUpdate', 'sharedLock', 'OrganizationInvitation::', 'Hash::', 'Str::random'] as $forbidden) {
            expect($code)->not->toContain($forbidden);
        }

        expect($code)->toContain('$this->invitations->issue(')->and($code)->toContain('$this->invitations->revoke(');
    });

    it('does not let the card\'s read model write or lock', function () {
        $source = file_get_contents(app_path('Services/OrganizationInvitationList.php'));
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (['->insert(', '->update(', '->delete(', '->upsert(', 'lockForUpdate', 'sharedLock', 'transaction', 'token_hash'] as $forbidden) {
            expect($code)->not->toContain($forbidden);
        }
    });
});
