<?php

use App\Exceptions\OrganizationDirectoryException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationRelationships;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Release 1.0 (MO-1A): the operator-managed organization relationship. An administrative link from a customer organization to
 * a partner organization; it grants NO access to anything. These tests cover the rules, the policy (portal.operator AND
 * crm.manage), the unscoped integrity reads, the operator screen, the audit trail, the deletion and promotion guards, and that
 * the relationship leaves tenant isolation and the ticket, project and invoice boundaries exactly as they were.
 *
 * Everything runs inside RefreshDatabase's transaction. The races, which need real second connections, are in
 * OrganizationRelationshipsConcurrencyTest, and the schema and legacy-ambiguity behaviour in ManagedOrganizationMigrationTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** An Enabled organization, the unit a managed relationship needs. (Before ORG-2 it also needed exactly one CRM company; there is one entity now.) */
function pairedOrganization(string $name): Organization
{
    $owner = User::factory()->create();

    return Organization::factory()->create(['name' => $name, 'portal_enabled_by' => $owner->id]);
}

/** A partner: designated through the service, as production does. */
function partnerOrganization(string $name, User $actor): Organization
{
    $organization = pairedOrganization($name);
    app(OrganizationRelationships::class)->designatePartner($organization, $actor);

    return $organization->refresh();
}

/** The persisted relationship columns, bypassing every model and scope. */
function relationshipRow(Organization $organization): object
{
    return DB::table('organizations')->where('id', $organization->id)->first(['can_manage_customers', 'managed_by_organization_id']);
}

/** An account that is an operator by capability only: no `operator` role, so the tenant scopes treat it as a customer. */
function customRoleOperator(array $permissions = ['portal.operator', 'crm.manage']): User
{
    $role = Role::create(['name' => 'directory-lead-'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);

    return User::factory()->create()->assignRole($role);
}

function relationshipRefusal(callable $action): OrganizationRelationshipException
{
    try {
        $action();
    } catch (OrganizationRelationshipException $exception) {
        return $exception;
    }

    throw new RuntimeException('The operation was expected to be refused.');
}

// ── Partner designation ──────────────────────────────────────────────────────

it('designates and removes a partner, and a repeat changes nothing', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = pairedOrganization('UNISA');

    expect($service->designatePartner($unisa, $this->operator))->toBeTrue()
        ->and((bool) relationshipRow($unisa)->can_manage_customers)->toBeTrue()
        ->and($unisa->can_manage_customers)->toBeTrue();

    $audited = Activity::count();
    expect($service->designatePartner($unisa, $this->operator))->toBeFalse()
        ->and(Activity::count())->toBe($audited, 'a repeat writes no audit row');

    expect($service->removePartner($unisa, $this->operator))->toBeTrue()
        ->and((bool) relationshipRow($unisa)->can_manage_customers)->toBeFalse()
        ->and($service->removePartner($unisa, $this->operator))->toBeFalse();
});

it('records who designated and removed a partner, with the previous and new values', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = pairedOrganization('UNISA');

    $service->designatePartner($unisa, $this->operator);
    $service->removePartner($unisa, $this->operator);

    $entries = Activity::where('subject_type', Organization::class)->where('subject_id', $unisa->id)->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe([OrganizationRelationships::DESIGNATED, OrganizationRelationships::PARTNER_REMOVED])
        ->and($entries->every(fn (Activity $entry) => $entry->causer_id === $this->operator->id))->toBeTrue()
        ->and($entries[0]->properties->toArray())->toBe(['old' => ['can_manage_customers' => false], 'new' => ['can_manage_customers' => true]])
        ->and($entries[1]->properties->toArray())->toBe(['old' => ['can_manage_customers' => true], 'new' => ['can_manage_customers' => false]]);
});

it('refuses to designate a managed organization as a partner', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);

    expect(relationshipRefusal(fn () => $service->designatePartner($customer, $this->operator))->reason)->toBe(OrganizationRelationshipException::IS_MANAGED)
        ->and((bool) relationshipRow($customer)->can_manage_customers)->toBeFalse();
});

