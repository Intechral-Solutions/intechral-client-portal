<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Guests ──────────────────────────────────────────────────────────────────

it('redirects guests from users index to login', function () {
    $this->get(route('users.index'))->assertRedirect('/login');
});

it('redirects guests from user show to login', function () {
    $user = User::factory()->create();
    $this->get(route('users.show', $user))->assertRedirect('/login');
});

// ── Unauthorised users ───────────────────────────────────────────────────────

it('returns 403 for users without users.view permission on index', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role has no users.view
    $this->actingAs($user)->get(route('users.index'))->assertForbidden();
});

it('returns 403 for users without users.view permission on show', function () {
    $viewer = User::factory()->create();
    $viewer->assignRole('user');
    $target = User::factory()->create();

    $this->actingAs($viewer)->get(route('users.show', $target))->assertForbidden();
});

it('returns 403 when updating roles without users.manage permission', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');
    $target = User::factory()->create();

    $this->actingAs($viewer)
        ->put(route('users.roles.update', $target), ['roles' => ['user']])
        ->assertForbidden();
});

// ── Operator access ──────────────────────────────────────────────────────────

it('allows operator to view users index', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $this->actingAs($operator)->get(route('users.index'))->assertOk();
});

it('lists users on the index page', function () {
    $operator = User::factory()->create(['name' => 'Admin User']);
    $operator->assignRole('operator');

    $member = User::factory()->create(['name' => 'Regular Member']);
    $member->assignRole('user');

    $this->actingAs($operator)
        ->get(route('users.index'))
        ->assertOk()
        ->assertSee('Admin User')
        ->assertSee('Regular Member');
});

it('filters users by search query', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    User::factory()->create(['name' => 'Alice Smith', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Jones', 'email' => 'bob@example.com']);

    $this->actingAs($operator)
        ->get(route('users.index', ['search' => 'Alice']))
        ->assertOk()
        ->assertSee('Alice Smith')
        ->assertDontSee('Bob Jones');
});

it('paginates users', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    // Create enough users to trigger pagination (> 25)
    User::factory()->count(26)->create();

    $this->actingAs($operator)
        ->get(route('users.index'))
        ->assertOk();
});

it('allows operator to view user show page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create(['name' => 'Target User']);
    $target->assignRole('user');

    $this->actingAs($operator)
        ->get(route('users.show', $target))
        ->assertOk()
        ->assertSee('Target User');
});

it('shows all available roles on user show page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();

    $this->actingAs($operator)
        ->get(route('users.show', $target))
        ->assertOk()
        ->assertSee('operator')
        ->assertSee('user');
});

// ── Role assignment ──────────────────────────────────────────────────────────

it('allows operator to assign a role to a user', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();

    $this->actingAs($operator)
        ->put(route('users.roles.update', $target), ['roles' => ['user']])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($target->fresh()->hasRole('user'))->toBeTrue();
});

it('allows operator to remove all roles from a user', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();
    $target->assignRole('user');

    $this->actingAs($operator)
        ->put(route('users.roles.update', $target), [])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($target->fresh()->roles->isEmpty())->toBeTrue();
});

it('validates role names exist when updating roles', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();

    $this->actingAs($operator)
        ->put(route('users.roles.update', $target), ['roles' => ['nonexistent-role']])
        ->assertSessionHasErrors('roles.*');
});

it('logs activity when user roles are updated', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();

    $this->actingAs($operator)
        ->put(route('users.roles.update', $target), ['roles' => ['user']]);

    expect(
        Activity::forSubject($target)
            ->where('description', 'updated user roles')
            ->exists()
    )->toBeTrue();
});

it('does not log activity when roles are unchanged', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $target = User::factory()->create();
    $target->assignRole('user');

    // Same roles — no change
    $this->actingAs($operator)
        ->put(route('users.roles.update', $target), ['roles' => ['user']]);

    expect(
        Activity::forSubject($target)
            ->where('description', 'updated user roles')
            ->exists()
    )->toBeFalse();
});

// ── 403 error page ────────────────────────────────────────────────────────────

it('renders the 403 page for forbidden requests', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('roles.index'))
        ->assertForbidden()
        ->assertSee('403');
});
