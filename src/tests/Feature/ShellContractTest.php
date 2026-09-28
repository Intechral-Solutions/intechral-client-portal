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
            // WP3's flattened compatibility projection left the Inertia payload in WP4 and was deleted
            // outright in WP5, when the Blade shell began projecting `context` itself.
            ->missing('navigationLegacy'));
});

it('gives the Blade shell the same payload as the Inertia prop, from one builder', function () {
    $operator = shellActor('operator');
    $request = bindShellRequest($operator, 'operator.tickets.index');

    $view = view('layouts.app');
    app(ShellComposer::class)->compose($view);
    $data = $view->getData();

    expect($data['navigation'])->toBe(app(NavigationBuilder::class)->build($request))
        ->and(array_keys($data))->toBe(['navigation', 'shell', 'shellRoot', 'shellWorkspace', 'shellUser'])
        ->and($data['shell'])->toBe(['presentation' => 'operational'])
        ->and($data['navigation']['currentWorkspace'])->toBe('helpdesk')
        // The current workspace is the payload's own entry, looked up by the server's key.
        ->and($data['shellWorkspace'])->toBe(collect($data['navigation']['workspaces'])->firstWhere('key', 'helpdesk'))
        ->and($data['shellRoot'])->toBe(['workspace' => 'helpdesk', 'drawerDefault' => 'open', 'hasPanel' => true])
        ->and($data['shellUser']['name'])->toBe($operator->name)
        ->and($data['shellUser']['avatar']['initials'])->toBe(Initials::from($operator->name))
        ->and($data['shellUser']['avatar']['url'])->toBeNull();
});

it('derives the pre-paint inputs from the payload alone, with emptiness semantic', function () {
    $user = shellActor('user');

    // Home has no contextual navigation, so no panel and no default, for any presentation.
    bindShellRequest($user, 'dashboard');
    expect(ShellComposer::rootState(app(NavigationBuilder::class)->build(request())))
        ->toBe(['workspace' => 'home', 'drawerDefault' => null, 'hasPanel' => false]);

    // The G3 viewer surface is a single surface too.
    bindShellRequest($user, 'cms.index');
    expect(ShellComposer::rootState(app(NavigationBuilder::class)->build(request())))
        ->toBe(['workspace' => 'resources', 'drawerDefault' => null, 'hasPanel' => false]);

    // Tasks has views and a collapsed server default.
    bindShellRequest($user, 'tasks.index');
    expect(ShellComposer::rootState(app(NavigationBuilder::class)->build(request())))
        ->toBe(['workspace' => 'tasks', 'drawerDefault' => 'collapsed', 'hasPanel' => true]);

    // No workspace at all — a route that belongs to none, a guest, or no payload.
    bindShellRequest($user, 'profile.show');
    expect(ShellComposer::rootState(app(NavigationBuilder::class)->build(request())))
        ->toBe(['workspace' => null, 'drawerDefault' => null, 'hasPanel' => false])
        ->and(ShellComposer::rootState(['currentWorkspace' => null, 'workspaces' => []]))
        ->toBe(['workspace' => null, 'drawerDefault' => null, 'hasPanel' => false])
        ->and(ShellComposer::rootState(null))
        ->toBe(['workspace' => null, 'drawerDefault' => null, 'hasPanel' => false]);
});

it('composes the Blade shell safely for an unauthenticated render', function () {
    // `errors/403` extends `layouts.app`, so the shell can render for a guest.
    $request = Request::create(route('login', [], false));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => null);

    $view = view('layouts.app');
    app(ShellComposer::class)->compose($view);
    $data = $view->getData();

    expect($data['navigation'])->toBe(['currentWorkspace' => null, 'workspaces' => []])
        ->and($data['shellUser'])->toBeNull()
        ->and($data['shellWorkspace'])->toBeNull()
        ->and($data['shellRoot'])->toBe(['workspace' => null, 'drawerDefault' => null, 'hasPanel' => false])
        ->and($data['shell'])->toBe(['presentation' => 'operational'])
        ->and($data)->not->toHaveKey('navigationLegacy');
});

it('composes the Blade shell once per request, so the builder runs once', function () {
    $operator = shellActor('operator');
    $resolutions = 0;

    // The builder is not shared, and on a Blade response only ShellComposer resolves it (the Inertia
    // `navigation` prop is a lazy closure that a Blade response never evaluates), so each resolution
    // is exactly one composition and one build.
    app()->resolving(NavigationBuilder::class, function () use (&$resolutions) {
        $resolutions++;
    });

    $this->actingAs($operator)->get(route('operator.tickets.index'))->assertOk();

    expect($resolutions)->toBe(1);
});

it('no longer builds navigation inside the view layer', function () {
    // EPIC-013 §12.3 rule 11: the builder has one entry point per renderer. A view that instantiates
    // it is a second source of navigation truth — so no Blade view anywhere may name it.
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')
            && str_contains((string) file_get_contents($file->getPathname()), 'NavigationBuilder')) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});