it('refuses to remove a partner designation while it still has customers, however the actor is filtered', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $a = pairedOrganization('Customer A');
    $b = pairedOrganization('Customer B');
    $service->assignCustomer($a, $unisa, $this->operator);
    $service->assignCustomer($b, $unisa, $this->operator);

    // The session is filtered by the tenant scope: the authenticated account is a customer that is a member of nothing (and not
    // operator-eligible, which the membership scope now keys on, R1-1), so a scoped count would say "no customers". The service actor
    // is an eligible custom-role operator, as it must be to pass the policy, and the service reads unscoped, so it must still refuse.
    // (An eligible operator in the session is no longer filtered; that contract is pinned in AuthorizationBaselineHardeningTest.)
    $lead = customRoleOperator();
    $this->actingAs(User::factory()->create()->assignRole('user'));   // the scopes only apply to an authenticated account
    expect(Organization::count())->toBe(0, 'the scope hides every organization from this session (the premise of the test)')
        ->and(Organization::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(3);

    $refusal = relationshipRefusal(fn () => $service->removePartner($unisa, $lead));

    expect($refusal->reason)->toBe(OrganizationRelationshipException::HAS_CUSTOMERS)
        ->and($refusal->getMessage())->toContain('2 customer organization(s)')
        ->and((bool) relationshipRow($unisa)->can_manage_customers)->toBeTrue();

    $service->unlinkCustomer($a, $lead);
    $service->unlinkCustomer($b, $lead);
    expect($service->removePartner($unisa, $lead))->toBeTrue();
});

// ── Customer relationships ───────────────────────────────────────────────────

it('links several customers to one partner, and unlinks one', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customers = collect(['Customer A', 'Customer B', 'Customer C'])->map(fn ($name) => pairedOrganization($name));

    $customers->each(fn (Organization $customer) => expect($service->assignCustomer($customer, $unisa, $this->operator))->toBeTrue());

    expect(OrganizationRelationships::managedCustomers($unisa)->pluck('name')->all())->toBe(['Customer A', 'Customer B', 'Customer C'])
        ->and(OrganizationRelationships::managerOf($customers[1])->id)->toBe($unisa->id);

    expect($service->unlinkCustomer($customers[1], $this->operator))->toBeTrue()
        ->and(OrganizationRelationships::managedCustomers($unisa)->pluck('name')->all())->toBe(['Customer A', 'Customer C'])
        ->and(OrganizationRelationships::managerOf($customers[1]))->toBeNull()
        ->and($service->unlinkCustomer($customers[1], $this->operator))->toBeFalse();
});

it('reassigns a customer to another partner and audits the move', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $other = partnerOrganization('Other Partner', $this->operator);
    $customer = pairedOrganization('Customer A');

    $service->assignCustomer($customer, $unisa, $this->operator);
    expect($service->assignCustomer($customer, $unisa, $this->operator))->toBeFalse('the same partner again is a no-op');
    expect($service->assignCustomer($customer, $other, $this->operator, reassign: true))->toBeTrue()
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($other->id);

    $service->unlinkCustomer($customer, $this->operator);
    $entries = Activity::where('subject_type', Organization::class)->where('subject_id', $customer->id)->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe([
        OrganizationRelationships::CUSTOMER_LINKED,
        OrganizationRelationships::CUSTOMER_REASSIGNED,
        OrganizationRelationships::CUSTOMER_UNLINKED,
    ])->and($entries[1]->properties->toArray())->toBe([
        'old' => ['managed_by_organization_id' => $unisa->id, 'managed_by_name' => 'UNISA'],
        'new' => ['managed_by_organization_id' => $other->id, 'managed_by_name' => 'Other Partner'],
    ])->and($entries[2]->properties->toArray())->toBe([
        'old' => ['managed_by_organization_id' => $other->id, 'managed_by_name' => 'Other Partner'],
        'new' => ['managed_by_organization_id' => null, 'managed_by_name' => null],
    ])->and($entries->every(fn (Activity $entry) => $entry->causer_id === $this->operator->id))->toBeTrue();
});

