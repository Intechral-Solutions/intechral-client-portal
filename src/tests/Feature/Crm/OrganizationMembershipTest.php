<?php

use App\Exceptions\OrganizationMembershipException;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\CrmService;
use App\Services\OrganizationAuthority;
use App\Services\OrganizationMembership;
use App\Services\OrganizationPreflight;
use App\Shared\Pilot\CustomerPilotGate;
use App\Shared\Pilot\CustomerSurfaceMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Release 1.0 (MO-2A1): operator-side organization membership and the explicit approval of an administrator.
 *
 * ROLE IS NOT APPROVAL. `role = admin` has never granted anything. An administrator is APPROVED only by an operator's explicit,
 * audited act, never by promoting, never automatically, and a demotion always clears it. These tests cover that distinction, the
 * canonical authority predicate, the last-approved-administrator rule, the exceptional operator-only revocation, the policy
 * (portal.operator AND crm.manage, in the service as well as the routes), the operator screen, the audit trail, and that none of
 * it gives anyone access to anything. The races, which need real second connections, are in
 * OrganizationMembershipConcurrencyTest and the schema in OrganizationAdminApprovalMigrationTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

function memCustomer(array $attributes = []): User
{
    return User::factory()->create($attributes)->assignRole('user');
}

/** An account that is an operator by capability only: no `operator` role, so the tenant scopes treat it as a customer. */
function memCustomOperator(array $permissions = ['portal.operator', 'crm.manage']): User
{
    $role = Role::create(['name' => 'directory-lead-'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    return User::factory()->create()->assignRole($role);
}

function memOrg(string $name = 'Acme'): Organization
{
    return Organization::factory()->create(['name' => $name, 'owner_id' => User::factory()->create()->id]);
}

/** Fixture rows are written directly, as the historical data and the previous release's code write them. */
function memAttach(Organization $organization, User $user, string $role = 'member', ?User $approvedBy = null): void
{
    $now = now()->startOfSecond();   // one instant for all three timestamps, as the service writes them

    DB::table('organization_members')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'admin_approved_at' => $approvedBy ? $now : null,
        'admin_approved_by' => $approvedBy?->id,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

/** A half-written approval record, the way damage (never this release) would leave it: exactly one of the two columns. */
function memHalfApprove(Organization $organization, User $user, string $which, User $approver): void
{
    DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $user->id)->update(
        $which === 'time-only'
            ? ['admin_approved_at' => now()->startOfSecond(), 'admin_approved_by' => null]
            : ['admin_approved_at' => null, 'admin_approved_by' => $approver->id]
    );
}

function memRow(Organization $organization, User $user): ?object
{
    return DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
}

/** @return list<string> */
function memEvents(Organization $organization): array
{
    return Activity::where('subject_type', Organization::class)->where('subject_id', $organization->id)->orderBy('id')->pluck('description')->all();
}

function memRefusal(callable $action): OrganizationMembershipException
{
    try {
        $action();
    } catch (OrganizationMembershipException $exception) {
        return $exception;
    }

    throw new RuntimeException('The operation was expected to be refused.');
}

function memService(): OrganizationMembership
{
    return app(OrganizationMembership::class);
}

// ── Historical rows, approval, eligibility ───────────────────────────────────

it('leaves every historical administrator row unapproved: role is not approval', function () {
    $organization = memOrg();
    $historical = memCustomer();
    memAttach($organization, $historical, 'admin');

    expect(memRow($organization, $historical))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null, 'admin_approved_by' => null])
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $historical->id))->toBeFalse()
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([])
        ->and($organization->hasAdminRole($historical))->toBeTrue('hasAdminRole is the ROLE only; it grants and implies nothing');
});

it('approves an active customer administrator, stamping who and when, once', function () {
    $organization = memOrg();
    $admin = memCustomer();
    memAttach($organization, $admin, 'admin');

    expect(memService()->approve($organization, $admin, $this->operator))->toBeTrue();

    $row = memRow($organization, $admin);
    expect($row->admin_approved_at)->not->toBeNull()
        ->and((int) $row->admin_approved_by)->toBe($this->operator->id)
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $admin->id))->toBeTrue()
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$admin->id]);

    $stamp = $row->admin_approved_at;
    expect(memService()->approve($organization, $admin, $this->operator))->toBeFalse('a repeat is a no-op')
        ->and(memRow($organization, $admin)->admin_approved_at)->toBe($stamp, 'the original stamp is kept')
        ->and(memEvents($organization))->toBe([OrganizationMembership::APPROVED], 'and logs nothing more');
});

it('refuses to approve anything but an active, non-operator administrator', function () {
    $organization = memOrg();
    $service = memService();

    $plainMember = memCustomer();
    $suspendedAdmin = memCustomer(['disabled_at' => now()]);
    $operatorByRole = User::factory()->create()->assignRole('operator');
    $operatorByCapability = memCustomOperator();
    $outsider = memCustomer();
    memAttach($organization, $plainMember, 'member');
    memAttach($organization, $suspendedAdmin, 'admin');
    memAttach($organization, $operatorByRole, 'admin');
    memAttach($organization, $operatorByCapability, 'admin');

    expect(memRefusal(fn () => $service->approve($organization, $plainMember, $this->operator))->reason)->toBe(OrganizationMembershipException::NOT_ADMIN_ROLE)
        ->and(memRefusal(fn () => $service->approve($organization, $suspendedAdmin, $this->operator))->reason)->toBe(OrganizationMembershipException::ACCOUNT_SUSPENDED)
        ->and(memRefusal(fn () => $service->approve($organization, $operatorByRole, $this->operator))->reason)->toBe(OrganizationMembershipException::OPERATOR_ACCOUNT)
        ->and(memRefusal(fn () => $service->approve($organization, $operatorByCapability, $this->operator))->reason)->toBe(OrganizationMembershipException::OPERATOR_ACCOUNT)
        ->and(memRefusal(fn () => $service->approve($organization, $outsider, $this->operator))->reason)->toBe(OrganizationMembershipException::NOT_MEMBER);

    expect(DB::table('organization_members')->whereNotNull('admin_approved_at')->count())->toBe(0)
        ->and(memEvents($organization))->toBe([]);
});

it('never counts an approved row whose account has become an operator or been suspended, or whose role is no longer admin', function () {
    $organization = memOrg();
    $becameOperator = memCustomer();
    $suspended = memCustomer();
    $demotedByOldCode = memCustomer();
    memAttach($organization, $becameOperator, 'admin', $this->operator);
    memAttach($organization, $suspended, 'admin', $this->operator);
    memAttach($organization, $demotedByOldCode, 'admin', $this->operator);

    $becameOperator->assignRole('operator');
    $suspended->forceFill(['disabled_at' => now()])->save();
    // What the previous release does: it changes the role and knows nothing of the approval, which stays behind.
    DB::table('organization_members')->where('user_id', $demotedByOldCode->id)->update(['role' => 'member']);

    expect(memRow($organization, $demotedByOldCode)->admin_approved_at)->not->toBeNull('the stale approval is still there ...')
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([], '... and is worth nothing');
});

