<?php

/*
 * Release 1.0 (ORG-1): the portal-state fence, act by act and state by state (ORG-0 record, section 5.2). Every act is driven through the SERVICE directly,
 * with the route's gates out of the picture, and several through the real HTTP route too. A refusal is a controlled exception with a stable reason and writes
 * nothing: no user, membership, social account, invitation, approval, grant, audit row or notification. The allowed acts (the ones that take authority away)
 * are proved allowed in a Disabled Organization, because cleanup must stay possible, and a requester keeps their own existing request.
 *
 * `Organization::factory()` is Enabled by default, so the existing invitation, membership, partner-support and ticket suites are the unchanged control
 * group; the Directory-only state exists in these tests only (`->directoryOnly()`), and nothing in the application can create one until ORG-2.
 */

use App\Exceptions\OrganizationInvitationException;
use App\Exceptions\OrganizationMembershipException;
use App\Exceptions\OrganizationRelationshipException;
use App\Exceptions\PartnerSupportException;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportAccess;
use App\Services\PartnerSupportGrants;
use App\Services\ServiceRequestAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

require_once __DIR__.'/../PartnerSupport/PartnerSupportTestHelpers.php';
require_once __DIR__.'/../Auth/InvitationTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $this->operator = pilotOperator();
});

// ── Fixtures ─────────────────────────────────────────────────────────────────

/** An Organization in the named portal state ('enabled', 'disabled' or 'not_enabled'). */
function psfOrg(string $state, ?User $by = null, string $name = 'Fence Org'): Organization
{
    $factory = Organization::factory();
    $attributes = ['name' => $name];

    if ($state === 'not_enabled') {
        $factory = $factory->directoryOnly();
    } elseif ($by !== null) {
        $attributes['portal_enabled_by'] = $by->id;
    }

    if ($state === 'disabled') {
        $factory = $factory->disabled();
    }

    return $factory->create($attributes);
}

/** Put an existing Organization into a state with plain table writes (no service, so no side effect of its own). */
function psfSetState(Organization $organization, string $state): void
{
    DB::table('organizations')->where('id', $organization->id)->update(['disabled_at' => $state === 'disabled' ? now() : null]);
}

/** Run an act expecting a controlled refusal of the given class and reason. */
function psfRefused(Closure $act, string $class, string $reason): void
{
    try {
        $act();
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($class)->and($e->reason)->toBe($reason);

        return;
    }

    throw new RuntimeException('the act was not refused');
}

/** Every table a fenced act could write, so "refused" can be a comparison. */
function psfFootprint(): array
{
    return [
        'users' => DB::table('users')->count(),
        'members' => DB::table('organization_members')->get()->map(fn ($r) => (array) $r)->all(),
        'invitations' => DB::table('organization_invitations')->get()->map(fn ($r) => (array) $r)->all(),
        'organizations' => DB::table('organizations')->get()->map(fn ($r) => (array) $r)->all(),
        'social' => DB::table('social_accounts')->count(),
        'tickets' => DB::table('tickets')->count(),
        'activity' => DB::table('activity_log')->count(),
    ];
}

function psfMember(Organization $organization, string $role = 'member', array $columns = []): User
{
    $user = pilotCustomer();
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()] + $columns);

    return $user;
}

// ── Invitations ──────────────────────────────────────────────────────────────

it('issues an invitation into an Enabled organization and refuses every other state with nothing written', function () {
    $service = app(OrganizationInvitations::class);

    expect($service->issue(psfOrg('enabled', $this->operator), 'ok@example.test', $this->operator)->email)->toBe('ok@example.test');

    foreach (['disabled' => OrganizationInvitationException::PORTAL_REFUSED, 'not_enabled' => OrganizationInvitationException::PORTAL_REFUSED] as $state => $reason) {
        $org = psfOrg($state, $this->operator, "Refused {$state}");
        $before = psfFootprint();

        psfRefused(fn () => $service->issue($org, "no-{$state}@example.test", $this->operator), OrganizationInvitationException::class, $reason);

        expect(psfFootprint())->toBe($before);
    }

    Notification::assertSentOnDemandTimes(OrganizationInvitationNotification::class, 1);
});