it('refuses self-management, a non-partner manager, a managed partner and chains, changing nothing', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $second = partnerOrganization('Second Partner', $this->operator);
    $plain = pairedOrganization('Not A Partner');
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);
    $audited = Activity::count();

    expect(relationshipRefusal(fn () => $service->assignCustomer($unisa, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::SELF)
        ->and(relationshipRefusal(fn () => $service->assignCustomer($customer, $plain, $this->operator))->reason)->toBe(OrganizationRelationshipException::NOT_PARTNER)
        // A partner cannot itself be managed (so no partner-of-a-partner), ...
        ->and(relationshipRefusal(fn () => $service->assignCustomer($second, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::IS_PARTNER)
        // ... and a managed organization cannot manage: it is not a partner, so there is no chain customer -> customer.
        ->and(relationshipRefusal(fn () => $service->assignCustomer($plain, $customer, $this->operator))->reason)->toBe(OrganizationRelationshipException::NOT_PARTNER)
        // A cycle needs a partner that is managed, which cannot exist.
        ->and(relationshipRefusal(fn () => $service->assignCustomer($unisa, $second, $this->operator))->reason)->toBe(OrganizationRelationshipException::IS_PARTNER);

    expect(relationshipRow($unisa)->managed_by_organization_id)->toBeNull()
        ->and(relationshipRow($second)->managed_by_organization_id)->toBeNull()
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id)
        ->and(Activity::count())->toBe($audited, 'a refusal leaves no audit row');
});

it('needs no Directory profile pairing: the Organization is the whole record, and only an Enabled one can be linked or designated', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');

    // Both ends are one row each, and nothing else has to exist for the relationship to be made.
    expect($service->assignCustomer($customer, $unisa, $this->operator))->toBeTrue()
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id);

    // A Directory-only Organization (never enabled) is refused by the ORG-1 portal-state rule, not by any pairing rule.
    $directoryOnly = Organization::factory()->directoryOnly()->create(['name' => 'Directory Only']);

    expect(relationshipRefusal(fn () => $service->assignCustomer($directoryOnly, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::PORTAL_NOT_ENABLED)
        ->and(relationshipRefusal(fn () => $service->designatePartner($directoryOnly, $this->operator))->reason)->toBe(OrganizationRelationshipException::PORTAL_NOT_ENABLED)
        ->and(relationshipRow($directoryOnly)->managed_by_organization_id)->toBeNull()
        ->and((bool) relationshipRow($directoryOnly)->can_manage_customers)->toBeFalse();
});

it('reports a missing organization as not found', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $gone = pairedOrganization('Gone');
    $ghost = new Organization;
    $ghost->forceFill(['id' => $gone->id + 10_000]);

    expect(fn () => $service->assignCustomer($ghost, $unisa, $this->operator))->toThrow(HttpException::class);
});

it('locks the organization rows in ascending id order whichever way round the pair is given', function () {
    $service = app(OrganizationRelationships::class);
    // Ids rise with creation order: the partner is the lower id in the first pair and the higher in the second.
    $lowPartner = partnerOrganization('Low Partner', $this->operator);
    $highCustomer = pairedOrganization('High Customer');
    $lowCustomer = pairedOrganization('Low Customer');
    $highPartner = partnerOrganization('High Partner', $this->operator);
    expect($lowPartner->id)->toBeLessThan($highCustomer->id)->and($lowCustomer->id)->toBeLessThan($highPartner->id);

    $locking = function (callable $action): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return collect($log)->filter(fn ($query) => str_contains($query['query'], 'from `organizations`') && str_contains($query['query'], 'for update'))->values()->all();
    };

    // One locking statement for the pair, ordered by id, with the ids bound in ascending order.
    $locks = $locking(fn () => $service->assignCustomer($highCustomer, $lowPartner, $this->operator));
    expect($locks)->toHaveCount(1)->and($locks[0]['query'])->toContain('order by `id` asc')->and($locks[0]['bindings'])->toBe([$lowPartner->id, $highCustomer->id]);

    $locks = $locking(fn () => $service->assignCustomer($lowCustomer, $highPartner, $this->operator));
    expect($locks)->toHaveCount(1)->and($locks[0]['query'])->toContain('order by `id` asc')->and($locks[0]['bindings'])->toBe([$lowCustomer->id, $highPartner->id]);
});

// ── Authorization ────────────────────────────────────────────────────────────