it('treats the Spatie catalogue, the owner and the managing partner as irrelevant to who is an approved administrator', function () {
    $organization = memOrg('Customer');
    $partner = memOrg('Partner');
    DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
    DB::table('organizations')->where('id', $organization->id)->update(['managed_by_organization_id' => $partner->id]);

    $privileged = memCustomer();
    $privileged->givePermissionTo(['org.admin', 'org.invite', 'org.manage_roles', 'users.admin', 'crm.manage']);
    memAttach($organization, $privileged, 'admin');
    $owner = User::find($organization->owner_id);
    memAttach($organization, $owner, 'member');
    $partnerAdmin = memCustomer();
    memAttach($partner, $partnerAdmin, 'admin', $this->operator);

    expect(OrganizationAuthority::isApprovedAdmin($organization->id, $privileged->id))->toBeFalse('permissions do not approve')
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $owner->id))->toBeFalse('ownership does not approve')
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $partnerAdmin->id))->toBeFalse('an approved partner administrator is not an administrator of its customer')
        ->and(OrganizationAuthority::isApprovedAdmin($partner->id, $partnerAdmin->id))->toBeTrue();
});

// ── Add ──────────────────────────────────────────────────────────────────────

it('adds an active account, refuses an existing member without touching them, and refuses a suspended account', function () {
    $organization = memOrg();
    $service = memService();
    $newcomer = memCustomer();
    $admin = memCustomer();
    $suspended = memCustomer(['disabled_at' => now()]);
    memAttach($organization, $admin, 'admin', $this->operator);
    $before = memRow($organization, $admin);

    $service->add($organization, $newcomer, 'member', $this->operator);
    expect(memRow($organization, $newcomer))->toMatchObject(['role' => 'member', 'admin_approved_at' => null]);

    // Adding as 'member' used to rewrite the pivot of an existing administrator, silently demoting them.
    expect(memRefusal(fn () => $service->add($organization, $admin, 'member', $this->operator))->reason)->toBe(OrganizationMembershipException::ALREADY_MEMBER)
        ->and(memRefusal(fn () => $service->add($organization, $newcomer, 'admin', $this->operator))->reason)->toBe(OrganizationMembershipException::ALREADY_MEMBER)
        ->and(memRow($organization, $admin))->toEqual($before, 'the existing membership is exactly as it was')
        ->and(memRow($organization, $newcomer)->role)->toBe('member')
        ->and(memRefusal(fn () => $service->add($organization, $suspended, 'member', $this->operator))->reason)->toBe(OrganizationMembershipException::ACCOUNT_SUSPENDED)
        ->and(memRow($organization, $suspended))->toBeNull()
        ->and(fn () => $service->add($organization, $newcomer, 'owner', $this->operator))->toThrow(InvalidArgumentException::class);
});

it('adds an administrator without approving them', function () {
    $organization = memOrg();
    $newcomer = memCustomer();

    memService()->add($organization, $newcomer, 'admin', $this->operator);

    expect(memRow($organization, $newcomer))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null, 'admin_approved_by' => null])
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $newcomer->id))->toBeFalse();
});

// ── Promote, demote, remove ──────────────────────────────────────────────────

it('promotes without approving and never restores an approval', function () {
    $organization = memOrg();
    $member = memCustomer();
    memAttach($organization, $member, 'member');
    // A stale approval left on a member row by older code must not come back to life when the row is promoted.
    DB::table('organization_members')->where('user_id', $member->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id]);

    expect(memService()->promote($organization, $member, $this->operator))->toBeTrue()
        ->and(memRow($organization, $member))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null, 'admin_approved_by' => null])
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $member->id))->toBeFalse()
        ->and(memService()->promote($organization, $member, $this->operator))->toBeFalse('promoting an administrator is a no-op')
        ->and(memEvents($organization))->toBe([OrganizationMembership::PROMOTED]);
});

it('demotes and clears the approval in the same step, so a re-promotion does not revive authority', function () {
    $organization = memOrg();
    $first = memCustomer();
    $second = memCustomer();
    memAttach($organization, $first, 'admin', $this->operator);
    memAttach($organization, $second, 'admin', $this->operator);

    expect(memService()->demote($organization, $first, $this->operator))->toBeTrue()
        ->and(memRow($organization, $first))->toMatchObject(['role' => 'member', 'admin_approved_at' => null, 'admin_approved_by' => null]);

    memService()->promote($organization, $first, $this->operator);

    expect(memRow($organization, $first))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null, 'admin_approved_by' => null])
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $first->id))->toBeFalse('the old approval is gone for good')
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$second->id]);
});

it('removes the membership only: the account is neither suspended nor deleted', function () {
    $organization = memOrg();
    $member = memCustomer();
    memAttach($organization, $member, 'member');

    memService()->remove($organization, $member, $this->operator);

    expect(memRow($organization, $member))->toBeNull()
        ->and(User::find($member->id)->isSuspended())->toBeFalse()
        ->and(memEvents($organization))->toBe([OrganizationMembership::REMOVED])
        ->and(memRefusal(fn () => memService()->remove($organization, $member, $this->operator))->reason)->toBe(OrganizationMembershipException::NOT_MEMBER);
});

// ── Last approved administrator ──────────────────────────────────────────────