it('refuses an invitation through the real route for a Disabled and a Directory-only organization', function (string $state) {
    $org = psfOrg($state, $this->operator);
    $before = psfFootprint();

    $this->actingAs($this->operator)->post(route('organizations.invitations.store', $org), ['email' => 'via-route@example.test'])
        ->assertSessionHasErrors('email');

    expect(psfFootprint())->toBe($before);
    Notification::assertNothingSent();
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('lets an operator revoke an invitation in every state: revocation takes authority away', function (string $state) {
    $issuer = regInvIssuer();
    $org = psfOrg($state, $issuer);
    [$invitation] = regInvOpen('revoke-me@example.test', $org, $issuer);

    expect(app(OrganizationInvitations::class)->revoke($org, $invitation->id, $this->operator))->toBeTrue()
        ->and(DB::table('organization_invitations')->where('id', $invitation->id)->value('revoked_reason'))->toBe(OrganizationInvitations::REASON_OPERATOR);
})->with(['enabled' => 'enabled', 'disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('treats a link into a Disabled or Directory-only organization as dead, and a refused acceptance writes nothing (password and provider)', function (string $state, string $detail) {
    $issuer = regInvIssuer();
    $org = psfOrg($state, $issuer);
    [$invitation, $token] = regInvOpen('invitee@example.test', $org, $issuer);
    $service = app(OrganizationInvitations::class);
    $before = regInvFootprint();

    expect($service->findOpen($token))->toBeNull();

    foreach ([
        fn () => $service->accept($token, InvitationAcceptance::withPassword('New Person', Hash::make('Str0ng!Password99'))),
        fn () => $service->acceptByHash(OrganizationInvitations::hashToken($token), InvitationAcceptance::withProvider('google', ['name' => 'New Person', 'email' => 'invitee@example.test', 'provider_id' => 'g-1'])),
    ] as $accept) {
        try {
            $accept();
            throw new RuntimeException('accepted');
        } catch (OrganizationInvitationException $e) {
            expect($e->reason)->toBe(OrganizationInvitationException::UNAVAILABLE)->and($e->detail)->toBe($detail)->and($e->getMessage())->toBe('This invitation is no longer available.');
        }
    }

    expect(regInvFootprint())->toBe($before)
        ->and(DB::table('organization_invitations')->where('id', $invitation->id)->whereNull('accepted_at')->exists())->toBeTrue();
})->with([
    'disabled' => ['disabled', 'organization_disabled'],
    'not enabled' => ['not_enabled', 'portal_not_enabled'],
]);

it('accepts into an Enabled organization exactly as before', function () {
    $issuer = regInvIssuer();
    $org = psfOrg('enabled', $issuer);
    [, $token] = regInvOpen('welcome@example.test', $org, $issuer);

    $user = app(OrganizationInvitations::class)->accept($token, InvitationAcceptance::withPassword('Welcome', Hash::make('Str0ng!Password99')));

    expect(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $user->id)->value('role'))->toBe('member');
});

// ── Membership ───────────────────────────────────────────────────────────────

it('adds, promotes and approves only in an Enabled organization, and writes nothing otherwise', function (string $state, string $reason) {
    $org = psfOrg($state, $this->operator);
    $service = app(OrganizationMembership::class);
    $newcomer = pilotCustomer();
    $member = psfMember($org);
    $admin = psfMember($org, 'admin');
    $before = psfFootprint();

    psfRefused(fn () => $service->add($org, $newcomer, 'member', $this->operator), OrganizationMembershipException::class, $reason);
    psfRefused(fn () => $service->promote($org, $member, $this->operator), OrganizationMembershipException::class, $reason);
    psfRefused(fn () => $service->approve($org, $admin, $this->operator), OrganizationMembershipException::class, $reason);

    // Even a no-op is refused: the state is asked first.
    psfRefused(fn () => $service->promote($org, $admin, $this->operator), OrganizationMembershipException::class, $reason);

    expect(psfFootprint())->toBe($before);
})->with([
    'disabled' => ['disabled', OrganizationMembershipException::PORTAL_DISABLED],
    'not enabled' => ['not_enabled', OrganizationMembershipException::PORTAL_NOT_ENABLED],
]);

it('keeps the three membership grants working in an Enabled organization', function () {
    $org = psfOrg('enabled', $this->operator);
    $service = app(OrganizationMembership::class);
    $newcomer = pilotCustomer();

    $service->add($org, $newcomer, 'member', $this->operator);
    expect($service->promote($org, $newcomer, $this->operator))->toBeTrue()
        ->and($service->approve($org, $newcomer, $this->operator))->toBeTrue();
});

it('lets an operator demote, remove and revoke an approval while Disabled: cleanup stays possible', function () {
    $org = psfOrg('enabled', $this->operator);
    $service = app(OrganizationMembership::class);
    $demotable = psfMember($org, 'admin');
    $removable = psfMember($org);
    $approved = psfMember($org, 'admin', ['admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id]);
    psfSetState($org, 'disabled');

    expect($service->demote($org, $demotable, $this->operator))->toBeTrue();
    $service->remove($org, $removable, $this->operator);
    expect($service->revokeApproval($org, $approved, $this->operator, confirmNoAdministrator: true))->toBeTrue()
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $demotable->id)->value('role'))->toBe('member')
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $removable->id)->exists())->toBeFalse()
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $approved->id)->value('admin_approved_at'))->toBeNull();
});

