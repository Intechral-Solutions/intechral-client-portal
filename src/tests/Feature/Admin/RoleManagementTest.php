<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Guests ─────────────────────────────────────────────────────────────────

it('redirects guests from roles index to login', function () {
    $this->get(route('roles.index'))->assertRedirect('/login');
});

it('redirects guests from role create to login', function () {
    $this->get(route('roles.create'))->assertRedirect('/login');
});

// ── Unauthorised users ──────────────────────────────────────────────────────

it('returns 403 for users without roles.view on index', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role has no roles.view
    $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
});

it('returns 403 for users with only roles.view trying to create a role', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('roles.view', 'web');
    $user->givePermissionTo('roles.view');
    $this->actingAs($user)->get(route('roles.create'))->assertForbidden();
});

// ── Operator access ─────────────────────────────────────────────────────────

it('allows operator to view roles index', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $this->actingAs($operator)->get(route('roles.index'))->assertOk();
});

it('shows all roles on the index page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->get(route('roles.index'))
        ->assertOk()
        ->assertSee('operator')
        ->assertSee('user');
});

it('allows operator to view the create role form', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $this->actingAs($operator)->get(route('roles.create'))->assertOk();
});

it('allows operator to create a new role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->post(route('roles.store'), [
            'name' => 'support-agent',
            'permissions' => ['tickets.view', 'tickets.create'],
        ])
        ->assertRedirect(route('roles.index'))
        ->assertSessionHas('status');

    $role = Role::findByName('support-agent');
    expect($role)->not->toBeNull();
    expect($role->hasPermissionTo('tickets.view'))->toBeTrue();
    expect($role->hasPermissionTo('tickets.create'))->toBeTrue();
});

it('validates role name is unique on store', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->post(route('roles.store'), ['name' => 'operator'])
        ->assertSessionHasErrors('name');
});

it('validates role name is required on store', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->post(route('roles.store'), ['name' => ''])
        ->assertSessionHasErrors('name');
});

it('allows operator to edit a role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

    $this->actingAs($operator)
        ->get(route('roles.edit', $role))
        ->assertOk()
        ->assertSee('editor');
});

it('allows operator to update role permissions', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);

    $this->actingAs($operator)
        ->put(route('roles.update', $role), [
            'permissions' => ['tickets.view'],
        ])
        ->assertRedirect(route('roles.index'))
        ->assertSessionHas('status');

    expect($role->fresh()->hasPermissionTo('tickets.view'))->toBeTrue();
});

it('allows operator to delete a custom role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $role = Role::create(['name' => 'temp-role', 'guard_name' => 'web']);

    $this->actingAs($operator)
        ->delete(route('roles.destroy', $role))
        ->assertRedirect(route('roles.index'))
        ->assertSessionHas('status');

    expect(Role::where('name', 'temp-role')->exists())->toBeFalse();
});

// ── Built-in role protection ─────────────────────────────────────────────────

it('prevents deleting the built-in operator role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $operatorRole = Role::findByName('operator');

    $this->actingAs($operator)
        ->delete(route('roles.destroy', $operatorRole))
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    expect(Role::where('name', 'operator')->exists())->toBeTrue();
});

it('prevents deleting the built-in user role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $userRole = Role::findByName('user');

    $this->actingAs($operator)
        ->delete(route('roles.destroy', $userRole))
        ->assertRedirect()
        ->assertSessionHasErrors('role');
});

it('prevents deleting a role that has assigned users', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $role = Role::create(['name' => 'assigned-role', 'guard_name' => 'web']);

    $member = User::factory()->create();
    $member->assignRole('assigned-role');

    $this->actingAs($operator)
        ->delete(route('roles.destroy', $role))
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    expect(Role::where('name', 'assigned-role')->exists())->toBeTrue();
});

// ── Activity logging ─────────────────────────────────────────────────────────

it('logs activity when a role is created', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->post(route('roles.store'), ['name' => 'logged-role']);

    $role = Role::findByName('logged-role');
    expect(
        Activity::forSubject($role)
            ->where('description', 'created role')
            ->exists()
    )->toBeTrue();
});

it('logs activity when a role is deleted', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $role = Role::create(['name' => 'doomed-role', 'guard_name' => 'web']);

    $this->actingAs($operator)
        ->delete(route('roles.destroy', $role));

    expect(
        Activity::where('description', 'deleted role "doomed-role"')
            ->exists()
    )->toBeTrue();
});