it('needs BOTH portal.operator and crm.manage, in the service and on every route', function () {
    $unisa = pairedOrganization('UNISA');
    $customer = pairedOrganization('Customer A');
    $partner = partnerOrganization('Partner', $this->operator);

    $actors = [
        'crm.manage without portal.operator' => customRoleOperator(['crm.manage']),
        'portal.operator without crm.manage' => customRoleOperator(['portal.operator']),
        'customer' => User::factory()->create()->assignRole('user'),
        'customer with a stray crm.manage' => User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage'),
    ];
    $before = [relationshipRow($unisa), relationshipRow($customer), relationshipRow($partner)];
    $audited = Activity::count();

    foreach ($actors as $label => $actor) {
        $service = app(OrganizationRelationships::class);
        expect(fn () => $service->designatePartner($unisa, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->removePartner($partner, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->assignCustomer($customer, $partner, $actor))->toThrow(AuthorizationException::class)
            ->and(fn () => $service->unlinkCustomer($customer, $actor))->toThrow(AuthorizationException::class);

        $this->actingAs($actor);
        $this->put(route('organizations.partner.designate', $unisa->id))->assertForbidden();
        $this->delete(route('organizations.partner.remove', $partner->id))->assertForbidden();
        $this->put(route('organizations.manager.assign', $customer->id), ['partner_id' => $partner->id])->assertForbidden();
        $this->post(route('organizations.customers.link', $partner->id), ['customer_id' => $customer->id])->assertForbidden();
        $this->delete(route('organizations.manager.unlink', $customer->id))->assertForbidden();
    }

    expect([relationshipRow($unisa), relationshipRow($customer), relationshipRow($partner)])->toEqual($before)
        ->and(Activity::count())->toBe($audited);

    $this->app['auth']->forgetGuards();
    $this->put(route('organizations.partner.designate', $unisa->id))->assertRedirect('/login');
});

it('lets a custom-role operator holding both capabilities use the operations, with no tenant membership at all', function () {
    $lead = customRoleOperator();
    $unisa = pairedOrganization('UNISA');
    $customer = pairedOrganization('Customer A');

    // Not a member, but eligible (R1-1): the organization page resolves for them, so the success lands on it.
    $this->actingAs($lead)->put(route('organizations.partner.designate', $unisa->id))
        ->assertRedirect(route('organizations.show', $unisa->id));
    $this->actingAs($lead)->post(route('organizations.customers.link', $unisa->id), ['customer_id' => $customer->id])
        ->assertRedirect(route('organizations.show', $unisa->id))
        ->assertSessionHas('success', '"Customer A" is now managed by "UNISA".');

    expect(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id)
        ->and(Activity::where('causer_id', $lead->id)->count())->toBe(2);

    $this->actingAs($lead)->delete(route('organizations.manager.unlink', $customer->id))->assertRedirect(route('organizations.show', $customer->id));
    $this->actingAs($lead)->delete(route('organizations.partner.remove', $unisa->id))->assertRedirect(route('organizations.show', $unisa->id));

    expect(relationshipRow($customer)->managed_by_organization_id)->toBeNull()
        ->and((bool) relationshipRow($unisa)->can_manage_customers)->toBeFalse();
});

it('lands each actor on a page they can open, with the success message, after a successful change', function () {
    // The change is made, then the actor is sent somewhere. organizations.show binds through the tenant scope, which (R1-1) keys on
    // portal.operator: an eligible custom-role operator who is not a member opens the page like the built-in operator.
    $visit = function (User $actor, Organization $organization, string $expectedUrl, string $message) {
        $response = $this->actingAs($actor)->put(route('organizations.partner.designate', $organization->id));
        $response->assertRedirect($expectedUrl)->assertSessionHas('success', $message);

        // The whole redirect, not just its target: the page opens (200) and shows the message.
        $this->actingAs($actor)->get($expectedUrl)->assertOk()->assertSee($message);
        expect((bool) relationshipRow($organization)->can_manage_customers)->toBeTrue();
    };

    $nonMember = customRoleOperator();
    $alpha = pairedOrganization('Alpha');
    $visit($nonMember, $alpha, route('organizations.show', $alpha->id), '"Alpha" is now a partner.');

    $member = customRoleOperator();
    $beta = pairedOrganization('Beta');
    $beta->members()->attach($member, ['role' => 'member']);
    $visit($member, $beta, route('organizations.show', $beta->id), '"Beta" is now a partner.');

    $gamma = pairedOrganization('Gamma');
    $visit($this->operator, $gamma, route('organizations.show', $gamma->id), '"Gamma" is now a partner.');

    // R1-1: nor is a custom-role operator refused a page they are not a member of; a customer that is not a member still is.
    $this->actingAs($nonMember)->get(route('organizations.show', $gamma->id))->assertOk();
    $this->actingAs(User::factory()->create()->assignRole('user'))->get(route('organizations.show', $gamma->id))->assertNotFound();
});

it('is not reachable by a customer in pilot mode, even one holding crm.manage', function () {
    config(['portal.customer_surface_mode' => 'pilot']);
    $customer = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $unisa = pairedOrganization('UNISA');

    $this->actingAs($customer)->put(route('organizations.partner.designate', $unisa->id))->assertStatus(404);
    $this->actingAs($customer)->put(route('organizations.manager.assign', $unisa->id), ['partner_id' => $unisa->id])->assertStatus(404);

    expect((bool) relationshipRow($unisa)->can_manage_customers)->toBeFalse();
});

// ── Operator screen ──────────────────────────────────────────────────────────

it('answers a rule refusal with a business message, 409 for JSON, and no change', function () {
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    app(OrganizationRelationships::class)->assignCustomer($customer, $unisa, $this->operator);

    $this->actingAs($this->operator)->from(route('organizations.show', $unisa->id))
        ->delete(route('organizations.partner.remove', $unisa->id))
        ->assertRedirect(route('organizations.show', $unisa->id))
        ->assertSessionHasErrors(['relationship' => '"UNISA" still manages 1 customer organization(s). Unlink or reassign them before removing its partner designation.']);

    $this->actingAs($this->operator)->deleteJson(route('organizations.partner.remove', $unisa->id))
        ->assertStatus(409)->assertJson(['message' => '"UNISA" still manages 1 customer organization(s). Unlink or reassign them before removing its partner designation.']);

    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $customer->id), ['partner_id' => 999999])->assertSessionHasErrors('partner_id');
    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $customer->id), [])->assertSessionHasErrors('partner_id');
    $this->actingAs($this->operator)->post(route('organizations.customers.link', $unisa->id), ['customer_id' => 'x'])->assertSessionHasErrors('customer_id');
    $this->actingAs($this->operator)->put('/organizations/not-a-number/partner')->assertNotFound();

    expect((bool) relationshipRow($unisa)->can_manage_customers)->toBeTrue()
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id);
});

