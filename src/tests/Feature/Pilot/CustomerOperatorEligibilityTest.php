<?php

/*
 * R0.9-CS1: explicit operator eligibility. Real accounts, real roles and persisted grants, never a mocked role name.
 * `portal.operator` is a trust designation: holding it exempts an account from the customer boundary and grants
 * nothing else, and no other capability implies it.
 */

use App\Exceptions\LastAccountAdministratorException;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// Every business GET an operator-class account reaches and a customer must not: (uri) => the capability it needs.
const ELIGIBILITY_ROUTES = [
    '/billing/invoices' => 'billing.manage',
    '/operator/tickets' => 'tickets.assign',
    '/crm/companies' => 'crm.manage',
    '/admin/users' => 'users.view',
    '/admin/roles' => 'roles.view',
    '/operator/cms' => 'cms.edit',
    '/operator/time' => 'time.view_all',
];

/**
 * What a real client's next request gets: a fresh authentication from the session, not the guard's cached user.
 * The test client reuses one application between calls, so without this a changed grant would be hidden by the
 * guard's per-request memory, which production never has.
 */
function eligibilityNextRequest(): void
{
    app('auth')->forgetGuards();
}

it('keeps the built-in operator operational and the built-in user restricted, in both restricting modes', function (string $mode) {
    pilotMode($mode);

    foreach (ELIGIBILITY_ROUTES as $uri => $capability) {
        $this->actingAs(pilotOperator())->get($uri)->assertOk();
        $this->actingAs(pilotCustomer())->get($uri)->assertNotFound();
    }

    $this->actingAs(pilotOperator())->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard/index'));
})->with(['closed', 'pilot']);

it('keeps an account with settings.view, and with every other operational capability but no eligibility, a customer', function (string $mode) {
    pilotMode($mode);
    $settingsOnly = pilotCustomRoleUser('settings-viewer', ['settings.view', ...PermissionCatalogue::userDefaults()]);
    $everythingElse = pilotCustomRoleUser('everything-but-eligibility', array_values(array_diff(PermissionCatalogue::all(), ['portal.operator'])));

    foreach ([$settingsOnly, $everythingElse] as $user) {
        foreach (ELIGIBILITY_ROUTES as $uri => $capability) {
            $this->actingAs($user)->get($uri)->assertNotFound();
        }

        $this->actingAs($user)->getJson('/billing/invoices')->assertNotFound();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component($mode === 'pilot' ? 'dashboard/customer-home' : 'dashboard/customer-unavailable'));
    }
})->with(['closed', 'pilot']);

it('lets a custom role with deliberate eligibility and the endpoint permissions do the work', function () {
    pilotMode('pilot');
    $staff = pilotCustomRoleUser('trusted-billing', ['portal.operator', 'billing.manage', 'billing.create', 'billing.view']);

    $this->actingAs($staff)->get('/billing/invoices')->assertOk();
    $this->actingAs($staff)->get('/billing/invoices/create')->assertOk();
});

it('does not turn eligibility into permission: the endpoint checks still apply', function () {
    pilotMode('pilot');
    $eligibleOnly = pilotCustomRoleUser('eligible-only', ['portal.operator']);

    foreach (ELIGIBILITY_ROUTES as $uri => $capability) {
        // Past the customer boundary (not a 404), stopped by the route's own capability (403).
        $this->actingAs($eligibleOnly)->get($uri)->assertForbidden();
    }

    // And it gives none of the customer surface's own requirements either.
    $this->actingAs($eligibleOnly)->post('/billing/invoices', [])->assertForbidden();
});

it('changes classification on an existing authenticated session when eligibility is granted and revoked', function () {
    pilotMode('pilot');
    $staff = pilotCustomRoleUser('later-eligible', ['billing.manage']);
    $staff->forceFill(['password' => bcrypt('Password1!')])->save();

    $this->post('/login', ['email' => $staff->email, 'password' => 'Password1!'])->assertRedirect('/dashboard');
    $this->get('/billing/invoices')->assertNotFound();

    // Promotion (a direct grant of eligibility) takes effect on the next request of the SAME session.
    User::findOrFail($staff->id)->givePermissionTo('portal.operator');
    eligibilityNextRequest();
    $this->get('/billing/invoices')->assertOk();

    // Demotion takes it away again.
    User::findOrFail($staff->id)->revokePermissionTo('portal.operator');
    eligibilityNextRequest();
    $this->get('/billing/invoices')->assertNotFound();
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('dashboard/customer-home'));
});

