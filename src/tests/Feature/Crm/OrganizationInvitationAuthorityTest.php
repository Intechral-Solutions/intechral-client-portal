<?php

/*
 * Release 1.0 (R1-3A): the table-based predicate OrganizationAuthority::isMembershipManager() must say what the policy
 * OrganizationPolicy::manageMembership says, for every kind of account. The invitation flow decides from the TABLES inside locked
 * transactions and queue jobs (never the permission cache, which can deadlock there and can be stale); issuance still asks the Gate. If the
 * two ever disagree, an operator could issue what acceptance then refuses, or the reverse. A change to either side that is not made to both
 * fails here (design record, 4.4, drift guard).
 */

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** One account per named case: [user, whether it is a membership manager]. */
function oiaAccount(string $case): array
{
    $user = User::factory()->create(['disabled_at' => $case === 'a suspended account holding both' ? now() : null]);
    $role = fn (string ...$permissions) => tap(Role::create(['name' => 'r-'.Str::random(8), 'guard_name' => 'web']), fn ($r) => $r->givePermissionTo($permissions));

    $manager = match ($case) {
        'the built-in operator role' => $user->assignRole('operator') && true,
        'a custom role carrying both' => $user->assignRole($role('portal.operator', 'crm.manage')) && true,
        'both granted directly' => $user->givePermissionTo(['portal.operator', 'crm.manage']) && true,
        'portal.operator directly and crm.manage through a role' => $user->givePermissionTo('portal.operator') && $user->assignRole($role('crm.manage')) && true,
        'portal.operator through one role and crm.manage through another' => $user->assignRole($role('portal.operator'), $role('crm.manage')) && true,
        'portal.operator alone, directly' => $user->givePermissionTo('portal.operator') && false,
        'portal.operator alone, through a role' => $user->assignRole($role('portal.operator')) && false,
        'crm.manage alone, directly' => $user->givePermissionTo('crm.manage') && false,
        'crm.manage alone, through a role' => $user->assignRole($role('crm.manage')) && false,
        'the operator role NAME without the capabilities' => (function () use ($user) {
            // A built-in `operator` whose capability rows were removed outside the application: the name alone is nothing.
            $operator = Role::findByName('operator', 'web');
            DB::table('role_has_permissions')->where('role_id', $operator->id)->whereIn('permission_id', Permission::whereIn('name', ['portal.operator', 'crm.manage'])->pluck('id'))->delete();
            $user->assignRole($operator);

            return false;
        })(),
        'crm.manage directly and the operator role NAME, without portal.operator' => (function () use ($user) {
            // The role NAME is not eligibility: crm.manage plus the name `operator` (its eligibility capability removed) is a customer to the policy.
            $operator = Role::findByName('operator', 'web');
            DB::table('role_has_permissions')->where('role_id', $operator->id)->where('permission_id', Permission::findByName('portal.operator')->id)->delete();
            $user->assignRole($operator);
            $user->givePermissionTo('crm.manage');

            return false;
        })(),
        'a suspended account holding both' => $user->givePermissionTo(['portal.operator', 'crm.manage']) && false,
        'a customer' => $user->assignRole('user') && false,
        'an account with no roles and no permissions' => false,
        'org.invite without the capabilities' => $user->givePermissionTo(['org.invite']) && false,
    };

    return [$user, $manager];
}

const OIA_CASES = [
    'the built-in operator role',
    'a custom role carrying both',
    'both granted directly',
    'portal.operator directly and crm.manage through a role',
    'portal.operator through one role and crm.manage through another',
    'portal.operator alone, directly',
    'portal.operator alone, through a role',
    'crm.manage alone, directly',
    'crm.manage alone, through a role',
    'the operator role NAME without the capabilities',
    'crm.manage directly and the operator role NAME, without portal.operator',
    'a suspended account holding both',
    'a customer',
    'an account with no roles and no permissions',
    'org.invite without the capabilities',
];

it('answers exactly what the manageMembership policy answers, from the tables alone', function (string $case) {
    [$user, $expected] = oiaAccount($case);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $viaTables = OrganizationAuthority::isMembershipManager($user->id);
    $viaGate = Gate::forUser(User::findOrFail($user->id))->allows('manageMembership', Organization::class);

    expect($viaTables)->toBe($viaGate)->and($viaTables)->toBe($expected);
})->with(OIA_CASES);

it('does not touch the permission cache when it answers', function () {
    [$user] = oiaAccount('a custom role carrying both');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::flushQueryLog();
    DB::enableQueryLog();
    OrganizationAuthority::isMembershipManager($user->id);
    OrganizationAuthority::membershipManagerIds([$user->id]);
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($log->filter(fn ($sql) => preg_match('/`(cache|cache_locks)`/', $sql) === 1))->toHaveCount(0)
        ->and($log->filter(fn ($sql) => preg_match('/^(insert|update|delete)\b|for update|lock in share mode/i', $sql) === 1))->toHaveCount(0);
});

it('answers the set version of the predicate in one place: ascending, ignoring unknown ids and duplicates', function () {
    [$a] = oiaAccount('both granted directly');
    [$b] = oiaAccount('a customer');
    [$c] = oiaAccount('a custom role carrying both');

    expect(OrganizationAuthority::membershipManagerIds([$c->id, $b->id, $a->id, $a->id, 999999]))->toBe([$a->id, $c->id])
        ->and(OrganizationAuthority::membershipManagerIds([]))->toBe([]);
});