it('refuses the three membership grants through the real routes while Disabled', function () {
    $org = psfOrg('disabled', $this->operator);
    $member = psfMember($org);
    $admin = psfMember($org, 'admin');
    $newcomer = pilotCustomer();
    $before = psfFootprint();

    $this->actingAs($this->operator)->post(route('organizations.members.store', $org), ['user_id' => $newcomer->id, 'role' => 'member'])->assertSessionHasErrors('membership');
    $this->actingAs($this->operator)->put(route('organizations.members.role', [$org, $member]), ['role' => 'admin'])->assertSessionHasErrors('membership');
    $this->actingAs($this->operator)->post(route('organizations.members.approve', [$org, $admin]))->assertSessionHasErrors('membership');

    expect(psfFootprint())->toBe($before);
});

// ── Partner support grants ───────────────────────────────────────────────────

it('grants support only from an Enabled partner, and revoking a grant stays possible while Disabled', function () {
    $relationships = app(OrganizationRelationships::class);
    $grants = app(PartnerSupportGrants::class);
    $partner = psPaired('Partner Co');
    $relationships->designatePartner($partner, $this->operator);
    $staff = pilotCustomer();
    psJoin($staff, $partner);

    expect($grants->grant($partner, $staff, $this->operator))->toBeTrue();

    $second = pilotCustomer();
    psJoin($second, $partner);
    $relationships->disable($partner, $this->operator);
    $before = psfFootprint();

    psfRefused(fn () => $grants->grant($partner, $second, $this->operator), PartnerSupportException::class, PartnerSupportException::ORGANIZATION_DISABLED);
    expect(psfFootprint())->toBe($before);

    expect($grants->revoke($partner, $staff, $this->operator))->toBeTrue();
});

it('refuses a support grant on a Directory-only organization before it asks whether it is a partner', function () {
    $org = psfOrg('not_enabled');
    $member = psfMember($org);
    $before = psfFootprint();

    psfRefused(fn () => app(PartnerSupportGrants::class)->grant($org, $member, $this->operator), PartnerSupportException::class, PartnerSupportException::PORTAL_NOT_ENABLED);
    $this->actingAs($this->operator)->post(route('organizations.members.support.grant', [$org->id, $member->id]))->assertSessionHasErrors('support');

    expect(psfFootprint())->toBe($before);
});

// ── Partner designation and managed customers ────────────────────────────────

it('designates, links and reassigns only between Enabled organizations; removing a designation or a link stays possible', function () {
    $relationships = app(OrganizationRelationships::class);
    $partner = psPaired('Partner');
    $other = psPaired('Other Partner');
    $customer = psPaired('Customer');
    $relationships->designatePartner($partner, $this->operator);
    $relationships->designatePartner($other, $this->operator);
    $relationships->assignCustomer($customer, $partner, $this->operator);

    // Disabled customer: cannot be linked or reassigned; an unlink still works.
    psfSetState($customer, 'disabled');
    $before = psfFootprint();
    psfRefused(fn () => $relationships->assignCustomer($customer, $other, $this->operator, reassign: true), OrganizationRelationshipException::class, OrganizationRelationshipException::PORTAL_DISABLED);
    expect(psfFootprint())->toBe($before);
    expect($relationships->unlinkCustomer($customer, $this->operator))->toBeTrue();

    // Disabled partner: cannot receive a customer; its designation can be removed once it has none.
    psfSetState($customer, 'enabled');
    psfSetState($other, 'disabled');
    psfRefused(fn () => $relationships->assignCustomer($customer, $other, $this->operator), OrganizationRelationshipException::class, OrganizationRelationshipException::PORTAL_DISABLED);
    expect($relationships->removePartner($other, $this->operator))->toBeTrue();

    // A Disabled organization cannot be designated.
    psfRefused(fn () => $relationships->designatePartner($other, $this->operator), OrganizationRelationshipException::class, OrganizationRelationshipException::PORTAL_DISABLED);
});