it('shows an operator the relationship, offers only valid actions, and shows a stray crm.manage holder nothing', function () {
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $spare = pairedOrganization('Spare Customer');
    $broken = Organization::factory()->directoryOnly()->create(['name' => 'Directory Only Org']);   // never enabled: no candidate
    app(OrganizationRelationships::class)->assignCustomer($customer, $unisa, $this->operator);

    $partnerPage = $this->actingAs($this->operator)->get(route('organizations.show', $unisa))->assertOk();
    $partnerPage->assertSee('Partner relationship')->assertSee('Managed customers (1)')->assertSee('Customer A')
        ->assertSee('Link customer')->assertSee('Spare Customer')->assertDontSee('Directory Only Org')
        ->assertSee('Remove partner designation')->assertSee('Unlink its customers first.')
        ->assertDontSee('Designate as partner');

    $managedPage = $this->actingAs($this->operator)->get(route('organizations.show', $customer))->assertOk();
    $managedPage->assertSee('Managed by')->assertSee('UNISA')->assertSee('Unlink from partner')->assertSee('Reassign');

    $plainPage = $this->actingAs($this->operator)->get(route('organizations.show', $spare))->assertOk();
    $plainPage->assertSee('Not managed by a partner.')->assertSee('Designate as partner')->assertSee('Link to partner');

    $brokenPage = $this->actingAs($this->operator)->get(route('organizations.show', $broken))->assertOk();
    $brokenPage->assertDontSee('Designate as partner')->assertDontSee('Link to partner');   // a never-enabled Organization is offered neither: the server would refuse both

    // A customer who holds crm.manage and belongs to the customer organization can open their own organization's page,
    // and must learn nothing about any other organization from the relationship.
    $member = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $customer->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member)->get(route('organizations.show', $customer))->assertOk()
        ->assertDontSee('Partner relationship')->assertDontSee('UNISA')->assertDontSee('Spare Customer');
});

