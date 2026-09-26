<?php

/*
 * EPIC-013 WP3: the shared-prop and Blade-shell half of the navigation contract (§12.4, §12.5).
 *
 * The builder's own coverage lives in NavigationBuilderTest. This file asserts the seams: what the
 * two renderers receive, that they receive the SAME payload from the one builder, that the retired
 * "Manage" grouping is gone from the payload and from the Blade shell, and that the account data
 * stays minimal.
 */

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Support\Initials;
use App\View\Composers\ShellComposer;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function shellActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/**
 * A route-resolved request bound into the container, as the Blade pipeline provides one.
 *
 * The resolvers are set AFTER the instance bind on purpose: binding `request` fires Laravel's auth
 * rebind handler, which replaces the user resolver with one backed by the guard.
 */
function bindShellRequest(User $user, string $routeName): Request
{
    $request = Request::create(route($routeName, [], false));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return $request;
}

it('shares the canonical navigation contract and the presentation discriminator', function () {
    $operator = shellActor('operator');

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            // A presentation family, never a role name.
            ->where('shell.presentation', 'operational')
            ->where('navigation.currentWorkspace', 'home')
            ->has('navigation.workspaces', 8)
            ->where('navigation.workspaces.0.key', 'home')
            ->where('navigation.workspaces.0.icon', 'house')
            ->where('navigation.workspaces.0.context', [])
            ->where('navigation.workspaces.0.presentation.operational.panel', null)
            ->where('navigation.workspaces.1.key', 'projects')
            ->where('navigation.workspaces.1.presentation.operational.panel', 'open'));
});

it('shares server-derived avatar initials and no further account data', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $user->assignRole('user');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.avatar.initials', 'AL')
            ->where('auth.user.avatar.url', null)
            ->missing('auth.roles')
            ->missing('auth.user.roles')
            ->missing('auth.user.organizations')
            ->missing('auth.user.password')
            ->missing('auth.user.two_factor_secret'));
});

it('keeps the retired Manage grouping out of the shared payload', function () {
    $operator = shellActor('operator');

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            // The payload is workspace-shaped: there is no group list to hold a "Manage" section.
            ->missing('navigation.0')
            ->has('navigation.workspaces')
            ->where('navigationLegacy', fn ($groups) => collect($groups)
                ->pluck('key')
                ->diff(['primary', 'overflow'])
                ->isEmpty())
            ->where('navigationLegacy', fn ($groups) => collect($groups)
                ->pluck('label')
                ->every(fn ($label) => $label === null)));
});

it('gives the Blade shell the same payload as the Inertia prop, from one builder', function () {
    $operator = shellActor('operator');
    $request = bindShellRequest($operator, 'operator.tickets.index');

    $view = view('layouts.partials.nav');
    app(ShellComposer::class)->compose($view);
    $data = $view->getData();

    expect($data['navigation'])->toBe(app(NavigationBuilder::class)->build($request))
        ->and($data['shell'])->toBe(['presentation' => 'operational'])
        ->and($data['navigation']['currentWorkspace'])->toBe('helpdesk')
        ->and($data['shellUser']['name'])->toBe($operator->name)
        ->and($data['shellUser']['avatar']['initials'])->toBe(Initials::from($operator->name))
        ->and($data['shellUser']['avatar']['url'])->toBeNull()
        ->and(collect($data['navigationLegacy'])->pluck('key')->all())->toBe(['primary', 'overflow']);
});

it('composes the Blade shell safely for an unauthenticated render', function () {
    // `errors/403` extends `layouts.app`, so the shell can render for a guest. Before WP3 the
    // partial's own `@php` call handled that; now the composer must.
    $request = Request::create(route('login', [], false));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => null);

    $view = view('layouts.partials.nav');
    app(ShellComposer::class)->compose($view);
    $data = $view->getData();

    expect($data['navigation'])->toBe(['currentWorkspace' => null, 'workspaces' => []])
        ->and($data['navigationLegacy'])->toBe([])
        ->and($data['shellUser'])->toBeNull()
        ->and($data['shell'])->toBe(['presentation' => 'operational']);
});

it('no longer builds navigation inside the view layer', function () {
    // EPIC-013 §12.3 rule 11: the builder has one entry point per renderer. A view that
    // instantiates it is a second source of navigation truth.
    $nav = (string) file_get_contents(resource_path('views/layouts/partials/nav.blade.php'));

    expect($nav)->not->toContain('NavigationBuilder')
        ->and($nav)->toContain('$navigationLegacy');
});

it('renders the Blade shell from the contract without a Manage heading', function () {
    $operator = shellActor('operator');

    $response = $this->actingAs($operator)->get(route('tickets.index'));

    $response->assertOk()
        // Every destination the pre-WP3 "Manage" group carried is still reachable.
        ->assertSee(route('operator.tickets.index'))
        ->assertSee(route('operator.time.index'))
        ->assertSee(route('organizations.index'))
        ->assertSee(route('operator.cms.index'))
        ->assertSee(route('users.index'))
        ->assertSee(route('roles.index'))
        // …and the grouping that used to hold them is gone.
        ->assertDontSee('Manage');
});

it('withholds from the Blade shell exactly what it withholds from the Inertia prop', function () {
    $user = shellActor('user');

    $this->actingAs($user)->get(route('tickets.index'))
        ->assertOk()
        ->assertDontSee(route('operator.tickets.index'))
        ->assertDontSee(route('users.index'))
        ->assertDontSee(route('roles.index'))
        ->assertDontSee(route('crm.companies.index'))
        ->assertDontSee(route('organizations.index'));
});