it('refuses an ordinary demotion or removal of the last active approved administrator and changes nothing', function () {
    $organization = memOrg();
    $only = memCustomer();
    $unapproved = memCustomer();
    memAttach($organization, $only, 'admin', $this->operator);
    memAttach($organization, $unapproved, 'admin');
    $audited = Activity::count();
    $before = DB::table('organization_members')->orderBy('id')->get()->all();

    expect(memRefusal(fn () => memService()->demote($organization, $only, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN)
        ->and(memRefusal(fn () => memService()->remove($organization, $only, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN)
        ->and(DB::table('organization_members')->orderBy('id')->get()->all())->toEqual($before, 'rolled back completely')
        ->and(Activity::count())->toBe($audited, 'and nothing was audited');

    // A second approved administrator lifts it; an unapproved administrator never counted and can always go.
    memService()->approve($organization, $unapproved, $this->operator);
    memService()->demote($organization, $only, $this->operator);
    expect(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$unapproved->id])
        ->and(memRefusal(fn () => memService()->remove($organization, $unapproved, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN);
});

it('lets any member go while the organization has no approved administrator: that state is valid and operator-managed', function () {
    $organization = memOrg();
    $historicalAdmin = memCustomer();
    $member = memCustomer();
    memAttach($organization, $historicalAdmin, 'admin');
    memAttach($organization, $member, 'member');

    memService()->demote($organization, $historicalAdmin, $this->operator);
    memService()->remove($organization, $member, $this->operator);
    memService()->remove($organization, $historicalAdmin, $this->operator);

    expect(DB::table('organization_members')->where('organization_id', $organization->id)->count())->toBe(0);
});

it('does not count a suspended approved administrator, and keeps their approval across suspension and reactivation', function () {
    $organization = memOrg();
    $active = memCustomer();
    $other = memCustomer();
    memAttach($organization, $active, 'admin', $this->operator);
    memAttach($organization, $other, 'admin', $this->operator);
    $lifecycle = app(AccountLifecycleService::class);

    // Suspension is never blocked by the organization rule, even when it leaves no active approved administrator.
    expect($lifecycle->suspend($other, $this->operator, 'offboarding'))->toBeTrue()
        ->and($lifecycle->suspend($active, $this->operator, 'offboarding'))->toBeTrue()
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([]);

    $lifecycle->reactivate($active, $this->operator);

    expect(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$active->id], 'reactivation keeps the approval')
        ->and(memRow($organization, $active)->admin_approved_by)->not->toBeNull()
        ->and(memRefusal(fn () => memService()->demote($organization, $active, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN);
});

it('does not let an approved operator-held row stand in for a missing customer administrator', function () {
    $organization = memOrg();
    $customerAdmin = memCustomer();
    $becameOperator = memCustomer();
    memAttach($organization, $customerAdmin, 'admin', $this->operator);
    memAttach($organization, $becameOperator, 'admin', $this->operator);
    $becameOperator->assignRole('operator');

    expect(memRefusal(fn () => memService()->remove($organization, $customerAdmin, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN);
});

// ── Explicit, operator-only revocation ───────────────────────────────────────

it('revokes an approval but keeps the membership and the role, and audits it', function () {
    $organization = memOrg();
    $a = memCustomer();
    $b = memCustomer();
    memAttach($organization, $a, 'admin', $this->operator);
    memAttach($organization, $b, 'admin', $this->operator);

    expect(memService()->revokeApproval($organization, $a, $this->operator))->toBeTrue()
        ->and(memRow($organization, $a))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null, 'admin_approved_by' => null])
        ->and(memService()->revokeApproval($organization, $a, $this->operator))->toBeFalse('a repeat changes nothing')
        ->and(memEvents($organization))->toBe([OrganizationMembership::APPROVAL_REVOKED]);
});

it('refuses a revocation that would leave no approved administrator unless the operator confirms, and logs that under its own event', function () {
    $organization = memOrg();
    $only = memCustomer();
    memAttach($organization, $only, 'admin', $this->operator);

    expect(memRefusal(fn () => memService()->revokeApproval($organization, $only, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVAL_UNCONFIRMED)
        ->and(memRow($organization, $only)->admin_approved_at)->not->toBeNull()
        ->and(memEvents($organization))->toBe([]);

    expect(memService()->revokeApproval($organization, $only, $this->operator, confirmNoAdministrator: true))->toBeTrue()
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([])
        ->and(memEvents($organization))->toBe([OrganizationMembership::APPROVAL_REVOKED_LAST]);

    $entry = Activity::where('description', OrganizationMembership::APPROVAL_REVOKED_LAST)->firstOrFail();
    expect($entry->properties['leaves_no_approved_administrator'])->toBeTrue()
        ->and($entry->properties['old']['approved'])->toBeTrue()
        ->and((int) $entry->properties['old']['approved_by'])->toBe($this->operator->id);
});

it('revokes a stale approval on an account that cannot count (suspended, operator, or no longer an administrator) without needing confirmation', function () {
    $organization = memOrg();
    $counted = memCustomer();
    $stale = memCustomer();
    memAttach($organization, $counted, 'admin', $this->operator);
    memAttach($organization, $stale, 'member', $this->operator);   // an approval left behind on a member row

    expect(memService()->revokeApproval($organization, $stale, $this->operator))->toBeTrue()
        ->and(memRow($organization, $stale)->admin_approved_at)->toBeNull()
        ->and(memEvents($organization))->toBe([OrganizationMembership::APPROVAL_REVOKED], 'it did not leave the organization without an administrator');
});

// ── Authorization ────────────────────────────────────────────────────────────

it('lets only an actor holding BOTH portal.operator and crm.manage change membership, in the service as well as on the routes', function () {
    $organization = memOrg('Target');
    $partner = memOrg('Partner');
    DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
    DB::table('organizations')->where('id', $organization->id)->update(['managed_by_organization_id' => $partner->id]);

    $subject = memCustomer();
    $other = memCustomer();
    $newcomer = memCustomer();
    memAttach($organization, $subject, 'admin', $this->operator);
    memAttach($organization, $other, 'admin', $this->operator);

    $approvedAdminHere = memCustomer();
    $approvedAdminHere->givePermissionTo('crm.manage');
    memAttach($organization, $approvedAdminHere, 'admin', $this->operator);
    $owner = User::find($organization->owner_id)->givePermissionTo('crm.manage');
    memAttach($organization, $owner, 'member');
    $partnerAdmin = memCustomer();
    $partnerAdmin->givePermissionTo('crm.manage');
    memAttach($partner, $partnerAdmin, 'admin', $this->operator);
    $orgPermissions = memCustomer();
    $orgPermissions->givePermissionTo(['org.admin', 'org.invite', 'org.manage_roles', 'users.admin', 'users.manage']);

    // The `operator` ROLE NAME alone is not enough: strip the role's capabilities, then give the role to a new account.
    Role::findByName('operator')->revokePermissionTo(['portal.operator', 'crm.manage']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $operatorRoleNameOnly = User::factory()->create()->assignRole('operator');

    $actors = [
        'a customer holding crm.manage' => memCustomer()->givePermissionTo('crm.manage'),
        'an approved administrator of this very organization' => $approvedAdminHere,
        'the organization owner' => $owner,
        'an approved administrator of the managing partner' => $partnerAdmin,
        'an account with every org.* permission and users.admin' => $orgPermissions,
        'portal.operator alone' => memCustomOperator(['portal.operator']),
        'crm.manage alone' => memCustomOperator(['crm.manage']),
        'the operator role name alone' => $operatorRoleNameOnly,
        'a plain customer' => memCustomer(),
    ];

    $calls = [
        'add' => fn (User $actor) => memService()->add($organization, $newcomer, 'member', $actor),
        'promote' => fn (User $actor) => memService()->promote($organization, $subject, $actor),
        'demote' => fn (User $actor) => memService()->demote($organization, $subject, $actor),
        'remove' => fn (User $actor) => memService()->remove($organization, $subject, $actor),
        'approve' => fn (User $actor) => memService()->approve($organization, $other, $actor),
        'revokeApproval' => fn (User $actor) => memService()->revokeApproval($organization, $subject, $actor),
    ];

    $state = fn () => [DB::table('organization_members')->orderBy('id')->get()->all(), Activity::count()];
    $before = $state();

    foreach ($actors as $who => $actor) {
        foreach ($calls as $name => $call) {
            expect(fn () => $call($actor))->toThrow(AuthorizationException::class, '', "{$who} must not be able to {$name}");
        }

        expect(Gate::forUser($actor)->allows('manageMembership', Organization::class))->toBeFalse($who);
    }

    expect($state())->toEqual($before, 'nothing changed, nothing was audited');

    // The capability pair, however it was obtained, is enough: no `operator` role name is needed.
    $customOperator = memCustomOperator();
    expect(Gate::forUser($customOperator)->allows('manageMembership', Organization::class))->toBeTrue()
        ->and(memService()->add($organization, $newcomer, 'member', $customOperator))->toBeNull();
});

it('answers the membership routes with 403 to a customer who holds crm.manage and belongs to the organization, and changes nothing', function () {
    $organization = memOrg();
    $manager = memCustomer()->givePermissionTo('crm.manage');
    $subject = memCustomer();
    $newcomer = memCustomer();
    memAttach($organization, $manager, 'admin', $this->operator);
    memAttach($organization, $subject, 'admin');
    $before = DB::table('organization_members')->orderBy('id')->get()->all();
    $audited = Activity::count();

    $this->actingAs($manager);
    $this->post(route('organizations.members.store', $organization), ['user_id' => $newcomer->id, 'role' => 'admin'])->assertForbidden();
    $this->put(route('organizations.members.role', [$organization, $subject]), ['role' => 'member'])->assertForbidden();
    $this->delete(route('organizations.members.destroy', [$organization, $subject]))->assertForbidden();
    $this->post(route('organizations.members.approve', [$organization, $subject]))->assertForbidden();
    $this->delete(route('organizations.members.approval.revoke', [$organization, $manager]), ['confirm_no_administrator' => 1])->assertForbidden();

    expect(DB::table('organization_members')->orderBy('id')->get()->all())->toEqual($before)->and(Activity::count())->toBe($audited);
});

it('runs the operator routes end to end: add, promote, approve, demote, revoke, remove, each audited with its actor', function () {
    $organization = memOrg();
    $a = memCustomer();
    $b = memCustomer();
    $this->actingAs($this->operator);

    $this->post(route('organizations.members.store', $organization), ['user_id' => $a->id, 'role' => 'member'])->assertRedirect(route('organizations.show', $organization));
    $this->post(route('organizations.members.store', $organization), ['user_id' => $b->id, 'role' => 'admin'])
        ->assertSessionHas('success', "{$b->name} added as an administrator. They are not approved: an administrator holds no delegated authority until an operator approves them.");
    $this->put(route('organizations.members.role', [$organization, $a]), ['role' => 'admin'])->assertRedirect();
    expect(memRow($organization, $a)->admin_approved_at)->toBeNull();

    $this->post(route('organizations.members.approve', [$organization, $a]))->assertSessionHas('success', "{$a->name} approved as an administrator.");
    $this->post(route('organizations.members.approve', [$organization, $b]))->assertRedirect();
    $this->put(route('organizations.members.role', [$organization, $b]), ['role' => 'member'])->assertRedirect();
    $this->delete(route('organizations.members.approval.revoke', [$organization, $a]))->assertSessionHasErrors('membership');
    $this->delete(route('organizations.members.approval.revoke', [$organization, $a]), ['confirm_no_administrator' => 1])->assertRedirect();
    $this->delete(route('organizations.members.destroy', [$organization, $b]))->assertRedirect();

    expect(memEvents($organization))->toBe([
        OrganizationMembership::ADDED,
        OrganizationMembership::ADDED,
        OrganizationMembership::PROMOTED,
        OrganizationMembership::APPROVED,
        OrganizationMembership::APPROVED,
        OrganizationMembership::DEMOTED,
        OrganizationMembership::APPROVAL_REVOKED_LAST,
        OrganizationMembership::REMOVED,
    ]);
    expect(Activity::where('subject_id', $organization->id)->where('subject_type', Organization::class)->pluck('causer_id')->unique()->values()->all())->toBe([$this->operator->id]);

    $approval = Activity::where('description', OrganizationMembership::APPROVED)->orderBy('id')->first();
    expect($approval->properties['user_id'])->toBe($a->id)
        ->and($approval->properties['new']['approved_by'])->toBe($this->operator->id)
        ->and($approval->properties['new']['approved'])->toBeTrue();
});

it('answers a rule refusal with a business message, 409 for JSON, and no change', function () {
    $organization = memOrg();
    $only = memCustomer();
    memAttach($organization, $only, 'admin', $this->operator);
    $this->actingAs($this->operator);

    $this->from(route('organizations.show', $organization))->delete(route('organizations.members.destroy', [$organization, $only]))
        ->assertRedirect(route('organizations.show', $organization))
        ->assertSessionHasErrors('membership');

    $message = $this->deleteJson(route('organizations.members.destroy', [$organization, $only]))->assertStatus(409)->json('message');
    expect($message)->toContain('without an active approved administrator');
    $this->putJson(route('organizations.members.role', [$organization, $only]), ['role' => 'member'])->assertStatus(409);
    $this->postJson(route('organizations.members.store', $organization), ['user_id' => $only->id, 'role' => 'member'])->assertStatus(409);

    expect(memRow($organization, $only)->role)->toBe('admin')->and(memRow($organization, $only)->admin_approved_at)->not->toBeNull();
});

it('gives the custom-role operator the built-in operator\'s reach (R1-1): the routes bind through a tenant scope that keys on portal.operator, not the role name', function () {
    $organization = memOrg();
    $admin = memCustomer();
    memAttach($organization, $admin, 'admin');
    $lead = memCustomOperator();

    // R1-1: the three organization scopes key on `portal.operator` ({@see CustomerPilotGate::isOperator()}), so the organization
    // resolves for a custom-role operator exactly as it does for the built-in one. The capabilities (`crm.manage`) still decide.
    $this->actingAs($lead)->get(route('organizations.show', $organization))->assertOk();
    $this->actingAs($lead)->post(route('organizations.members.approve', [$organization, $admin]))->assertRedirect();

    expect((int) memRow($organization, $admin)->admin_approved_by)->toBe($lead->id);

    // Eligibility without the capability still does nothing.
    $eligibleOnly = memCustomOperator(['portal.operator']);
    $second = memCustomer();
    memAttach($organization, $second, 'admin');
    $this->actingAs($eligibleOnly)->post(route('organizations.members.approve', [$organization, $second]))->assertForbidden();
    expect(memRow($organization, $second)->admin_approved_at)->toBeNull();
});

// ── Operator screen ──────────────────────────────────────────────────────────

it('shows the operator role and approval separately, never presenting an unapproved administrator as holding authority', function () {
    $organization = memOrg();
    $approved = memCustomer(['name' => 'Alice Approved']);
    $unapproved = memCustomer(['name' => 'Ursula Unapproved']);
    $suspended = memCustomer(['name' => 'Sam Suspended']);
    $operatorMember = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
    $plain = memCustomer(['name' => 'Mia Member']);
    $second = memCustomer(['name' => 'Bob Second']);
    memAttach($organization, $approved, 'admin', $this->operator);
    memAttach($organization, $second, 'admin', $this->operator);
    memAttach($organization, $unapproved, 'admin');
    memAttach($organization, $suspended, 'admin', $this->operator);
    $suspended->forceFill(['disabled_at' => now()])->save();
    memAttach($organization, $operatorMember, 'admin');
    memAttach($organization, $plain, 'member');

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();
    $page->assertSee('The administrator role grants nothing by itself')
        ->assertSee('Approved administrator')
        ->assertSee('Not approved: holds no delegated authority.')
        ->assertSee('Approved, not in effect')
        ->assertSee('Cannot be approved: the account is an operator.')
        ->assertSee('Operator account: acts with platform authority, not through this membership.')
        ->assertSee('Approve administrator')
        ->assertSee('Revoke approval')
        ->assertSee('Admin (not approved)');
    expect(substr_count($page->getContent(), 'Approve administrator'))->toBe(1, 'only the one eligible unapproved administrator offers approval');
});

it('disables demotion and removal of the only approved administrator and asks for explicit intent before revoking it', function () {
    $organization = memOrg();
    $only = memCustomer(['name' => 'Olivia Only']);
    memAttach($organization, $only, 'admin', $this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();
    $page->assertSee('The only approved administrator: approve another before demoting or removing them.')
        ->assertSee('I understand this leaves '.$organization->name.' with no approved administrator.')
        ->assertSee('name="confirm_no_administrator"', false)
        ->assertDontSee('action="'.route('organizations.members.role', [$organization, $only]).'"', false)
        ->assertDontSee('action="'.route('organizations.members.destroy', [$organization, $only]).'"', false);

    $second = memCustomer();
    memAttach($organization, $second, 'admin', $this->operator);
    $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()
        ->assertDontSee('The only approved administrator')
        ->assertDontSee('name="confirm_no_administrator"', false)
        ->assertSee('action="'.route('organizations.members.destroy', [$organization, $only]).'"', false);
});

it('shows a customer holding crm.manage who belongs to the organization the plain member list and no controls, candidates or approvals', function () {
    $organization = memOrg();
    $viewer = memCustomer(['name' => 'Vera Viewer'])->givePermissionTo('crm.manage');
    $colleague = memCustomer(['name' => 'Colin Colleague']);
    $stranger = memCustomer(['name' => 'Sasha Stranger']);
    memAttach($organization, $viewer, 'member');
    memAttach($organization, $colleague, 'admin', $this->operator);

    $this->actingAs($viewer)->get(route('organizations.show', $organization))->assertOk()
        ->assertSee('Colin Colleague')
        ->assertDontSee('Sasha Stranger')
        ->assertDontSee('Approved administrator')
        ->assertDontSee('Revoke approval')
        ->assertDontSee('Person to add')
        ->assertDontSee('action="'.route('organizations.members.destroy', [$organization, $colleague]).'"', false);
});

// ── Grants nothing ───────────────────────────────────────────────────────────

it('gives an approved administrator no access to anything: tickets, projects, invoices, organization pages, routes or abilities', function () {
    $organization = memOrg();
    $admin = memCustomer()->givePermissionTo('crm.manage');
    $peer = memCustomer();
    memAttach($organization, $admin, 'admin');
    memAttach($organization, $peer, 'member');

    $own = Ticket::factory()->create(['user_id' => $admin->id]);
    $peersTicket = Ticket::factory()->create(['user_id' => $peer->id]);
    $project = Project::factory()->create();
    $project->members()->attach($peer, ['role' => 'member']);
    $invoice = Invoice::factory()->create(['client_id' => $peer->id]);

    $observe = function () use ($admin, $own, $peersTicket, $project, $invoice): array {
        return [
            'own ticket' => $admin->can('view', $own),
            'peer ticket' => $admin->can('view', $peersTicket),
            'project' => $admin->can('view', $project),
            'visible projects' => Project::visibleTo($admin)->count(),
            'invoice' => $admin->can('view', $invoice),
            'manage membership' => Gate::forUser($admin)->allows('manageMembership', Organization::class),
            'manage relationships' => Gate::forUser($admin)->allows('manageRelationships', Organization::class),
        ];
    };

    $routes = function () use ($admin, $organization, $peer): array {
        config(['portal.customer_surface_mode' => 'pilot']);

        return [
            'organizations' => $this->actingAs($admin)->get(route('organizations.index'))->status(),
            'organization page' => $this->actingAs($admin)->get(route('organizations.show', $organization))->status(),
            'add member' => $this->actingAs($admin)->post(route('organizations.members.store', $organization), ['user_id' => $peer->id, 'role' => 'member'])->status(),
        ];
    };

    $before = [$observe(), $routes()];
    memService()->approve($organization, $admin, $this->operator);
    expect(OrganizationAuthority::isApprovedAdmin($organization->id, $admin->id))->toBeTrue();
    $after = [$observe(), $routes()];

    expect($after)->toEqual($before)
        ->and($after[1])->toBe(['organizations' => 404, 'organization page' => 404, 'add member' => 404]);
});

it('adds no customer route: no membership route name is in any customer allowlist', function () {
    $names = collect(app('router')->getRoutes()->getRoutesByName())->keys()
        ->filter(fn (string $name) => str_starts_with($name, 'organizations.members.'))->values()->all();

    expect($names)->toContain('organizations.members.approve', 'organizations.members.approval.revoke');

    foreach ([CustomerSurfaceMode::Closed, CustomerSurfaceMode::Pilot] as $mode) {
        foreach ($names as $name) {
            expect(array_key_exists($name, CustomerPilotGate::routes($mode)))->toBeFalse("{$name} must stay out of the {$mode->value} allowlist");
        }
    }
});

// ── No direct-write path is left ─────────────────────────────────────────────

it('leaves no other writer of organization membership: the legacy CrmService methods and isAdmin() are gone', function () {
    expect(method_exists(CrmService::class, 'addMember'))->toBeFalse()
        ->and(method_exists(CrmService::class, 'updateMemberRole'))->toBeFalse()
        ->and(method_exists(CrmService::class, 'removeMember'))->toBeFalse()
        ->and(method_exists(Organization::class, 'isAdmin'))->toBeFalse()
        ->and(method_exists(Organization::class, 'hasAdminRole'))->toBeTrue();

    // Only the membership service writes the table, and the promotion of a company attaches its promoter (unchanged behaviour).
    $writers = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => preg_match('/members\(\)\s*->\s*(attach|sync|syncWithoutDetaching|detach|updateExistingPivot)\(|table\(\s*[\'"]organization_members[\'"]\s*\)[^;]*->\s*(insert|update|delete|upsert)\(/s', file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file) => str_replace(app_path().'/', '', $file->getPathname()))
        ->sort()->values()->all();

    // Project membership uses the same relation name on a different model.
    $writers = array_values(array_diff($writers, ['Services/ProjectService.php']));

    // R1-3C: the one NAMED exception besides those two is the browser suite's own cleanup (`e2e:cleanup-invitees`), which only deletes the
    // memberships of accounts it removes, in local and testing only (OrganizationInvitationFlowTest pins "delete only").
    expect($writers)->toBe(['Console/Commands/CleanupE2eInvitees.php', 'Services/CrmService.php', 'Services/OrganizationMembership.php']);
});

it('takes the organization row lock before touching the membership table', function () {
    $organization = memOrg();
    $member = memCustomer();
    memAttach($organization, $member, 'admin');

    DB::flushQueryLog();
    DB::enableQueryLog();
    memService()->approve($organization, $member, $this->operator);
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lock = $log->search(fn (string $sql) => str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update'));
    $firstMembership = $log->search(fn (string $sql) => str_contains($sql, '`organization_members`'));

    expect($lock)->not->toBeFalse()->and($firstMembership)->not->toBeFalse()->and($lock)->toBeLessThan($firstMembership);
});

// ── MO-2A1 remediation: suspended actors ─────────────────────────────────────

/** Every mutation of the service, as a closure over an actor. */
function memMutations(Organization $organization, User $subject, User $other, User $newcomer): array
{
    return [
        'add' => fn (User $actor) => memService()->add($organization, $newcomer, 'member', $actor),
        'promote' => fn (User $actor) => memService()->promote($organization, $subject, $actor),
        'demote' => fn (User $actor) => memService()->demote($organization, $subject, $actor),
        'remove' => fn (User $actor) => memService()->remove($organization, $subject, $actor),
        'approve' => fn (User $actor) => memService()->approve($organization, $other, $actor),
        'revokeApproval' => fn (User $actor) => memService()->revokeApproval($organization, $subject, $actor, confirmNoAdministrator: true),
    ];
}

it('refuses every membership mutation, approval and revocation to a SUSPENDED operator, through the service as through the routes', function () {
    $organization = memOrg();
    $subject = memCustomer();
    $other = memCustomer();
    $newcomer = memCustomer();
    memAttach($organization, $subject, 'admin', $this->operator);
    memAttach($organization, $other, 'admin');

    $suspendedRoleOperator = User::factory()->create(['disabled_at' => now()])->assignRole('operator');
    $suspendedCapabilityOperator = memCustomOperator();
    $suspendedCapabilityOperator->forceFill(['disabled_at' => now()])->save();

    $state = fn () => [DB::table('organization_members')->orderBy('id')->get()->all(), Activity::count()];
    $before = $state();

    foreach (['suspended operator role' => $suspendedRoleOperator, 'suspended capability operator' => $suspendedCapabilityOperator] as $who => $actor) {
        expect($actor->can('portal.operator'))->toBeTrue('the capabilities are still held; only the account state differs');

        foreach (memMutations($organization, $subject, $other, $newcomer) as $name => $call) {
            expect(fn () => $call($actor))->toThrow(AuthorizationException::class, '', "{$who} must not be able to {$name}");
        }

        expect(Gate::forUser($actor)->allows('manageMembership', Organization::class))->toBeFalse($who)
            ->and(Gate::forUser($actor)->allows('manageRelationships', Organization::class))->toBeFalse("{$who}: the shared boundary also closes the MO-1A relationship operations");
    }

    expect($state())->toEqual($before, 'nothing changed and nothing was audited');

    $this->actingAs($suspendedRoleOperator);
    $this->post(route('organizations.members.store', $organization), ['user_id' => $newcomer->id, 'role' => 'member'])->assertForbidden();
    $this->post(route('organizations.members.approve', [$organization, $other]))->assertForbidden();
    $this->delete(route('organizations.members.approval.revoke', [$organization, $subject]), ['confirm_no_administrator' => 1])->assertForbidden();
    expect($state())->toEqual($before);

    // Reactivation restores the ability: the boundary is the account state, not a permission change.
    $suspendedRoleOperator->forceFill(['disabled_at' => null])->save();
    expect(memService()->approve($organization, $other, $suspendedRoleOperator->fresh()))->toBeTrue();
});

it('does not stamp a suspended operator as the approver, and a suspended operator who is not the actor changes nothing', function () {
    $organization = memOrg();
    $admin = memCustomer();
    memAttach($organization, $admin, 'admin');
    $suspended = User::factory()->create(['disabled_at' => now()])->assignRole('operator');

    expect(fn () => memService()->approve($organization, $admin, $suspended))->toThrow(AuthorizationException::class)
        ->and(memRow($organization, $admin))->toMatchObject(['admin_approved_at' => null, 'admin_approved_by' => null]);

    // A SUSPENDED approver's earlier approval is a different matter: it stands (the approval is the membership's, not the approver's).
    memService()->approve($organization, $admin, $this->operator);
    $this->operator->forceFill(['disabled_at' => now()])->save();
    expect(OrganizationAuthority::isApprovedAdmin($organization->id, $admin->id))->toBeTrue();
});

// ── Operator detection (every branch of the exclusion) ───────────────────────

it('excludes an operator however the eligibility was obtained: role name alone, a direct permission, or a custom role', function () {
    $organization = memOrg();
    $admin = memCustomer();
    memAttach($organization, $admin, 'admin', $this->operator);

    $directOnly = memCustomer()->givePermissionTo('portal.operator');
    $viaCustomRole = memCustomOperator(['portal.operator']);
    $customRoleWithoutCapability = memCustomOperator(['crm.manage']);
    $customer = memCustomer();

    // The `operator` ROLE NAME alone: strip the capabilities from the role, then give it to a new account.
    Role::findByName('operator')->revokePermissionTo(['portal.operator', 'crm.manage']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $roleNameOnly = User::factory()->create()->assignRole('operator');

    $ids = OrganizationAuthority::operatorIds();

    // (Pest's toContain takes every argument as a needle, so the reasons live in the comments.)
    expect(in_array($directOnly->id, $ids, true))->toBeTrue('a direct portal.operator permission')
        ->and(in_array($viaCustomRole->id, $ids, true))->toBeTrue('portal.operator through a custom role')
        ->and(in_array($roleNameOnly->id, $ids, true))->toBeTrue('the operator role name alone')
        ->and(in_array($customRoleWithoutCapability->id, $ids, true))->toBeFalse('crm.manage without portal.operator is not eligibility')
        ->and(in_array($customer->id, $ids, true))->toBeFalse()
        ->and(in_array($admin->id, $ids, true))->toBeFalse()
        ->and(in_array($this->operator->id, $ids, true))->toBeTrue('the fixture operator keeps only the role name now, and the role name alone still excludes');

    // Each of the three, held in a membership, is neither approvable nor counted.
    foreach ([$directOnly, $viaCustomRole, $roleNameOnly] as $heldByOperator) {
        memAttach($organization, $heldByOperator, 'admin');
        expect(OrganizationAuthority::isOperator($heldByOperator->id))->toBeTrue()
            ->and(memRefusal(fn () => memService()->approve($organization, $heldByOperator, memCustomOperator()))->reason)->toBe(OrganizationMembershipException::OPERATOR_ACCOUNT);
        DB::table('organization_members')->where('user_id', $heldByOperator->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $admin->id]);
    }

    expect(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$admin->id], 'approved operator-held rows never count');
});

// ── Incomplete approval records fail closed ──────────────────────────────────

it('does not count a half-written approval record as an approved administrator, in either direction', function () {
    $organization = memOrg();
    $timeOnly = memCustomer();
    $approverOnly = memCustomer();
    $complete = memCustomer();
    memAttach($organization, $timeOnly, 'admin');
    memAttach($organization, $approverOnly, 'admin');
    memAttach($organization, $complete, 'admin', $this->operator);
    memHalfApprove($organization, $timeOnly, 'time-only', $this->operator);
    memHalfApprove($organization, $approverOnly, 'approver-only', $this->operator);

    expect(OrganizationAuthority::isApprovedAdmin($organization->id, $timeOnly->id))->toBeFalse('a time without an approver is unprovable')
        ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $approverOnly->id))->toBeFalse('an approver without a time is unprovable')
        ->and(OrganizationAuthority::approvedAdminIds($organization->id)->all())->toBe([$complete->id])
        ->and(OrganizationAuthority::approvalRecord(now(), null))->toBe(OrganizationAuthority::APPROVAL_INCOMPLETE)
        ->and(OrganizationAuthority::approvalRecord(null, 1))->toBe(OrganizationAuthority::APPROVAL_INCOMPLETE)
        ->and(OrganizationAuthority::approvalRecord(now(), 1))->toBe(OrganizationAuthority::APPROVAL_COMPLETE)
        ->and(OrganizationAuthority::approvalRecord(null, null))->toBe(OrganizationAuthority::APPROVAL_NONE);

    // So the last-approved-administrator rule is not satisfied by one either: demoting the complete one is refused.
    expect(memRefusal(fn () => memService()->demote($organization, $complete, $this->operator))->reason)->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN);
});

it('does not treat an incomplete approval as already approved: approve() refuses it, revocation clears it, and only then can it be approved', function () {
    $organization = memOrg();
    $service = memService();

    foreach (['time-only', 'approver-only'] as $which) {
        $admin = memCustomer();
        memAttach($organization, $admin, 'admin');
        memHalfApprove($organization, $admin, $which, $this->operator);
        $before = memRow($organization, $admin);
        $audited = Activity::count();

        expect(memRefusal(fn () => $service->approve($organization, $admin, $this->operator))->reason)->toBe(OrganizationMembershipException::INCOMPLETE_APPROVAL, $which)
            ->and(memRow($organization, $admin))->toEqual($before, "{$which}: unchanged")
            ->and(Activity::count())->toBe($audited);

        // The corrective path is the explicit revocation. It needs no confirmation: the record never counted.
        expect($service->revokeApproval($organization, $admin, $this->operator))->toBeTrue()
            ->and(memRow($organization, $admin))->toMatchObject(['admin_approved_at' => null, 'admin_approved_by' => null])
            ->and($service->approve($organization, $admin, $this->operator))->toBeTrue()
            ->and(OrganizationAuthority::isApprovedAdmin($organization->id, $admin->id))->toBeTrue();

        $revoked = Activity::where('description', OrganizationMembership::APPROVAL_REVOKED)->latest('id')->first();
        expect($revoked->properties['old']['approved'])->toBeFalse('it was never a complete approval')
            ->and($revoked->properties['new']['approved'])->toBeFalse();
    }
});

it('offers Revoke whenever EITHER approval column is populated, offers Approve only when neither is, and says what is wrong', function () {
    $organization = memOrg();
    $timeOnly = memCustomer(['name' => 'Tina Timeonly']);
    $approverOnly = memCustomer(['name' => 'Alan Approveronly']);
    $clean = memCustomer(['name' => 'Cleo Clean']);
    $stale = memCustomer(['name' => 'Sid Stale']);
    foreach ([$timeOnly, $approverOnly, $clean] as $user) {
        memAttach($organization, $user, 'admin');
    }
    memAttach($organization, $stale, 'member');
    memHalfApprove($organization, $timeOnly, 'time-only', $this->operator);
    memHalfApprove($organization, $approverOnly, 'approver-only', $this->operator);
    memHalfApprove($organization, $stale, 'approver-only', $this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();
    $html = $page->getContent();

    // Approve (POST) and Revoke (DELETE) share one URL, so count the controls: three rows hold approval data, one is clean.
    expect(substr_count($html, 'Revoke approval'))->toBe(3, 'Revoke for the time-only, approver-only and stale member rows')
        ->and(substr_count($html, 'Approve administrator'))->toBe(1, 'Approve only for the clean administrator');
    $page->assertSee('Incomplete approval record')
        ->assertSee('An approval time is recorded but no approver.')
        ->assertSee('An approver is recorded but no approval time.')
        ->assertSee('Has an approval record but is not an administrator');

    // And the button does what it says, for the row that used to have none.
    $this->delete(route('organizations.members.approval.revoke', [$organization, $approverOnly]))->assertSessionHas('success');
    expect(memRow($organization, $approverOnly))->toMatchObject(['admin_approved_at' => null, 'admin_approved_by' => null]);
});

// ── Audit records what was actually there ────────────────────────────────────

it('records the actual previous approver and time when a promotion clears a stale approval, and a flag only when something was cleared', function () {
    $organization = memOrg();
    $stale = memCustomer();
    $half = memCustomer();
    $clean = memCustomer();
    memAttach($organization, $stale, 'member', $this->operator);
    memAttach($organization, $half, 'member');
    memHalfApprove($organization, $half, 'time-only', $this->operator);
    memAttach($organization, $clean, 'member');
    $staleAt = memRow($organization, $stale)->admin_approved_at;
    $halfAt = memRow($organization, $half)->admin_approved_at;

    $cleared = null;
    expect(memService()->promote($organization, $stale, $this->operator, $cleared))->toBeTrue()
        ->and($cleared)->toBeTrue();
    $entry = Activity::where('description', OrganizationMembership::PROMOTED)->latest('id')->first();
    expect($entry->properties['old'])->toMatchArray(['role' => 'member', 'approved' => true, 'approved_by' => $this->operator->id, 'approved_at' => $staleAt])
        ->and($entry->properties['new'])->toMatchArray(['role' => 'admin', 'approved' => false]);

    expect(memService()->promote($organization, $half, $this->operator, $cleared))->toBeTrue()
        ->and($cleared)->toBeTrue();
    $entry = Activity::where('description', OrganizationMembership::PROMOTED)->latest('id')->first();
    expect($entry->properties['old'])->toMatchArray(['role' => 'member', 'approved' => false, 'approved_by' => null, 'approved_at' => $halfAt]);

    expect(memService()->promote($organization, $clean, $this->operator, $cleared))->toBeTrue()
        ->and($cleared)->toBeFalse('nothing to clear');
    $entry = Activity::where('description', OrganizationMembership::PROMOTED)->latest('id')->first();
    expect($entry->properties['old'])->toMatchArray(['role' => 'member', 'approved' => false, 'approved_by' => null, 'approved_at' => null]);

    expect(memService()->promote($organization, $clean, $this->operator, $cleared))->toBeFalse()
        ->and($cleared)->toBeFalse();
});

it('records the previous approval on demotion and removal, and reports clearing only when approval data was cleared', function () {
    $organization = memOrg();
    $a = memCustomer();
    $b = memCustomer();
    $unapproved = memCustomer();
    $keeper = memCustomer();
    memAttach($organization, $a, 'admin', $this->operator);
    memAttach($organization, $b, 'admin', $this->operator);
    memAttach($organization, $unapproved, 'admin');
    memAttach($organization, $keeper, 'admin', $this->operator);
    $aAt = memRow($organization, $a)->admin_approved_at;

    $cleared = null;
    expect(memService()->demote($organization, $a, $this->operator, $cleared))->toBeTrue()->and($cleared)->toBeTrue();
    $entry = Activity::where('description', OrganizationMembership::DEMOTED)->latest('id')->first();
    expect($entry->properties['old'])->toMatchArray(['role' => 'admin', 'approved' => true, 'approved_by' => $this->operator->id, 'approved_at' => $aAt])
        ->and($entry->properties['new'])->toMatchArray(['role' => 'member', 'approved' => false]);

    expect(memService()->demote($organization, $unapproved, $this->operator, $cleared))->toBeTrue()->and($cleared)->toBeFalse('there was no approval to clear');
    expect(memService()->demote($organization, $unapproved, $this->operator, $cleared))->toBeFalse()->and($cleared)->toBeFalse();

    memService()->remove($organization, $b, $this->operator);
    $entry = Activity::where('description', OrganizationMembership::REMOVED)->latest('id')->first();
    expect($entry->properties['old'])->toMatchArray(['role' => 'admin', 'approved' => true, 'approved_by' => $this->operator->id])
        ->and($entry->properties['new'])->toBeNull();
});

it('flashes only what happened: an unchanged role is said to be unchanged, and clearing is reported only when it happened', function () {
    $organization = memOrg();
    $approved = memCustomer(['name' => 'Alma Approved']);
    $unapproved = memCustomer(['name' => 'Una Unapproved']);
    $staleMember = memCustomer(['name' => 'Stan Stale']);
    $plain = memCustomer(['name' => 'Pat Plain']);
    $keeper = memCustomer();
    memAttach($organization, $approved, 'admin', $this->operator);
    memAttach($organization, $keeper, 'admin', $this->operator);
    memAttach($organization, $unapproved, 'admin');
    memAttach($organization, $staleMember, 'member', $this->operator);
    memAttach($organization, $plain, 'member');
    $this->actingAs($this->operator);
    $role = fn (User $user, string $to) => $this->put(route('organizations.members.role', [$organization, $user]), ['role' => $to]);

    $role($approved, 'member')->assertSessionHas('success', 'Member role updated. The approval was cleared.');
    $role($unapproved, 'member')->assertSessionHas('success', 'Member role updated.');
    $role($staleMember, 'member')->assertSessionHas('success', 'Stan Stale is already a member. Nothing was changed.');
    expect(memRow($organization, $staleMember)->admin_approved_by)->not->toBeNull('a no-op demotion leaves the record for the explicit revocation');

    $role($plain, 'admin')->assertSessionHas('success', 'Member role updated. They are not approved: approve them separately to record delegated authority.');
    $role($plain, 'admin')->assertSessionHas('success', 'Pat Plain is already an administrator. Nothing was changed.');
    $role($staleMember, 'admin')->assertSessionHas('success', 'Member role updated. They are not approved: approve them separately to record delegated authority. A leftover approval record was cleared.');
});

// ── Atomicity: a failing audit rolls the mutation back ───────────────────────

it('rolls back every mutation completely when its audit entry cannot be written', function () {
    $organization = memOrg();
    $subject = memCustomer();
    $other = memCustomer();
    $newcomer = memCustomer();
    $spare = memCustomer();
    memAttach($organization, $subject, 'admin', $this->operator);
    memAttach($organization, $spare, 'admin', $this->operator);   // so demoting/removing $subject passes the last-administrator rule
    memAttach($organization, $other, 'admin');

    $plain = memCustomer();
    memAttach($organization, $plain, 'member');   // a plain member, for the promotion

    $state = fn () => DB::table('organization_members')->orderBy('id')->get()->all();
    $before = $state();
    $audited = Activity::count();

    Activity::creating(fn () => throw new RuntimeException('the audit store is down'));

    $calls = memMutations($organization, $subject, $other, $newcomer);
    $calls['promote'] = fn (User $actor) => memService()->promote($organization, $plain, $actor);

    foreach ($calls as $name => $call) {
        expect(fn () => $call($this->operator))->toThrow(RuntimeException::class, 'the audit store is down', $name);
        expect($state())->toEqual($before, "{$name}: the membership change was rolled back with the audit");
    }

    expect(DB::table('activity_log')->count())->toBe($audited);
});

// ── Legitimate writes keep the timestamps consistent ─────────────────────────

it('stamps the approval and the membership with one instant, so the preflight timestamp diagnostic is never tripped by this release', function () {
    $organization = memOrg();
    $admin = memCustomer();
    $other = memCustomer();
    memAttach($organization, $admin, 'member');
    memAttach($organization, $other, 'member');

    $this->travelTo(now()->startOfSecond()->addDays(3)->addMilliseconds(500));
    memService()->promote($organization, $admin, $this->operator);
    $this->travel(2)->minutes();
    memService()->promote($organization, $other, $this->operator);
    $this->travel(7)->seconds();
    memService()->approve($organization, $admin, $this->operator);
    $this->travel(7)->seconds();
    memService()->approve($organization, $other, $this->operator);

    foreach ([$admin, $other] as $user) {
        $row = memRow($organization, $user);
        expect($row->admin_approved_at)->not->toBeNull()
            ->and($row->updated_at)->toBe($row->admin_approved_at, 'the same instant, not merely close');
    }

    $report = app(OrganizationPreflight::class)->report();
    expect($report['adminApproval']['changedAfterApproval'])->toBe([])
        ->and($report['adminApproval']['approvedActiveCustomerAdministrators'])->toHaveCount(2)
        ->and($report['failures'])->toBe([]);
});
