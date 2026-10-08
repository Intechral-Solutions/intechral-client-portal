<?php

/*
 * EPIC-017 WP1 (§14): the Focused navigation projection.
 *
 * `NavigationBuilder::build($request, ShellPresentation::Focused)` is a SUBSET of what the same
 * actor is offered Operationally: it derives every destination from a capability-filtered survivor,
 * can add no workspace, and carries no drawer content and no operational metadata. The default
 * (Operational) output is pinned untouched by `NavigationBuilderTest`.
 *
 * Helper names carry the `focusedNav` prefix (Pest loads every test file into one process).
 */

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/**
 * A user with a built-in role, extra direct permissions, and some customer defaults taken away.
 *
 * A default that comes from the built-in role cannot be withheld from that role without changing
 * every other user, so a user missing one holds a custom role carrying the defaults minus it.
 *
 * @param  list<string>  $give
 * @param  list<string>  $revoke
 */
function focusedNavActor(string $role = 'user', array $give = [], array $revoke = []): User
{
    $user = User::factory()->create();

    if ($revoke === []) {
        $user->assignRole($role);
    } else {
        $custom = Role::firstOrCreate(['name' => 'focused-nav-'.md5(implode(',', $revoke)), 'guard_name' => 'web']);
        $custom->syncPermissions(array_values(array_diff(PermissionCatalogue::userDefaults(), $revoke)));
        $user->assignRole($custom);
    }

    if ($give !== []) {
        $user->givePermissionTo($give);
    }

    return User::findOrFail($user->id);
}

function focusedNavBuild(User $user, ShellPresentation $presentation, string $routeName = 'dashboard'): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return app(NavigationBuilder::class)->build($request, $presentation);
}

/** @return list<string> */
function focusedNavLabels(array $navigation): array
{
    return array_column($navigation['workspaces'], 'label');
}

/** Every string value under a key, at any depth. @return list<string> */
function focusedNavValuesDeep(mixed $payload, string $field): array
{
    $found = [];

    if (is_array($payload)) {
        foreach ($payload as $key => $value) {
            if ($key === $field && is_string($value)) {
                $found[] = $value;
            }

            array_push($found, ...focusedNavValuesDeep($value, $field));
        }
    }

    return $found;
}

/** Every key name at any depth. @return list<string> */
function focusedNavKeysDeep(mixed $payload): array
{
    $found = [];

    if (is_array($payload)) {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $found[] = $key;
            }

            array_push($found, ...focusedNavKeysDeep($value));
        }
    }

    return $found;
}

/**
 * The personas the subset invariant is proven over, by name. Built inside the test body (never in a
 * dataset) so roles are seeded first. @return array<string, callable(): User>
 */
function focusedNavPersonaDefinitions(): array
{
    $personas = [
        'user' => fn () => focusedNavActor(),
        'operator' => fn () => focusedNavActor('operator'),
        'no permissions' => fn () => User::findOrFail(User::factory()->create()->id),
        'user without tickets.view' => fn () => focusedNavActor('user', [], [PermissionCatalogue::TICKETS_VIEW]),
        'user without billing.view' => fn () => focusedNavActor('user', [], [PermissionCatalogue::BILLING_VIEW]),
        'user without projects.view' => fn () => focusedNavActor('user', [], [PermissionCatalogue::PROJECTS_VIEW]),
        'user without cms.view' => fn () => focusedNavActor('user', [], [PermissionCatalogue::CMS_VIEW]),
        'user with cms.edit' => fn () => focusedNavActor('user', [PermissionCatalogue::CMS_EDIT]),
        'user with billing.manage' => fn () => focusedNavActor('user', [PermissionCatalogue::BILLING_MANAGE]),
    ];

    foreach (ShellPresentationResolver::OPERATIONAL as $capability) {
        $personas["user with {$capability}"] = fn () => focusedNavActor('user', [$capability]);
    }

    foreach (array_keys(ShellPresentationResolver::PRESENTATION_NEUTRAL) as $capability) {
        $personas["user with neutral {$capability}"] = fn () => focusedNavActor('user', [$capability]);
    }

    return $personas;
}

function focusedNavPersona(string $name): User
{
    return focusedNavPersonaDefinitions()[$name]();
}

it('projects the customer navigation: Home, Support, Projects, Invoices, Resources, in that order', function () {
    $navigation = focusedNavBuild(focusedNavActor(), ShellPresentation::Focused);

    expect(focusedNavLabels($navigation))->toBe(['Home', 'Support', 'Projects', 'Invoices', 'Resources'])
        ->and(array_column($navigation['workspaces'], 'href'))->toBe([route('dashboard'), route('tickets.index'), route('projects.index'), route('billing.client.invoices.index'), route('cms.index')])
        ->and(array_column($navigation['workspaces'], 'visit'))->toBe(['inertia', 'document', 'inertia', 'document', 'document'])
        ->and(array_column($navigation['workspaces'], 'key'))->toBe(['home', 'helpdesk', 'projects', 'finance', 'resources']);

    foreach ($navigation['workspaces'] as $workspace) {
        // The Focused shell has no drawer: no contextual content and no operational hint.
        expect($workspace['context'])->toBe([])
            ->and(array_keys($workspace['presentation']))->toBe(['focused'])
            ->and(array_keys($workspace))->toBe(['key', 'label', 'icon', 'href', 'visit', 'isActive', 'context', 'presentation']);
    }
});