it('changes classification when the built-in operator role is given and taken away', function () {
    pilotMode('pilot');
    $user = pilotCustomer(['password' => bcrypt('Password1!')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'Password1!'])->assertRedirect('/dashboard');
    $this->get('/admin/users')->assertNotFound();

    User::findOrFail($user->id)->assignRole('operator');
    eligibilityNextRequest();
    $this->get('/admin/users')->assertOk();

    User::findOrFail($user->id)->removeRole('operator');
    eligibilityNextRequest();
    $this->get('/admin/users')->assertNotFound();
});

it('changes classification when a custom role gains or loses eligibility under the account', function () {
    pilotMode('pilot');
    $staff = pilotCustomRoleUser('role-gains-eligibility', ['billing.manage']);
    $role = $staff->roles->first();

    $this->actingAs($staff)->get('/billing/invoices')->assertNotFound();

    $role->givePermissionTo('portal.operator');
    $this->actingAs(User::findOrFail($staff->id))->get('/billing/invoices')->assertOk();

    $role->revokePermissionTo('portal.operator');
    $this->actingAs(User::findOrFail($staff->id))->get('/billing/invoices')->assertNotFound();
});

it('is granted to a custom role only through the authorized role-management screens', function () {
    pilotMode('pilot');
    $operator = pilotOperator();

    // An authorized operator (roles.manage) creates the role with the eligibility, as for any other permission.
    $this->actingAs($operator)->post(route('roles.store'), ['name' => 'trusted-by-decision', 'permissions' => ['portal.operator', 'billing.manage']])
        ->assertRedirect();
    expect(Role::findByName('trusted-by-decision')->hasPermissionTo('portal.operator'))->toBeTrue();

    // An account that is merely eligible, without roles.manage, cannot hand it out; a customer cannot even reach the screen.
    $eligibleOnly = pilotCustomRoleUser('eligible-without-roles-manage', ['portal.operator', 'roles.view']);
    $this->actingAs($eligibleOnly)->post(route('roles.store'), ['name' => 'sneaky', 'permissions' => ['portal.operator']])->assertForbidden();
    $this->actingAs(pilotCustomer())->post(route('roles.store'), ['name' => 'sneaky2', 'permissions' => ['portal.operator']])->assertNotFound();
    expect(Role::where('name', 'like', 'sneaky%')->exists())->toBeFalse();

    // The built-in roles stay code-owned: the user role cannot be edited to carry it.
    $this->actingAs($operator)->put(route('roles.update', Role::findByName('user')), ['permissions' => ['portal.operator']])->assertSessionHasErrors('role');
    expect(Role::findByName('user')->hasPermissionTo('portal.operator'))->toBeFalse();
});

it('moves an account in and out of the customer boundary through the real role-assignment route', function () {
    pilotMode('pilot');
    $admin = pilotOperator();
    $target = pilotCustomer();

    $this->actingAs($target)->get('/admin/users')->assertNotFound();

    $this->actingAs($admin)->put(route('users.roles.update', $target), ['roles' => ['operator']])->assertRedirect();
    $this->actingAs(User::findOrFail($target->id))->get('/admin/users')->assertOk();

    $this->actingAs($admin)->put(route('users.roles.update', $target), ['roles' => ['user']])->assertRedirect();
    $this->actingAs(User::findOrFail($target->id))->get('/admin/users')->assertNotFound();
});

it('never lets a customer’s own request widen their eligibility', function () {
    pilotMode('pilot');
    $customer = pilotCustomer();

    // Role and permission management are operator routes: refused as absent, and nothing changes.
    $this->actingAs($customer)->put(route('users.roles.update', $customer), ['roles' => ['operator']])->assertNotFound();
    $this->actingAs($customer)->post(route('roles.store'), ['name' => 'self-grant', 'permissions' => ['portal.operator']])->assertNotFound();

    expect(User::findOrFail($customer->id)->hasRole('operator'))->toBeFalse()
        ->and(CustomerPilotGate::isCustomer(User::findOrFail($customer->id)))->toBeTrue();
});

it('keeps the last-administrator protection with eligibility in play', function () {
    $last = pilotOperator();
    pilotMode('pilot');

    $this->actingAs($last)
        ->putJson(route('users.roles.update', $last), ['roles' => []])
        ->assertStatus(409)
        ->assertJson(['message' => LastAccountAdministratorException::MESSAGE]);

    expect(User::findOrFail($last->id)->hasRole('operator'))->toBeTrue()
        ->and(CustomerPilotGate::isOperator(User::findOrFail($last->id)))->toBeTrue();
});
