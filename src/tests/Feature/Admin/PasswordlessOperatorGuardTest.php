<?php

/*
 * Release 1.0 (operator MFA, owner ruling): operator accounts must have a LOCAL PASSWORD, because Fortify's enrolment stands behind password
 * confirmation and a passwordless (provider-only) account can neither confirm one nor obtain one (ResetUserPassword refuses it). So no role change may
 * newly make such an account operator-eligible. Only NEW eligibility is refused: an unrelated edit never trips over a passwordless operator that
 * already existed.
 */

use App\Exceptions\PasswordlessOperatorException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('operator');
});

function passwordless(string ...$roles): User
{
    $user = User::factory()->create(['password' => null]);
    foreach ($roles as $role) {
        $user->assignRole($role);
    }

    return $user;
}

function operatorCustomRole(string $name = 'platform-admin'): Role
{
    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
    $role->givePermissionTo('portal.operator');

    return $role;
}

describe('assigning roles to a user', function () {
    it('refuses the built-in operator role for an account with no local password, changing nothing', function () {
        $target = passwordless('user');

        $this->actingAs($this->admin)->put(route('users.roles.update', $target), ['roles' => ['operator']])
            ->assertSessionHasErrors('account');

        expect($target->fresh()->getRoleNames()->all())->toBe(['user'])
            ->and(Activity::where('description', 'updated user roles')->count())->toBe(0);
    });

    it('answers a JSON caller with 409 and the same message', function () {
        $target = passwordless('user');

        $this->actingAs($this->admin)->putJson(route('users.roles.update', $target), ['roles' => ['operator']])
            ->assertStatus(409)->assertJson(['message' => PasswordlessOperatorException::MESSAGE]);

        expect($target->fresh()->hasRole('operator'))->toBeFalse();
    });

    it('refuses a custom role that carries portal.operator just the same', function () {
        operatorCustomRole();
        $target = passwordless('user');

        $this->actingAs($this->admin)->putJson(route('users.roles.update', $target), ['roles' => ['platform-admin']])->assertStatus(409);

        expect($target->fresh()->hasRole('platform-admin'))->toBeFalse();
    });

    it('treats an empty password string as no password', function () {
        $target = User::factory()->create();
        DB::table('users')->where('id', $target->id)->update(['password' => '']);

        $this->actingAs($this->admin)->putJson(route('users.roles.update', $target), ['roles' => ['operator']])->assertStatus(409);
    });

    it('allows the operator role for an account with a local password', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->put(route('users.roles.update', $target), ['roles' => ['operator']])->assertSessionHasNoErrors();

        expect($target->fresh()->hasRole('operator'))->toBeTrue();
    });

    it('still lets a passwordless account hold ordinary roles', function () {
        $target = passwordless();

        $this->actingAs($this->admin)->put(route('users.roles.update', $target), ['roles' => ['user']])->assertSessionHasNoErrors();

        expect($target->fresh()->hasRole('user'))->toBeTrue();
    });

    it('does not block changes to a passwordless account that is already an operator (only NEW eligibility is refused)', function () {
        $legacy = passwordless('operator');

        // Swapping one operator-carrying role for another grants nothing new; dropping the role is always allowed.
        $this->actingAs($this->admin)->putJson(route('users.roles.update', $legacy), ['roles' => ['user']])->assertSessionHasNoErrors();

        expect($legacy->fresh()->hasRole('operator'))->toBeFalse();
    });

    it('lets a passwordless account that already holds operator keep it while gaining an ordinary role', function () {
        $legacy = passwordless('operator');

        $this->actingAs($this->admin)->putJson(route('users.roles.update', $legacy), ['roles' => ['operator', 'user']])->assertRedirect();

        expect($legacy->fresh()->getRoleNames()->sort()->values()->all())->toBe(['operator', 'user']);
    });
});

describe('editing a custom role', function () {
    it('refuses to add portal.operator to a role a passwordless account holds, and keeps the old permissions', function () {
        $role = Role::create(['name' => 'support-lead', 'guard_name' => 'web']);
        $role->givePermissionTo('tickets.view');
        $holder = passwordless('support-lead');

        $this->actingAs($this->admin)->put(route('roles.update', $role), ['permissions' => ['tickets.view', 'portal.operator']])
            ->assertSessionHasErrors('account');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect($role->fresh()->permissions->pluck('name')->all())->toBe(['tickets.view'])
            ->and($holder->fresh()->can('portal.operator'))->toBeFalse();
    });

    it('allows it when every holder has a local password', function () {
        $role = Role::create(['name' => 'support-lead', 'guard_name' => 'web']);
        User::factory()->create()->assignRole('support-lead');

        $this->actingAs($this->admin)->put(route('roles.update', $role), ['permissions' => ['tickets.view', 'portal.operator']])->assertSessionHasNoErrors();

        expect($role->fresh()->hasPermissionTo('portal.operator'))->toBeTrue();
    });

    it('does not trip over a passwordless operator that already existed when the edit adds nothing operator-related', function () {
        $role = operatorCustomRole('legacy-ops');
        passwordless('legacy-ops');

        $this->actingAs($this->admin)->put(route('roles.update', $role), ['permissions' => ['portal.operator', 'tickets.view']])->assertSessionHasNoErrors();

        expect($role->fresh()->hasPermissionTo('tickets.view'))->toBeTrue();
    });

    it('allows a role with no holders to gain portal.operator', function () {
        $role = Role::create(['name' => 'unused', 'guard_name' => 'web']);
        passwordless('user');

        $this->actingAs($this->admin)->put(route('roles.update', $role), ['permissions' => ['portal.operator']])->assertSessionHasNoErrors();

        expect($role->fresh()->hasPermissionTo('portal.operator'))->toBeTrue();
    });
});