it('refuses to designate, link or reassign a Directory-only organization, and offers it as no candidate', function () {
    $relationships = app(OrganizationRelationships::class);
    $partner = psPaired('Partner');
    $relationships->designatePartner($partner, $this->operator);
    $directoryOnly = psfOrg('not_enabled', null, 'Directory Only');
    $before = psfFootprint();

    psfRefused(fn () => $relationships->designatePartner($directoryOnly, $this->operator), OrganizationRelationshipException::class, OrganizationRelationshipException::PORTAL_NOT_ENABLED);
    psfRefused(fn () => $relationships->assignCustomer($directoryOnly, $partner, $this->operator), OrganizationRelationshipException::class, OrganizationRelationshipException::PORTAL_NOT_ENABLED);
    expect(psfFootprint())->toBe($before)
        ->and(OrganizationRelationships::eligibleCustomers($partner)->pluck('id')->all())->not->toContain($directoryOnly->id);

    $this->actingAs($this->operator)->put(route('organizations.partner.designate', $directoryOnly->id))->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->post(route('organizations.customers.link', $partner->id), ['customer_id' => $directoryOnly->id])->assertSessionHasErrors('relationship');
    expect(psfFootprint())->toBe($before);
});

it('leaves a Disabled organization out of both candidate lists', function () {
    $relationships = app(OrganizationRelationships::class);
    $partner = psPaired('Partner');
    $relationships->designatePartner($partner, $this->operator);
    $off = psPaired('Disabled Customer');
    $on = psPaired('Enabled Customer');
    $offPartner = psPaired('Disabled Partner');
    $relationships->designatePartner($offPartner, $this->operator);
    psfSetState($off, 'disabled');
    psfSetState($offPartner, 'disabled');

    $customers = OrganizationRelationships::eligibleCustomers($partner)->pluck('id')->all();
    $partners = OrganizationRelationships::eligiblePartners($on)->pluck('id')->all();

    expect($customers)->toContain($on->id)->not->toContain($off->id)
        ->and($partners)->toContain($partner->id)->not->toContain($offPartner->id);
});

// ── Service Requests ─────────────────────────────────────────────────────────

it('files a request only for an Enabled organization, and offers no other', function (string $state) {
    $org = psfOrg($state, $this->operator);
    $requester = psfMember($org);
    $access = app(ServiceRequestAccess::class);

    expect(fn () => DB::transaction(fn () => $access->attributionFor($requester, $org->id)))->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => $access->attributionFor($requester, null)))->toThrow(ValidationException::class)
        ->and($access->eligibleOrganizations($requester))->toBe([]);
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('keeps the requester\'s own existing request readable and repliable while the organization is Disabled', function () {
    pilotMode('pilot');
    $w = psWorld();
    app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']);

    $this->actingAs($w['requester'])->get(route('tickets.show', $w['ticket']))->assertOk();
    $this->actingAs($w['requester'])->post(route('tickets.replies.store', $w['ticket']), ['body' => 'Still here while the organization is disabled.'])->assertSessionHasNoErrors();

    expect(DB::table('ticket_replies')->where('ticket_id', $w['ticket']->id)->where('user_id', $w['requester']->id)->where('body', 'Still here while the organization is disabled.')->exists())->toBeTrue();

    // Disabling is not organization-wide Ticket visibility: another member of the same organization still sees nothing of it.
    $colleague = pilotCustomer();
    psJoin($colleague, $w['customer']);
    $this->actingAs($colleague)->get(route('tickets.show', $w['ticket']))->assertForbidden();

    // Disabling closed no account, removed no membership and ended no session.
    expect(DB::table('users')->whereIn('id', [$w['requester']->id])->whereNull('disabled_at')->count())->toBe(1)
        ->and(DB::table('organization_members')->where('organization_id', $w['customer']->id)->where('user_id', $w['requester']->id)->exists())->toBeTrue();
});

it('refuses partner support for or by a Disabled organization, through the same rule that decides it unlocked and locked', function () {
    pilotMode('pilot');
    $w = psWorld();

    expect(PartnerSupportAccess::allows($w['staff'], $w['ticket']))->toBeTrue();

    app(OrganizationRelationships::class)->disable($w['customer'], $w['operator']);
    expect(PartnerSupportAccess::allows($w['staff'], $w['ticket']))->toBeFalse()
        ->and(DB::transaction(fn () => PartnerSupportAccess::refusal($w['staff']->id, $w['customer']->id)))->toBe(PartnerSupportAccess::REFUSED_ORGANIZATION);

    app(OrganizationRelationships::class)->enable($w['customer'], $w['operator']);
    app(OrganizationRelationships::class)->disable($w['partner'], $w['operator']);
    expect(PartnerSupportAccess::allows($w['staff'], $w['ticket']))->toBeFalse()
        ->and(DB::transaction(fn () => PartnerSupportAccess::refusal($w['staff']->id, $w['customer']->id)))->toBe(PartnerSupportAccess::REFUSED_ORGANIZATION);
});

