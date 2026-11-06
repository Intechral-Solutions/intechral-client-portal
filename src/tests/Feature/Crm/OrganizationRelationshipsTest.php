<?php

use App\Exceptions\OrganizationRelationshipException;
use App\Models\CrmCompany;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CrmService;
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

/** An organization with exactly one CRM company: the pair a managed relationship needs. */
function pairedOrganization(string $name): Organization
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['name' => $name, 'owner_id' => $owner->id]);
    CrmCompany::factory()->create(['name' => $name, 'organization_id' => $organization->id, 'created_by' => $owner->id]);

    return $organization;
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

    // The actor is a custom-role operator who is a member of nothing: the membership scope shows them no organization at all,
    // so a scoped count would say "no customers". The unscoped count must still refuse.
    $lead = customRoleOperator();
    $this->actingAs($lead);   // the scopes only apply to an authenticated actor
    expect(Organization::count())->toBe(0, 'the scope hides every organization from this account (the premise of the test)');

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

it('requires an unambiguous company pairing for both ends', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $withoutCompany = Organization::factory()->create(['name' => 'No Company']);

    expect(relationshipRefusal(fn () => $service->assignCustomer($withoutCompany, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::COMPANY_PAIRING)
        ->and(relationshipRefusal(fn () => $service->designatePartner($withoutCompany, $this->operator))->reason)->toBe(OrganizationRelationshipException::COMPANY_PAIRING)
        ->and(relationshipRow($withoutCompany)->managed_by_organization_id)->toBeNull()
        ->and((bool) relationshipRow($withoutCompany)->can_manage_customers)->toBeFalse();

    // The partner side too: a partner whose company is gone cannot gain customers.
    $customer = pairedOrganization('Customer A');
    DB::table('crm_companies')->where('organization_id', $unisa->id)->update(['organization_id' => null]);

    expect(relationshipRefusal(fn () => $service->assignCustomer($customer, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::COMPANY_PAIRING);
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

    // Not a member, so the scoped organization page is a 404 for them: the success lands on the index instead.
    $this->actingAs($lead)->put(route('organizations.partner.designate', $unisa->id))
        ->assertRedirect(route('organizations.index'));
    $this->actingAs($lead)->post(route('organizations.customers.link', $unisa->id), ['customer_id' => $customer->id])
        ->assertRedirect(route('organizations.index'))
        ->assertSessionHas('success', '"Customer A" is now managed by "UNISA".');

    expect(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id)
        ->and(Activity::where('causer_id', $lead->id)->count())->toBe(2);

    $this->actingAs($lead)->delete(route('organizations.manager.unlink', $customer->id))->assertRedirect(route('organizations.index'));
    $this->actingAs($lead)->delete(route('organizations.partner.remove', $unisa->id))->assertRedirect(route('organizations.index'));

    expect(relationshipRow($customer)->managed_by_organization_id)->toBeNull()
        ->and((bool) relationshipRow($unisa)->can_manage_customers)->toBeFalse();
});

it('lands each actor on a page they can open, with the success message, after a successful change', function () {
    // The change is made, then the actor is sent somewhere. organizations.show binds through the tenant scope, so a custom-role
    // operator who is not a member would get a 404 there: they go to the index. Nobody gains access to any page.
    $visit = function (User $actor, Organization $organization, string $expectedUrl, string $message) {
        $response = $this->actingAs($actor)->put(route('organizations.partner.designate', $organization->id));
        $response->assertRedirect($expectedUrl)->assertSessionHas('success', $message);

        // The whole redirect, not just its target: the page opens (200) and shows the message.
        $this->actingAs($actor)->get($expectedUrl)->assertOk()->assertSee($message);
        expect((bool) relationshipRow($organization)->can_manage_customers)->toBeTrue();
    };

    $nonMember = customRoleOperator();
    $visit($nonMember, pairedOrganization('Alpha'), route('organizations.index'), '"Alpha" is now a partner.');

    $member = customRoleOperator();
    $beta = pairedOrganization('Beta');
    $beta->members()->attach($member, ['role' => 'member']);
    $visit($member, $beta, route('organizations.show', $beta->id), '"Beta" is now a partner.');

    $gamma = pairedOrganization('Gamma');
    $visit($this->operator, $gamma, route('organizations.show', $gamma->id), '"Gamma" is now a partner.');

    // A custom-role operator is still not given the page they are not a member of.
    $this->actingAs($nonMember)->get(route('organizations.show', $gamma->id))->assertNotFound();
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
    $broken = Organization::factory()->create(['name' => 'Broken Pairing']);
    app(OrganizationRelationships::class)->assignCustomer($customer, $unisa, $this->operator);

    $partnerPage = $this->actingAs($this->operator)->get(route('organizations.show', $unisa))->assertOk();
    $partnerPage->assertSee('Partner relationship')->assertSee('Managed customers (1)')->assertSee('Customer A')
        ->assertSee('Link customer')->assertSee('Spare Customer')->assertDontSee('Broken Pairing')
        ->assertSee('Remove partner designation')->assertSee('Unlink its customers first.')
        ->assertDontSee('Designate as partner');

    $managedPage = $this->actingAs($this->operator)->get(route('organizations.show', $customer))->assertOk();
    $managedPage->assertSee('Managed by')->assertSee('UNISA')->assertSee('Unlink from partner')->assertSee('Reassign');

    $plainPage = $this->actingAs($this->operator)->get(route('organizations.show', $spare))->assertOk();
    $plainPage->assertSee('Not managed by a partner.')->assertSee('Designate as partner')->assertSee('Link to partner');

    $brokenPage = $this->actingAs($this->operator)->get(route('organizations.show', $broken))->assertOk();
    $brokenPage->assertSee('linked to 0 CRM companies')->assertDontSee('Designate as partner')->assertDontSee('Link to partner');

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

it('still offers Unlink for a managed organization whose company is gone, and the server allows it', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);
    DB::table('crm_companies')->where('organization_id', $customer->id)->update(['organization_id' => null]);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $customer->id))->assertOk();
    $page->assertSee('linked to 0 CRM companies')->assertSee('Unlink from partner')
        ->assertDontSee('Link to partner')->assertDontSee('Reassign');

    // Creating a relationship still needs the pair: the broken organization cannot be linked anywhere new.
    $service->unlinkCustomer($customer, $this->operator);
    expect(relationshipRefusal(fn () => $service->assignCustomer($customer, $unisa, $this->operator))->reason)->toBe(OrganizationRelationshipException::COMPANY_PAIRING)
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBeNull();
});

it('reads a company with no organization under a shared row lock before deleting it', function () {
    $company = CrmCompany::factory()->create(['organization_id' => null]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(CrmService::class)->deleteCompany($company);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $locked = collect($log)->filter(fn ($query) => str_contains($query['query'], 'select `organization_id` from `crm_companies`') && str_contains($query['query'], 'lock in share mode'));

    expect($locked)->toHaveCount(1)->and(CrmCompany::withoutGlobalScopes()->whereKey($company->id)->exists())->toBeFalse();
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
    $customerCompany = CrmCompany::withoutGlobalScopes()->where('organization_id', $customer->id)->firstOrFail();

    $observe = function () use ($unisaAdmin, $ticket, $project, $invoice, $customer, $customerCompany): array {
        $this->actingAs($unisaAdmin);

        return [
            'ticket' => $unisaAdmin->can('view', $ticket),
            'ticket page' => $this->get(route('tickets.show', $ticket))->status(),
            'project' => $unisaAdmin->can('view', $project),
            'visible projects' => Project::visibleTo($unisaAdmin)->count(),
            'invoice' => $unisaAdmin->can('view', $invoice),
            'organization page' => $this->get(route('organizations.show', $customer))->status(),
            'company page' => $this->get(route('crm.companies.show', $customerCompany))->status(),
            'organizations visible' => Organization::pluck('name')->all(),
            'companies visible' => CrmCompany::pluck('name')->all(),
        ];
    };

    $before = $observe();
    $service->assignCustomer($customer, $unisa, $this->operator);
    $after = $observe();

    expect($after)->toBe($before)
        ->and($after['ticket'])->toBeFalse()->and($after['project'])->toBeFalse()->and($after['invoice'])->toBeFalse()
        ->and($after['organization page'])->toBe(404)->and($after['company page'])->toBe(404)
        ->and($after['organizations visible'])->toBe(['UNISA'])->and($after['companies visible'])->toBe(['UNISA']);

    // The customer's own boundaries are untouched too: owner-only tickets, and a peer in the customer organization sees nothing of it.
    $peer = User::factory()->create()->assignRole('user');
    $customer->members()->attach($peer, ['role' => 'member']);
    expect($peer->can('view', $ticket))->toBeFalse()->and($customerUser->can('view', $ticket))->toBeTrue();
});

// ── Company deletion and promotion ───────────────────────────────────────────

it('refuses to delete the company of a partner or of a managed customer, with a business message and no side effect', function () {
    $service = app(OrganizationRelationships::class);
    $unisa = partnerOrganization('UNISA', $this->operator);
    $customer = pairedOrganization('Customer A');
    $service->assignCustomer($customer, $unisa, $this->operator);
    $companies = [
        'partner' => CrmCompany::withoutGlobalScopes()->where('organization_id', $unisa->id)->firstOrFail(),
        'customer' => CrmCompany::withoutGlobalScopes()->where('organization_id', $customer->id)->firstOrFail(),
    ];
    $audited = Activity::count();

    foreach ($companies as $company) {
        $this->actingAs($this->operator)->from(route('crm.companies.edit', $company))->delete(route('crm.companies.destroy', $company))
            ->assertRedirect(route('crm.companies.edit', $company))
            ->assertSessionHasErrors('company');

        $this->actingAs($this->operator)->deleteJson(route('crm.companies.destroy', $company))
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'is designated as a partner or is managed by another organization'));

        expect(fn () => app(CrmService::class)->deleteCompany($company))
            ->toThrow(OrganizationRelationshipException::class, 'Remove the relationship first.');

        expect(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeTrue();
    }

    expect(Activity::count())->toBe($audited)
        ->and(relationshipRow($customer)->managed_by_organization_id)->toBe($unisa->id);

    // Once the relationship is gone, the company deletes as any other does.
    $service->unlinkCustomer($customer, $this->operator);
    $service->removePartner($unisa, $this->operator);
    app(CrmService::class)->deleteCompany($companies['customer']);
    app(CrmService::class)->deleteCompany($companies['partner']);

    expect(DB::table('crm_companies')->whereIn('id', [$companies['customer']->id, $companies['partner']->id])->exists())->toBeFalse();
});

it('keeps deleting a promoted company that has no relationship', function () {
    $organization = pairedOrganization('Plain');
    $company = CrmCompany::withoutGlobalScopes()->where('organization_id', $organization->id)->firstOrFail();

    app(CrmService::class)->deleteCompany($company);

    expect(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeFalse()
        ->and(DB::table('organizations')->where('id', $organization->id)->exists())->toBeTrue('the organization is not deleted with its company');
});

it('promotes a company once: a repeat, or a stale model, returns the same organization', function () {
    $service = app(CrmService::class);
    $company = CrmCompany::factory()->create(['name' => 'Fresh Co', 'organization_id' => null, 'created_by' => $this->operator->id]);
    $stale = CrmCompany::withoutGlobalScopes()->find($company->id); // a second request's copy, loaded before the first promotion

    $first = $service->promoteToOrganization($company, $this->operator);
    $again = $service->promoteToOrganization($company, $this->operator);
    $viaStale = $service->promoteToOrganization($stale, $this->operator);

    expect($again->id)->toBe($first->id)->and($viaStale->id)->toBe($first->id)
        ->and($stale->organization_id)->toBe($first->id)
        ->and(Organization::withoutGlobalScopes()->where('name', 'Fresh Co')->count())->toBe(1)
        ->and(DB::table('organization_members')->where('organization_id', $first->id)->count())->toBe(1)
        ->and(DB::table('crm_companies')->where('id', $company->id)->value('organization_id'))->toBe($first->id)
        ->and($first->slug)->toBe('fresh-co');
});

it('promotion picks a slug no other tenant holds, even for an actor the membership scope blinds', function () {
    $service = app(CrmService::class);
    Organization::factory()->create(['name' => 'Taken', 'slug' => 'shared-name']);
    $company = CrmCompany::factory()->create(['name' => 'Shared Name', 'organization_id' => null, 'created_by' => $this->operator->id]);

    // Authenticated as someone who sees no organization: the scoped slug check would have found none and collided.
    $this->actingAs(customRoleOperator());
    $organization = $service->promoteToOrganization($company, $this->operator);

    expect($organization->slug)->toBe('shared-name-2');
});

// ── Existing contracts the work must not move ────────────────────────────────

it('leaves the organization fields the application can mass-assign unchanged', function () {
    expect((new Organization)->getFillable())->toBe(['name', 'slug', 'owner_id']);
});