it('refuses a plain Link of a customer another partner already manages, with a conflict message and no change', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $other = partnerOrganization('Other Partner', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $other, $this->operator);
    $audited = Activity::count();

    // A stale "Link customer" form on UNISA's page (the customer was linked to Other Partner after it was drawn).
    $this->actingAs($this->operator)->from(route('organizations.show', $unisa->id))
        ->post(route('organizations.customers.link', $unisa->id), ['customer_id' => $customer->id])
        ->assertRedirect(route('organizations.show', $unisa->id))
        ->assertSessionHasErrors(['relationship' => '"Customer A" is already managed by "Other Partner" (it may have been linked since this page was loaded). Nothing was changed. Reload, then use Reassign if you mean to move it.']);

    $this->actingAs($this->operator)->postJson(route('organizations.customers.link', $unisa->id), ['customer_id' => $customer->id])->assertStatus(409);

    // The same through the service, and a stale customer-page form that was drawn as "Link to partner" (no `reassign`).
    expect(relationshipRefusal(fn () => $service->assignCustomer($customer, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::ALREADY_MANAGED);
    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $customer->id), ['partner_id' => $unisa->id])
        ->assertSessionHasErrors('relationship');

    expect(relationshipRow($customer)->managed_by_organization_id)->toBe($other->id)
        ->and(Activity::count())->toBe($audited, 'a refused link leaves no audit row');

    // Linking to the partner it already has is still a harmless no-op.
    $this->actingAs($this->operator)->post(route('organizations.customers.link', $other->id), ['customer_id' => $customer->id])
        ->assertSessionHas('success', '"Customer A" is already managed by "Other Partner".');
});

it('moves a customer only on an explicit Reassign, and audits the move', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $other = partnerOrganization('Other Partner', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $other, $this->operator);

    $this->actingAs($this->operator)->put(route('organizations.manager.assign', $customer->id), ['partner_id' => $unisa->id, 'reassign' => '1'])
        ->assertSessionHas('success', '"Customer A" is now managed by "UNISA".');

    expect(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id)
        ->and(Activity::where('subject_id', $customer->id)->where('description', OrganizationRelationships::CUSTOMER_REASSIGNED)->count())->toBe(1);

    // The customer page offers Reassign (and sends the flag) only when it shows a current partner.
    $this->actingAs($this->operator)->get(route('organizations.show', $customer->id))->assertSee('name="reassign"', false);
    $plain = pairedOrganization('Plain');
    $this->actingAs($this->operator)->get(route('organizations.show', $plain->id))->assertDontSee('name="reassign"', false);
});

it('offers Unlink for a managed organization, and the server allows it', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $customer->id))->assertOk();
    $page->assertSee('Unlink from partner');

    $service->unlinkCustomer($customer, $this->operator);
    expect(relationshipRow($customer)->managed_by_organization_id)->toBeNull();
});

it('takes the actor row, then the Organization row exclusively, then nothing for a Directory-only deletion, and never a Company lock', function () {
    $organization = Organization::factory()->directoryOnly()->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(OrganizationDirectory::class)->delete($organization, $this->operator);
    $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => preg_replace('/\s+/', ' ', $sql));
    DB::disableQueryLog();

    $locks = $log->filter(fn ($sql) => str_contains($sql, 'lock in share mode') || str_contains($sql, 'for update'))->values();

    expect($locks[0])->toContain('from `users`')->toContain('lock in share mode')
        ->and($locks[1])->toContain('from `organizations`')->toContain('for update')
        ->and($log->contains(fn ($sql) => str_contains($sql, 'crm_companies')))->toBeFalse()
        ->and(Organization::withoutGlobalScopes()->whereKey($organization->id)->exists())->toBeFalse();
});

// ── Relationship grants nothing ──────────────────────────────────────────────

it('grants a partner and its members no access to a customer: tickets, projects, invoices, directory and organization pages', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');

    $unisaAdmin = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $unisa->members()->attach($unisaAdmin, ['role' => 'admin']);
    $customerUser = User::factory()->create()->assignRole('user');
    $customer->members()->attach($customerUser, ['role' => 'member']);

    $ticket = Ticket::factory()->create(['user_id' => $customerUser->id]);
    $project = Project::factory()->create();
    $project->members()->attach($customerUser, ['role' => 'member']);
    $invoice = Invoice::factory()->create(['client_id' => $customerUser->id]);

    $observe = function () use ($unisaAdmin, $ticket, $project, $invoice, $customer): array {
        $this->actingAs($unisaAdmin);

        return [
            'ticket' => $unisaAdmin->can('view', $ticket),
            'ticket page' => $this->get(route('tickets.show', $ticket))->status(),
            'project' => $unisaAdmin->can('view', $project),
            'visible projects' => Project::visibleTo($unisaAdmin)->count(),
            'invoice' => $unisaAdmin->can('view', $invoice),
            'organization page' => $this->get(route('organizations.show', $customer))->status(),
            'directory page' => $this->get(route('crm.companies.show', $customer))->status(),
            'organizations visible' => Organization::pluck('name')->all(),
        ];
    };

    $before = $observe();
    $service->assignCustomer($customer, $unisa, $this->operator);
    $after = $observe();

    expect($after)->toBe($before)
        ->and($after['ticket'])->toBeFalse()->and($after['project'])->toBeFalse()->and($after['invoice'])->toBeFalse()
        ->and($after['organization page'])->toBe(404)->and($after['directory page'])->toBe(404)
        ->and($after['organizations visible'])->toBe(['UNISA']);

    // The customer's own boundaries are untouched too: owner-only tickets, and a peer in the customer organization sees nothing of it.
    $peer = User::factory()->create()->assignRole('user');
    $customer->members()->attach($peer, ['role' => 'member']);
    expect($peer->can('view', $ticket))->toBeFalse()->and($customerUser->can('view', $ticket))->toBeTrue();
});