// ── The service holds its own authority, whatever the route did ───────────────

it('refuses every fenced act to an actor who may not manage membership or relationships, with nothing written', function (string $actorKind) {
    $actor = match ($actorKind) {
        'a customer' => pilotCustomer(),
        'a suspended operator' => tap(pilotOperator(), fn (User $u) => DB::table('users')->where('id', $u->id)->update(['disabled_at' => now()])),
        'portal.operator without crm.manage' => User::factory()->create()->givePermissionTo('portal.operator'),
        'crm.manage without portal.operator' => User::factory()->create()->givePermissionTo('crm.manage'),
    };
    $actor = User::findOrFail($actor->id);

    $org = psfOrg('enabled', $this->operator);
    $partner = psPaired('Auth Partner');
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    $customerOrg = psPaired('Auth Customer');
    $member = psfMember($org);
    $admin = psfMember($org, 'admin');
    $newcomer = pilotCustomer();
    $staff = pilotCustomer();
    psJoin($staff, $partner);
    $company = Organization::factory()->directoryOnly()->create(['name' => 'To Enable', 'created_by' => $this->operator->id]);
    $before = psfFootprint();

    $acts = [
        'issue' => fn () => app(OrganizationInvitations::class)->issue($org, 'x@example.test', $actor),
        'revoke' => fn () => app(OrganizationInvitations::class)->revoke($org, 1, $actor),
        'add' => fn () => app(OrganizationMembership::class)->add($org, $newcomer, 'member', $actor),
        'promote' => fn () => app(OrganizationMembership::class)->promote($org, $member, $actor),
        'approve' => fn () => app(OrganizationMembership::class)->approve($org, $admin, $actor),
        'grant' => fn () => app(PartnerSupportGrants::class)->grant($partner, $staff, $actor),
        'designate' => fn () => app(OrganizationRelationships::class)->designatePartner($org, $actor),
        'assign' => fn () => app(OrganizationRelationships::class)->assignCustomer($customerOrg, $partner, $actor),
        'disable' => fn () => app(OrganizationRelationships::class)->disable($org, $actor),
        'enable' => fn () => app(OrganizationRelationships::class)->enable($org, $actor),
        'initial enablement' => fn () => app(OrganizationDirectory::class)->enablePortalAccess($company, $actor),
    ];

    foreach ($acts as $name => $act) {
        expect($act)->toThrow(AuthorizationException::class);
    }

    expect(psfFootprint())->toBe($before)
        ->and(DB::table('organizations')->where('id', $company->id)->value('portal_enabled_at'))->toBeNull();
})->with(['a customer', 'a suspended operator', 'portal.operator without crm.manage', 'crm.manage without portal.operator']);

// ── No new Directory-only portal capability ──────────────────────────────────