it('leaves out Tasks, Time, Directory and System for every actor', function () {
    foreach ([focusedNavActor(), focusedNavActor('operator')] as $actor) {
        $labels = focusedNavLabels(focusedNavBuild($actor, ShellPresentation::Focused));

        expect($labels)->not->toContain('Tasks', 'Time', 'Directory', 'System', 'Helpdesk', 'Finance');
    }
});

it('removes an entry when its capability is removed, and never shows a placeholder', function (string $permission, string $label) {
    $actor = focusedNavActor('user', [], [$permission]);
    $labels = focusedNavLabels(focusedNavBuild($actor, ShellPresentation::Focused));

    expect($labels)->not->toContain($label)
        ->and($labels)->toContain('Home')
        ->and(count($labels))->toBe(4);
})->with([
    'tickets.view removes Support' => [PermissionCatalogue::TICKETS_VIEW, 'Support'],
    'projects.view removes Projects' => [PermissionCatalogue::PROJECTS_VIEW, 'Projects'],
    'billing.view removes Invoices' => [PermissionCatalogue::BILLING_VIEW, 'Invoices'],
    'cms.view removes Resources' => [PermissionCatalogue::CMS_VIEW, 'Resources'],
]);

it('offers only Home to an actor with no capability at all', function () {
    $navigation = focusedNavBuild(User::findOrFail(User::factory()->create()->id), ShellPresentation::Focused);

    expect(focusedNavLabels($navigation))->toBe(['Home']);
});

it('offers an actor who may edit pages no Resources entry, as the Operational rail does', function () {
    $actor = focusedNavActor('user', [PermissionCatalogue::CMS_EDIT]);

    expect(focusedNavLabels(focusedNavBuild($actor, ShellPresentation::Focused)))->not->toContain('Resources');
});

it('never points the Invoices entry at the operator invoice surface', function () {
    $actor = focusedNavActor('user', [PermissionCatalogue::BILLING_MANAGE]);
    $navigation = focusedNavBuild($actor, ShellPresentation::Focused);

    expect(focusedNavLabels($navigation))->not->toContain('Invoices')
        ->and(focusedNavValuesDeep($navigation, 'href'))->not->toContain('/billing/invoices');
});

it('is a subset of the Operational navigation, at most five long, for every persona', function (string $persona) {
    $actor = focusedNavPersona($persona);
    $operational = focusedNavBuild($actor, ShellPresentation::Operational);
    $focused = focusedNavBuild($actor, ShellPresentation::Focused);

    $operationalByKey = collect($operational['workspaces'])->keyBy('key');

    expect(count($focused['workspaces']))->toBeLessThanOrEqual(5);

    foreach ($focused['workspaces'] as $workspace) {
        expect($operationalByKey->has($workspace['key']))->toBeTrue("{$workspace['key']} is not an Operational workspace for this actor");

        // The destination is one the actor is already offered Operationally: the workspace's own
        // destination or one of its contextual items.
        $operationalWorkspace = $operationalByKey[$workspace['key']];
        $destinations = [[$operationalWorkspace['href'], $operationalWorkspace['visit']]];

        foreach ($operationalWorkspace['context'] as $section) {
            foreach ($section['items'] as $item) {
                $destinations[] = [$item['href'], $item['visit']];
            }
        }

        expect($destinations)->toContain([$workspace['href'], $workspace['visit']]);
    }
})->with(array_keys(focusedNavPersonaDefinitions()));

it('exposes no operator, admin, CRM or organization destination and no matching metadata', function (string $persona) {
    $navigation = focusedNavBuild(focusedNavPersona($persona), ShellPresentation::Focused);

    foreach (focusedNavValuesDeep($navigation, 'href') as $href) {
        foreach (['/operator', '/admin', '/crm', '/organizations'] as $forbidden) {
            expect(str_contains($href, $forbidden))->toBeFalse("{$href} contains {$forbidden}");
        }
    }

    // Matching input and drawer data are never serialized.
    expect(focusedNavKeysDeep($navigation))->not->toContain('routes', 'patterns', 'query', 'sections', 'surfaces', 'panel', 'surface');
})->with(array_keys(focusedNavPersonaDefinitions()));