// ── Deletion and initial enablement ──────────────────────────────────────────

it('refuses to delete a partner or a managed customer, with a business message and no side effect: both have had portal access', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);
    $organizations = ['partner' => $unisa, 'customer' => $customer];
    $audited = Activity::count();

    foreach ($organizations as $organization) {
        $this->actingAs($this->operator)->from(route('crm.companies.edit', $organization))->delete(route('crm.companies.destroy', $organization))
            ->assertRedirect(route('crm.companies.edit', $organization))
            ->assertSessionHasErrors('company');

        $this->actingAs($this->operator)->deleteJson(route('crm.companies.destroy', $organization))
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'has (or had) portal access'));

        expect(fn () => app(OrganizationDirectory::class)->delete($organization, $this->operator))
            ->toThrow(OrganizationDirectoryException::class, 'Only an Organization that never had portal access can be deleted');

        expect(DB::table('organizations')->where('id', $organization->id)->exists())->toBeTrue();
    }

    expect(Activity::count())->toBe($audited)
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id);

    // Removing the relationship changes nothing about that: an Organization that has had portal access is disabled, never deleted (ruling 5).
    $service->unlinkCustomer($customer, $this->operator);
    $service->removePartner($unisa, $this->operator);

    expect(fn () => app(OrganizationDirectory::class)->delete($customer, $this->operator))->toThrow(OrganizationDirectoryException::class)
        ->and(DB::table('organizations')->whereIn('id', [$customer->id, $unisa->id])->count())->toBe(2);
});

it('enables a Directory-only Organization once: a repeat, or a stale model, is refused as already_enabled and changes nothing', function () {
    $service = app(OrganizationDirectory::class);
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Fresh Co', 'created_by' => $this->operator->id]);
    $stale = Organization::withoutGlobalScopes()->find($organization->id); // a second request's copy, loaded before the first enablement

    $first = $service->enablePortalAccess($organization, $this->operator);
    $snapshot = (array) DB::table('organizations')->where('id', $first->id)->first();

    foreach ([$organization, $stale] as $model) {
        expect(fn () => $service->enablePortalAccess($model, $this->operator))->toThrow(OrganizationRelationshipException::class, 'already has portal access');
    }

    expect((array) DB::table('organizations')->where('id', $first->id)->first())->toBe($snapshot)
        ->and(Organization::withoutGlobalScopes()->where('name', 'Fresh Co')->count())->toBe(1)
        ->and(DB::table('organization_members')->where('organization_id', $first->id)->count())->toBe(1)
        ->and($first->slug)->not->toBeEmpty();
});

it('picks a slug no other tenant holds, even for an actor the membership scope blinds', function () {
    $service = app(OrganizationDirectory::class);
    Organization::factory()->create(['name' => 'Taken', 'slug' => 'shared-name']);

    // Authenticated as someone who sees no organization: a scoped slug check would have found none and collided.
    $this->actingAs(customRoleOperator());
    $organization = $service->create($this->operator, ['name' => 'Shared Name']);

    expect($organization->slug)->toBe('shared-name-2');
});

// ── Existing contracts the work must not move ────────────────────────────────

it('keeps portal state, relationship columns, the Directory profile and the internal columns out of what the application can mass-assign', function () {
    // ORG-1's restrictive contract, restored by CP6 (F-7): ONLY name and slug. The Directory profile, the Classification and the creator are written by
    // OrganizationDirectory by explicit column; portal state and the relationship columns change only through the services that authorize, lock and audit them.
    expect((new Organization)->getFillable())->toBe(['name', 'slug']);
});