it('gives a Directory-only organization no capability through any operator route or service, and shows a customer no trace of it', function () {
    pilotMode('pilot');
    $directoryOnly = psfOrg('not_enabled', null, 'Directory Only');
    $someone = pilotCustomer();
    $before = psfFootprint();

    foreach ([
        fn () => $this->actingAs($this->operator)->post(route('organizations.invitations.store', $directoryOnly), ['email' => 'nobody@example.test']),
        fn () => $this->actingAs($this->operator)->post(route('organizations.members.store', $directoryOnly), ['user_id' => $someone->id, 'role' => 'member']),
        fn () => $this->actingAs($this->operator)->put(route('organizations.partner.designate', $directoryOnly->id)),
        fn () => $this->actingAs($this->operator)->post(route('organizations.members.support.grant', [$directoryOnly->id, $someone->id])),
    ] as $attempt) {
        $attempt();
    }

    expect(psfFootprint())->toBe($before);

    // A customer: no listing, no page, no candidate, no way to file for it.
    $theirOrg = psfOrg('enabled', $this->operator, 'Their Org');
    DB::table('organization_members')->insert(['organization_id' => $theirOrg->id, 'user_id' => $someone->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($someone);
    expect(Organization::query()->pluck('id')->all())->toBe([$theirOrg->id])
        ->and(app(ServiceRequestAccess::class)->eligibleOrganizations($someone))->toBe([['id' => $theirOrg->id, 'name' => 'Their Org']]);
    $this->get(route('organizations.show', $directoryOnly))->assertNotFound();

    // And the preflight stays clean: nothing above created portal data on the row.
    expect(app(OrganizationPreflight::class)->report()['portalState']['violations']['directoryOnlyWithMembers'])->toBe([]);
});

it('says so, from the rows, when a Directory-only organization has portal data (the invariant no constraint can state)', function () {
    $directoryOnly = psfOrg('not_enabled', null, 'Broken Directory Row');
    $member = pilotCustomer();
    DB::table('organization_members')->insert(['organization_id' => $directoryOnly->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    [$invitation] = regInvOpen('stranded@example.test', $directoryOnly, regInvIssuer());

    $report = app(OrganizationPreflight::class)->report();

    expect($report['portalState']['violations']['directoryOnlyWithMembers'])->toBe([$directoryOnly->id])
        ->and($report['portalState']['violations']['directoryOnlyWithInvitations'])->toBe([$directoryOnly->id])
        ->and($report['portalState']['notEnabled'])->toBe(1)
        ->and(collect($report['failures'])->filter(fn ($f) => str_contains($f, 'directoryOnlyWithMembers'))->count())->toBe(1)
        // A Directory-only row is not "an organization without an approved administrator": it has no portal to administer.
        ->and($report['adminApproval']['organizationsWithoutApprovedAdministrator'])->not->toContain($directoryOnly->id);
});

it('counts only organizations with portal access on the dashboard', function () {
    $operator = $this->operator;
    Organization::factory()->create();
    Organization::factory()->disabled()->create();
    Organization::factory()->directoryOnly()->create();

    $this->actingAs($operator)->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('crmSummary.orgs', 1));
});

// ── R-2: the same refusals through the real HTTP routes ──────────────────────
//
// The service tests above prove each decision under the lock; these prove that the ROUTES reach it, answer in the route's own way (a redirect with an
// error, the one invalid page, a 404, never a 500), and leave nothing behind. A Directory-only Organization cannot be created by the application until
// ORG-2 and the database forbids a partner, a managed customer or a Disabled row without a portal, so where a combination cannot exist legitimately the
// row is written as a plain TEST FIXTURE (a defensive case: what the fence does if a stray row ever appeared), never through a production mechanism.

/** A stray Directory-only or Disabled Organization carrying one open invitation, written as fixtures. @return array{0: Organization, 1: OrganizationInvitation, 2: string} */
function psfStrayInvitation(string $state, User $by): array
{
    $org = psfOrg($state, $by);
    [$invitation, $token] = regInvOpen('route-invitee@example.test', $org, regInvIssuer());

    return [$org, $invitation, $token];
}

function psfSsoProvider(string $email = 'route-invitee@example.test'): void
{
    Socialite::fake('google', SocialiteUser::fake(['email_verified' => true, 'id' => 'provider-psf', 'name' => 'Provider Person', 'email' => $email]));
}

/** The whole of what an acceptance (password or provider) or a filing could write, for the two footprints together. */
function psfWriteSet(): array
{
    return psfFootprint() + regInvFootprint() + ['files' => Storage::disk('local')->allFiles()];
}

it('answers the password invitation page and its POST with the one invalid page when the Organization is Disabled or Directory-only, and writes nothing', function (string $state) {
    [, $invitation, $token] = psfStrayInvitation($state, $this->operator);
    $before = psfWriteSet();

    $this->get(route('invitation.show', $token))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid')->missing('token')->missing('invitation'));

    $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));

    $this->assertGuest();
    expect(psfWriteSet())->toEqual($before)
        ->and(User::where('email', 'route-invitee@example.test')->exists())->toBeFalse()
        ->and(DB::table('organization_invitations')->where('id', $invitation->id)->whereNull('accepted_at')->exists())->toBeTrue();
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('refuses to start SSO from the link of a Disabled or Directory-only Organization, with no stored context, and contacts no provider', function (string $state) {
    [, , $token] = psfStrayInvitation($state, $this->operator);
    psfSsoProvider();
    $before = psfWriteSet();

    $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'This invitation is no longer available.'])
        ->assertSessionMissing('oauth_context');

    expect(psfWriteSet())->toEqual($before);
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('refuses the SSO callback of an invitation whose Organization is Disabled or Directory-only, creating no user, social account or membership', function (string $state) {
    [, $invitation] = psfStrayInvitation($state, $this->operator);
    psfSsoProvider();
    $before = psfWriteSet();

    // A context that was valid when it started, as a link opened before the Organization changed state would have left it.
    $this->withSession(['oauth_context' => ['intent' => 'invitation', 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => $invitation->token_hash, 'startedAt' => now()->toIso8601String()]])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'This invitation is no longer available.'])
        ->assertSessionMissing('oauth_context');

    $this->assertGuest();
    expect(psfWriteSet())->toEqual($before)
        ->and(DB::table('social_accounts')->count())->toBe(0);
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('kills the links of an Organization that is disabled through the service, on every route, and re-enabling revives none of them', function () {
    $org = psfOrg('enabled', $this->operator);
    [, $token] = regInvOpen('route-invitee@example.test', $org, regInvIssuer());
    psfSsoProvider();

    $this->get(route('invitation.show', $token))->assertInertia(fn (Assert $page) => $page->component('auth/invitation-register'));

    app(OrganizationRelationships::class)->disable($org, $this->operator);
    app(OrganizationRelationships::class)->enable($org, $this->operator);
    $before = psfWriteSet();

    $this->get(route('invitation.show', $token))->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
    $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));
    $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))->assertSessionHasErrors(['email' => 'This invitation is no longer available.']);

    $this->assertGuest();
    expect(psfWriteSet())->toEqual($before)
        ->and(DB::table('organization_invitations')->where('revoked_reason', OrganizationInvitations::REASON_ORGANIZATION_DISABLED)->count())->toBe(1);
});

