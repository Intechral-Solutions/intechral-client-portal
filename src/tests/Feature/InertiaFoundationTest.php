<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// FLIPPED IN EPIC-017 WP1: a plain `user` now resolves to the Focused customer shell, so /dashboard
// renders the customer Home and the presentation is `focused`. The shared-prop contract asserted below
// is unchanged; the operator dashboard is pinned by DashboardInertiaTest.
it('renders an authenticated production inertia page with minimal shared props', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/customer-home')
            ->where('app.name', config('app.name'))
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->has('auth.permissions')
            // EPIC-013 §12.5: the canonical workspace contract, the presentation discriminator and
            // the server-derived avatar. Their own coverage is in ShellContractTest.
            ->has('navigation.workspaces')
            ->has('navigation.currentWorkspace')
            ->where('shell.presentation', 'focused')
            ->has('auth.user.avatar')
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

    // Projects index/create/edit are React pages as of EPIC-011E WP3, and tickets.index as of EPIC-017
    // WP2; the client invoice list is still Blade, so it is the coexistence example here.
    $this->actingAs($user)
        ->get(route('billing.client.invoices.index'))
        ->assertOk()
        ->assertViewIs('billing.client.index')
        ->assertSee(route('dashboard'))
        ->assertHeader('Vary', 'X-Inertia');
});