it('marks the current entry from the server, and none on a route that belongs to no entry', function (string $route, ?string $current) {
    $navigation = focusedNavBuild(focusedNavActor(), ShellPresentation::Focused, $route);
    $active = array_values(array_map(fn (array $w) => $w['label'], array_filter($navigation['workspaces'], fn (array $w) => $w['isActive'])));

    expect($active)->toBe($current === null ? [] : [$current])
        ->and($navigation['currentWorkspace'])->toBe($current === null ? null : collect($navigation['workspaces'])->firstWhere('label', $current)['key']);
})->with([
    'dashboard' => ['dashboard', 'Home'],
    'ticket list' => ['tickets.index', 'Support'],
    'ticket detail' => ['tickets.show', 'Support'],
    'new ticket' => ['tickets.create', 'Support'],
    'project' => ['projects.show', 'Projects'],
    'board' => ['projects.board', 'Projects'],
    'project task' => ['projects.tasks.show', 'Projects'],
    'own invoices' => ['billing.client.invoices.index', 'Invoices'],
    'own invoice' => ['billing.client.invoices.show', 'Invoices'],
    'invoice payment' => ['billing.invoices.pay', 'Invoices'],
    'resources' => ['cms.index', 'Resources'],
    'a resource page' => ['cms.show', 'Resources'],
    'profile' => ['profile.show', null],
    'tasks (reachable, not in the nav)' => ['tasks.index', null],
    'time (reachable, not in the nav)' => ['time.index', null],
]);

it('leaves the default build Operational and byte-identical to the explicit Operational build', function () {
    $actor = focusedNavActor('operator');
    $request = Request::create('/');
    $request->setUserResolver(fn () => $actor);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('dashboard'));

    expect(app(NavigationBuilder::class)->build($request))
        ->toBe(app(NavigationBuilder::class)->build($request, ShellPresentation::Operational));
});

it('builds an empty navigation for a guest in either presentation', function () {
    $request = Request::create('/');
    $request->setUserResolver(fn () => null);

    foreach ([ShellPresentation::Operational, ShellPresentation::Focused] as $presentation) {
        expect(app(NavigationBuilder::class)->build($request, $presentation))
            ->toBe(['currentWorkspace' => null, 'workspaces' => []]);
    }
});

// ── Shared-prop privacy: what a Focused response carries ──────────────────

it('shares the Focused projection and an exact, operator-free prop set to a customer', function () {
    $user = focusedNavActor();
    $response = $this->actingAs($user)->get(route('profile.show'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('shell.presentation', 'focused')
        ->where('flash.bulk', null)
        ->where('navigation.workspaces.0.label', 'Home')
        ->has('navigation.workspaces', 5)
        ->where('navigation.currentWorkspace', null));

    $page = json_decode(json_encode($response->viewData('page')), true);
    $props = $page['props'];
    $shared = ['errors', 'app', 'auth', 'shell', 'navigation', 'flash'];
    $profileProps = ['profile', 'twoFactor', 'connectedAccounts', 'sessions'];

    // Exact: no operator count, queue size, feature flag or any other key rides along.
    expect(array_keys($props))->toEqualCanonicalizing([...$shared, ...$profileProps])
        ->and(array_keys($props['auth']))->toEqualCanonicalizing(['user', 'permissions'])
        ->and(array_keys($props['auth']['user']))->toEqualCanonicalizing(['id', 'name', 'email', 'avatar'])
        ->and(array_keys($props['auth']['user']['avatar']))->toEqualCanonicalizing(['initials', 'url'])
        ->and(array_keys($props['shell']))->toBe(['presentation'])
        ->and(array_keys($props['navigation']))->toEqualCanonicalizing(['currentWorkspace', 'workspaces'])
        ->and(array_keys($props['flash']))->toEqualCanonicalizing(['success', 'error', 'status', 'warning', 'bulk']);

    foreach ($props['navigation']['workspaces'] as $workspace) {
        expect(array_keys($workspace))->toBe(['key', 'label', 'icon', 'href', 'visit', 'isActive', 'context', 'presentation']);
    }
});

it('gives a mixed-capability user the Operational shell and the Operational navigation', function () {
    $user = focusedNavActor('user', [PermissionCatalogue::TASKS_VIEW_ALL]);

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shell.presentation', 'operational')
            ->where('navigation.workspaces.0.presentation.operational.panel', null));
});

it('leaves /tasks and /time reachable to a Focused customer: hidden navigation is not authorization', function () {
    $user = focusedNavActor();

    $this->actingAs($user)->get(route('tasks.index'))->assertOk();
    $this->actingAs(User::findOrFail($user->id))->get(route('time.index'))->assertOk();
});

it('refuses a Focused customer every operator, admin and CRM surface', function (string $routeName) {
    $this->actingAs(focusedNavActor())->get(route($routeName))->assertForbidden();
})->with([
    'operator queue' => ['operator.tickets.index'],
    'ticket reports' => ['operator.tickets.reports'],
    'users' => ['users.index'],
    'roles' => ['roles.index'],
    'directory' => ['crm.companies.index'],
    'organizations' => ['organizations.index'],
    'operator invoices' => ['billing.invoices.index'],
    'page editor' => ['operator.cms.index'],
    'operator time' => ['operator.time.index'],
]);