it('refuses a support grant through the route while the partner is Disabled, and still lets the operator revoke one', function () {
    $partner = psPaired('Partner Route');
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    $granted = psfMember($partner);
    $candidate = psfMember($partner);
    app(PartnerSupportGrants::class)->grant($partner, $granted, $this->operator);
    psfSetState($partner, 'disabled');
    $before = psfFootprint();

    $this->actingAs($this->operator)->post(route('organizations.members.support.grant', [$partner->id, $candidate->id]))->assertSessionHasErrors('support');
    expect(psfFootprint())->toBe($before);

    $this->actingAs($this->operator)->delete(route('organizations.members.support.revoke', [$partner->id, $granted->id]))->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('organization_members')->where('organization_id', $partner->id)->where('user_id', $granted->id)->value('support_granted_at'))->toBeNull();
});

it('refuses partner designation and customer links through the routes while either side is Disabled, and still lets the operator unlink and remove', function () {
    $relationships = app(OrganizationRelationships::class);
    $partner = psPaired('Route Partner');
    $relationships->designatePartner($partner, $this->operator);
    $linked = psPaired('Route Linked');
    $relationships->assignCustomer($linked, $partner, $this->operator);
    $customer = psPaired('Route Customer');
    $offPartner = psPaired('Route Disabled Partner');
    $relationships->designatePartner($offPartner, $this->operator);
    $offCustomer = psPaired('Route Disabled Customer');
    $notPartner = psPaired('Route Disabled Candidate');
    foreach ([$offPartner, $offCustomer, $notPartner, $linked] as $off) {
        psfSetState($off, 'disabled');
    }
    $before = psfFootprint();

    $this->actingAs($this->operator)->put(route('organizations.partner.designate', $notPartner->id))->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->post(route('organizations.customers.link', $partner->id), ['customer_id' => $offCustomer->id])->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->post(route('organizations.customers.link', $offPartner->id), ['customer_id' => $customer->id])->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $customer->id), ['partner_id' => $offPartner->id])->assertSessionHasErrors('relationship');
    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $offCustomer->id), ['partner_id' => $partner->id])->assertSessionHasErrors('relationship');
    expect(psfFootprint())->toBe($before);

    // Cleanup stays possible: a Disabled customer is unlinked, and a Disabled partner without customers loses its designation.
    $this->actingAs($this->operator)->delete(route('organizations.manager.unlink', $linked->id))->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->operator)->delete(route('organizations.partner.remove', $offPartner->id))->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('organizations')->where('id', $linked->id)->value('managed_by_organization_id'))->toBeNull()
        ->and((int) DB::table('organizations')->where('id', $offPartner->id)->value('can_manage_customers'))->toBe(0);
});

it('refuses administrator promotion and approval through the routes for a Directory-only Organization (and for adding a member), writing nothing', function () {
    $org = psfOrg('not_enabled', null, 'Directory Route Org');
    // Fixtures: the invariant says a Directory-only Organization has no members, so these rows exist only to be refused.
    $member = psfMember($org);
    $admin = psfMember($org, 'admin');
    $newcomer = pilotCustomer();
    $before = psfFootprint();

    $this->actingAs($this->operator)->put(route('organizations.members.role', [$org, $member]), ['role' => 'admin'])->assertSessionHasErrors('membership');
    $this->actingAs($this->operator)->post(route('organizations.members.approve', [$org, $admin]))->assertSessionHasErrors('membership');
    $this->actingAs($this->operator)->post(route('organizations.members.store', $org), ['user_id' => $newcomer->id, 'role' => 'admin'])->assertSessionHasErrors('membership');
    $this->actingAs($this->operator)->post(route('organizations.members.store', $org), ['user_id' => $newcomer->id, 'role' => 'member'])->assertSessionHasErrors('membership');

    expect(psfFootprint())->toBe($before)
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('role', 'admin')->whereNotNull('admin_approved_at')->exists())->toBeFalse();
});

