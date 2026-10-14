<?php

/*
 * R0.9-CS1: the read-only readiness report (`php artisan customer-surface:preflight`). It reads committed state,
 * writes nothing, and names every account and custom role that needs an explicit eligibility decision.
 */

use App\Services\CustomerSurfacePreflight;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    CustomerPilotGate::forgetWarnings();
});

/** @return array<string, mixed> */
function preflightReport(?string $expect = null): array
{
    return app(CustomerSurfacePreflight::class)->report($expect);
}

it('passes on a correctly provisioned installation and writes nothing', function () {
    pilotOperator();
    pilotMode('pilot');
    $before = [DB::table('role_has_permissions')->count(), DB::table('model_has_roles')->count(), DB::table('permissions')->count(), DB::table('users')->count()];

    $report = preflightReport('pilot');

    expect($report['failures'])->toBe([])->and($report['warnings'])->toBe([])
        ->and($report['mode'])->toBe('pilot')->and($report['operators'])->toBe(1)
        ->and($report['unresolvedUsers'])->toBe([])->and($report['unresolvedRoles'])->toBe([])
        ->and([DB::table('role_has_permissions')->count(), DB::table('model_has_roles')->count(), DB::table('permissions')->count(), DB::table('users')->count()])->toBe($before);

    $this->artisan('customer-surface:preflight --expect-mode=pilot')->assertSuccessful();
});

it('fails when the application resolved a different mode than expected', function () {
    pilotOperator();
    pilotMode('closed');

    expect(preflightReport('pilot')['failures'])->toHaveCount(1)->and(preflightReport('pilot')['failures'][0])->toContain('"closed", not the expected "pilot"');
    $this->artisan('customer-surface:preflight --expect-mode=pilot')->assertFailed();
});

it('fails on legacy in an environment that does not honour it', function () {
    pilotOperator();
    app()->detectEnvironment(fn () => 'production');
    config(['portal.customer_surface_mode' => 'legacy']);

    $report = preflightReport();

    expect($report['mode'])->toBe('closed')->and(implode(' ', $report['failures']))->toContain('legacy')->toContain('production');
});

it('fails before roles:sync has created or granted the permission', function () {
    pilotOperator();
    $permission = Permission::findByName('portal.operator');

    DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $report = preflightReport();
    expect(implode(' ', $report['failures']))->toContain('operator role does not hold portal.operator')->toContain('No active account holds portal.operator');

    $permission->delete();
    expect(implode(' ', preflightReport()['failures']))->toContain('does not exist');
    $this->artisan('customer-surface:preflight')->assertFailed();
});

it('fails if the customer role has been given eligibility', function () {
    pilotOperator();
    DB::table('role_has_permissions')->insert([
        'permission_id' => Permission::findByName('portal.operator')->id,
        'role_id' => Role::findByName('user')->id,
    ]);

    expect(implode(' ', preflightReport()['failures']))->toContain('built-in user role holds portal.operator');
});

it('names accounts and custom roles that hold operational capabilities without eligibility, and clears them once decided', function () {
    pilotOperator();
    $viaRole = pilotCustomRoleUser('needs-a-decision', ['settings.view', 'tasks.view_all', 'tickets.view']);
    $direct = pilotCustomer();
    $direct->givePermissionTo('time.view_all');
    $suspended = pilotCustomRoleUser('suspended-holder', ['crm.manage'], ['disabled_at' => now()]);
    pilotCustomer(); // a plain customer is not listed

    $report = preflightReport();

    expect(collect($report['unresolvedUsers'])->pluck('id')->all())->toEqualCanonicalizing([$viaRole->id, $direct->id])
        ->and(collect($report['unresolvedUsers'])->firstWhere('id', $viaRole->id)['capabilities'])->toBe(['settings.view', 'tasks.view_all'])
        ->and(collect($report['unresolvedUsers'])->firstWhere('id', $direct->id)['roles'])->toBe(['user'])
        ->and(collect($report['unresolvedRoles'])->pluck('name')->all())->toEqualCanonicalizing(['needs-a-decision', 'suspended-holder'])
        ->and($report['warnings'])->toHaveCount(2)
        ->and($report['failures'])->toBe([]);

    // Warnings do not fail the command unless --strict asks for it.
    $this->artisan('customer-surface:preflight')->assertSuccessful();
    $this->artisan('customer-surface:preflight --strict')->assertFailed();

    // Deciding "yes" for the role and "no" (removing the grant) for the direct account clears both.
    Role::findByName('needs-a-decision')->givePermissionTo('portal.operator');
    Role::findByName('suspended-holder')->givePermissionTo('portal.operator');
    $direct->revokePermissionTo('time.view_all');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $cleared = preflightReport();
    expect($cleared['unresolvedUsers'])->toBe([])->and($cleared['unresolvedRoles'])->toBe([]);
    $this->artisan('customer-surface:preflight --strict')->assertSuccessful();
});

it('does not list the built-in roles, customers with only the customer defaults, or eligible accounts', function () {
    pilotOperator();
    pilotCustomRoleUser('only-customer-things', PermissionCatalogue::userDefaults());
    pilotCustomRoleUser('eligible-and-operational', ['portal.operator', 'billing.manage']);

    $report = preflightReport();

    expect($report['unresolvedUsers'])->toBe([])->and($report['unresolvedRoles'])->toBe([]);
});

it('prints a machine-readable report', function () {
    pilotOperator();
    pilotMode('pilot');

    $this->artisan('customer-surface:preflight --json --expect-mode=pilot')->assertSuccessful()
        ->expectsOutputToContain('"mode": "pilot"');
});
