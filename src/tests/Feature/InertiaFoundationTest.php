<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('renders an authenticated production inertia page with minimal shared props', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->where('app.name', config('app.name'))
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->has('auth.permissions')
            ->has('navigation')
            ->has('flash')
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes'));
});

it('shares effective permissions but not role models', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => $permissions->contains('tickets.view'))
            ->missing('auth.roles'));
});

it('exposes flash once through the shared contract', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('user-profile-information.update'), [
            'name' => $user->name,
            'email' => $user->email,
        ])
        ->assertRedirect();

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.status', 'Profile information updated.'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.status', null));
});

it('leaves existing blade pages as normal document responses', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('projects.index'))
        ->assertOk()
        ->assertViewIs('projects.index')
        ->assertSee(route('dashboard'))
        ->assertHeader('Vary', 'X-Inertia');
});