it('keeps every authority-removing route working for a Disabled or Directory-only Organization: demote, remove, revoke approval, revoke invitation', function (string $state) {
    $org = psfOrg($state, $this->operator);
    $demotable = psfMember($org, 'admin');
    $removable = psfMember($org);
    $approved = psfMember($org, 'admin', ['admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id]);
    [$invitation] = regInvOpen('revoke-by-route@example.test', $org, regInvIssuer());

    $this->actingAs($this->operator)->put(route('organizations.members.role', [$org, $demotable]), ['role' => 'member'])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->operator)->delete(route('organizations.members.destroy', [$org, $removable]))->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->operator)->delete(route('organizations.members.approval.revoke', [$org, $approved]), ['confirm_no_administrator' => true])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->operator)->delete(route('organizations.invitations.destroy', [$org, $invitation->id]))->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $demotable->id)->value('role'))->toBe('member')
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $removable->id)->exists())->toBeFalse()
        ->and(DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $approved->id)->value('admin_approved_at'))->toBeNull()
        ->and(DB::table('organization_invitations')->where('id', $invitation->id)->value('revoked_reason'))->toBe(OrganizationInvitations::REASON_OPERATOR);
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('refuses to create a Service Request for a Disabled or Directory-only Organization, named or inferred, and writes nothing', function (string $state) {
    Storage::fake('local');
    $org = psfOrg($state, $this->operator);
    $requester = psfMember($org);
    $before = psfWriteSet();
    $payload = ['title' => 'Printer offline', 'description' => 'It stopped.', 'category' => 'General', 'priority' => 'low'];

    // The only organization the requester has is not available: inferred (no choice sent) and named (the organization's own id) are both refused.
    $this->actingAs($requester)->post(route('tickets.store'), $payload)->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_LINKED]);
    $this->actingAs($requester)->post(route('tickets.store'), $payload + ['organization_id' => $org->id])->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);

    expect(psfWriteSet())->toEqual($before)
        ->and(Ticket::count())->toBe(0);
    Notification::assertNothingSent();
})->with(['disabled' => 'disabled', 'not enabled' => 'not_enabled']);

it('refuses a partner support request for a Disabled customer or a Disabled partner through the route, and a requester keeps the existing one', function (string $which, int $status) {
    pilotMode('pilot');
    $w = psWorld();
    app(OrganizationRelationships::class)->disable($which === 'customer' ? $w['customer'] : $w['partner'], $w['operator']);
    $before = psfFootprint();

    $response = $this->actingAs($w['staff'])->post(route('support.store'), [
        'organization_id' => $w['customer']->id, 'requester_id' => $w['requester']->id,
        'title' => 'Printer offline', 'description' => 'It stopped printing this morning.', 'category' => 'General', 'priority' => 'low',
    ]);

    // The support routes' contract: a Disabled customer is the one 422 on `organization_id`; a Disabled PARTNER leaves its staff no effective grant at all, which is
    // the 404 of every support route (nothing confirms an organization is supported).
    $status === 404 ? $response->assertNotFound() : $response->assertSessionHasErrors('organization_id');

    expect(psfFootprint())->toBe($before);
    $this->actingAs($w['requester'])->get(route('tickets.show', $w['ticket']))->assertOk();
})->with(['a Disabled customer' => ['customer', 302], 'a Disabled partner' => ['partner', 404]]);

it('proves the invariant that keeps a Directory-only row away from every partner and customer route: the database refuses it', function () {
    $partner = psPaired('Invariant Partner');
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    $customer = psPaired('Invariant Customer');
    app(OrganizationRelationships::class)->assignCustomer($customer, $partner, $this->operator);

    foreach ([$partner, $customer] as $org) {
        try {
            DB::table('organizations')->where('id', $org->id)->update(['portal_enabled_at' => null, 'portal_enabled_by' => null]);
            $code = null;
        } catch (QueryException $e) {
            $code = (int) $e->errorInfo[1];
        }

        expect($code)->toBe(4025, "{$org->name} must not be able to become Directory-only while it is a partner or a managed customer");
    }
});
