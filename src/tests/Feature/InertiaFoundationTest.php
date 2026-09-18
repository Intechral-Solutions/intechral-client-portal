<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('requires authentication for the inertia smoke page', function () {
    $this->get(route('inertia.smoke'))->assertRedirect('/login');
});

it('renders the authenticated inertia smoke page with minimal shared props', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('inertia.smoke'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('foundation/smoke')
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
        ->get(route('inertia.smoke'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => $permissions->contains('tickets.view'))
            ->missing('auth.roles'));
});

it('exposes redirect flash once through the shared contract', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('inertia.smoke.flash'))
        ->assertRedirect(route('inertia.smoke'));

    $this->get(route('inertia.smoke'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', 'Foundation flash message received.'));

    $this->get(route('inertia.smoke'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('flash.success', null));
});

it('leaves existing blade pages as normal document responses', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewIs('dashboard')
        ->assertSee(route('inertia.smoke'))
        ->assertHeader('Vary', 'X-Inertia');
});
