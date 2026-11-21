<?php

/*
 * Release 1.0 (ORG-2, CP6 F-2): ProjectService decides whether an actor is an UNRESTRICTED operator (every link is theirs to change) from the TABLES, inside the transaction,
 * after the actor's row lock, with OrganizationAuthority::holdsOperatorCapability(). The Gate is no longer asked: its answer, given before the transaction from the permission
 * cache, could be older than a revocation that had already committed.
 *
 * (1) DRIFT. The table test is the SAME test the product applies everywhere else, CustomerPilotGate::isOperator(): for every kind of account, direct or through a role, they agree.
 * (2) STALE CACHE. An actor whose in-process permission cache still says "operator" after the capability was revoked and committed is a customer to the service: it may not
 *     see or remove a link it is not a member of, and may not link an Organization it does not belong to. (The two-connection version, and the documented window it cannot close,
 *     are in ProjectOrganizationConcurrencyTest.)
 */

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAuthority;
use App\Services\ProjectService;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('agrees with CustomerPilotGate::isOperator() for every kind of account', function () {
    $custom = Role::create(['name' => 'ops-custom', 'guard_name' => 'web'])->givePermissionTo('portal.operator');
    $lookalike = Role::create(['name' => 'operator-lookalike', 'guard_name' => 'web'])->givePermissionTo('crm.manage');

    $accounts = [
        'built-in operator role' => User::factory()->create()->assignRole('operator'),
        'direct portal.operator' => User::factory()->create()->givePermissionTo('portal.operator'),
        'a custom role carrying portal.operator' => User::factory()->create()->assignRole($custom),
        'crm.manage and a role without portal.operator' => User::factory()->create()->assignRole($lookalike),
        'only crm.manage' => User::factory()->create()->givePermissionTo('crm.manage'),
        'an ordinary customer' => User::factory()->create()->assignRole('user'),
        'no role at all' => User::factory()->create(),
    ];
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ($accounts as $label => $account) {
        expect(OrganizationAuthority::holdsOperatorCapability($account->id))->toBe(CustomerPilotGate::isOperator($account->fresh()), $label);
    }

    expect(OrganizationAuthority::holdsOperatorCapability(999999))->toBeFalse('an account that does not exist is not an operator');
});

it('reads the tables, not the permission cache: a revoked capability that the in-process cache still shows makes the actor a customer', function () {
    $operator = User::factory()->create()->givePermissionTo(['portal.operator', 'crm.manage']);
    $partner = Organization::factory()->create(['name' => 'Hidden Partner', 'portal_enabled_by' => $operator->id]);
    $project = app(ProjectService::class)->create($operator, ['name' => 'Stale', 'companies' => [$partner->id]]);

    expect($operator->can('portal.operator'))->toBeTrue('warm the cache');

    // The revocation commits (here, straight to the tables, as another process would) while this process's cache is unchanged.
    DB::table('model_has_permissions')->where('model_id', $operator->id)->delete();

    expect($operator->can('portal.operator'))->toBeTrue('the cache is stale: it still says operator')
        ->and(CustomerPilotGate::isOperator($operator))->toBeTrue()
        ->and(OrganizationAuthority::holdsOperatorCapability($operator->id))->toBeFalse();

    // As a customer, an empty submission removes nothing it cannot see: the link to an Organization it is not a member of stands.
    app(ProjectService::class)->syncOrganizations($project, $operator, []);
    expect(DB::table('project_organization')->where('project_id', $project->id)->pluck('organization_id')->all())->toBe([$partner->id]);

    // And it may not link an Organization it does not belong to, with the one message that does not say whether the Organization exists.
    $other = Organization::factory()->create(['name' => 'Not Mine', 'portal_enabled_by' => $operator->id]);
    expect(fn () => app(ProjectService::class)->syncOrganizations($project, $operator, [$other->id]))->toThrow(ValidationException::class, 'The selected organization is invalid.')
        ->and(fn () => app(ProjectService::class)->create($operator, ['name' => 'Nope', 'companies' => [$other->id]]))->toThrow(ValidationException::class, 'The selected organization is invalid.')
        ->and(DB::table('projects')->where('name', 'Nope')->exists())->toBeFalse();
});
