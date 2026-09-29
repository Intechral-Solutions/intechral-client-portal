<?php

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/**
 * Build the payload as the real request pipeline would: a route-resolved request with a resolved
 * user, so `$request->routeIs()` and `$user->can()` answer exactly as they do in production.
 */
function navigationFor(User $user, string $routeName, array $parameters = [], array $query = []): array
{
    $uri = route($routeName, $parameters, false);
    $request = Request::create($query === [] ? $uri : $uri.'?'.http_build_query($query));
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return app(NavigationBuilder::class)->build($request);
}

/** @return array<string, array<string, mixed>> Workspaces keyed by their stable key. */
function workspaces(array $navigation): array
{
    return collect($navigation['workspaces'])->keyBy('key')->all();
}

/** @return array<string, array<string, mixed>> Every context item of one workspace, keyed. */
function contextItems(array $workspace): array
{
    return collect($workspace['context'])->flatMap(fn (array $section) => $section['items'])->keyBy('key')->all();
}

/**
 * Every string value the payload carries under the given key, at ANY depth.
 *
 * The contract is nested, so a leakage assertion that walks one convenient path proves nothing.
 * This flattens the whole structure, which is what makes "absent everywhere" assertable and future
 * accidental leakage hard: a forbidden destination cannot hide one level deeper than the assertion
 * happened to look.
 *
 * @return array<int, string>
 */
function navigationValuesDeep(mixed $payload, string $field): array
{
    $found = [];

    if (is_array($payload)) {
        foreach ($payload as $key => $value) {
            if ($key === $field && is_string($value)) {
                $found[] = $value;
            }

            $found = array_merge($found, navigationValuesDeep($value, $field));
        }
    }

    return $found;
}

/** Every entry marked active anywhere in the payload, as `key` values. */
function navigationActiveKeysDeep(mixed $payload): array
{
    $found = [];

    if (is_array($payload)) {
        if (($payload['isActive'] ?? null) === true && isset($payload['key'])) {
            $found[] = $payload['key'];
        }

        foreach ($payload as $value) {
            $found = array_merge($found, navigationActiveKeysDeep($value));
        }
    }

    return $found;
}

/** Every array key used anywhere in the payload. */
function navigationKeyNamesDeep(mixed $payload): array
{
    $found = [];

    if (is_array($payload)) {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $found[] = $key;
            }

            $found = array_merge($found, navigationKeyNamesDeep($value));
        }
    }

    return array_values(array_unique($found));
}

/** Drop `presentation` everywhere, to prove hints carry no authority (§12.3 rule 4). */
function withoutPresentation(array $navigation): array
{
    $navigation['workspaces'] = array_map(function (array $workspace) {
        unset($workspace['presentation']);

        return $workspace;
    }, $navigation['workspaces']);

    return $navigation;
}

function actor(string $role, array $extraPermissions = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    foreach ($extraPermissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user->fresh();
}

/**
 * An actor whose capabilities are exactly the given catalogue permissions, granted through a real
 * Spatie role so authorization resolves on the production path. Used where the matrix needs a
 * combination the two built-in roles do not provide — never a toy role that bypasses the gate.
 *
 * @param  array<int, string>  $permissions
 */
function actorWithPermissions(array $permissions): User
{
    $role = Role::create(['name' => 'matrix-'.Str::lower(Str::random(12)), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** The built-in `user` role's capabilities, minus the named ones. */
function userDefaultsWithout(string ...$permissions): User
{
    return actorWithPermissions(array_values(array_diff(PermissionCatalogue::userDefaults(), $permissions)));
}

// ─────────────────────────────────────────────────────────────────────────────
// Shape and ordering
// ─────────────────────────────────────────────────────────────────────────────

it('returns the canonical contract shape with stable workspace keys in a deterministic order', function () {
    $operator = actor('operator');
    $navigation = navigationFor($operator, 'dashboard');

    expect(array_keys($navigation))->toBe(['currentWorkspace', 'workspaces'])
        ->and(collect($navigation['workspaces'])->pluck('key')->all())->toBe([
            'home', 'projects', 'tasks', 'helpdesk', 'time', 'directory', 'finance', 'system',
        ]);

    foreach ($navigation['workspaces'] as $workspace) {
        expect(array_keys($workspace))->toBe([
            'key', 'label', 'icon', 'href', 'visit', 'isActive', 'context', 'presentation',
        ]);

        foreach ($workspace['context'] as $section) {
            expect(array_keys($section))->toBe(['key', 'label', 'kind', 'items']);

            foreach ($section['items'] as $item) {
                expect(array_keys($item))->toBe(['key', 'label', 'href', 'visit', 'isActive', 'count'])
                    ->and($item['count'])->toBeNull();
            }
        }
    }
});

it('orders workspaces identically regardless of which entries an actor may see', function () {
    $canonical = ['home', 'projects', 'tasks', 'helpdesk', 'time', 'directory', 'finance', 'system', 'resources'];

    foreach ([actor('operator'), actor('user'), actor('user', ['crm.manage']), userDefaultsWithout('projects.view')] as $subject) {
        $keys = collect(navigationFor($subject, 'dashboard')['workspaces'])->pluck('key')->all();

        expect($keys)->toBe(array_values(array_filter($canonical, fn (string $key) => in_array($key, $keys, true))));
    }
});

it('drops the dead pre-WP3 fields and never serializes route patterns', function () {
    $keys = navigationKeyNamesDeep(navigationFor(actor('operator'), 'projects.index'));

    expect($keys)->not->toContain('method')
        ->and($keys)->not->toContain('activePatterns')
        ->and($keys)->not->toContain('patterns')
        ->and($keys)->not->toContain('children')
        ->and($keys)->not->toContain('items_legacy');
});

it('returns an empty payload for guests', function () {
    $request = Request::create('/login');

    expect(app(NavigationBuilder::class)->build($request))
        ->toBe(['currentWorkspace' => null, 'workspaces' => []]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Presentation neutrality (L17)
// ─────────────────────────────────────────────────────────────────────────────

it('keeps contextual content free of shell vocabulary and of unemitted section kinds', function () {
    foreach ([actor('operator'), actor('user')] as $subject) {
        foreach (['dashboard', 'projects.index', 'tasks.index'] as $route) {
            $navigation = navigationFor($subject, $route);

            foreach ($navigation['workspaces'] as $workspace) {
                $names = navigationKeyNamesDeep($workspace['context']);

                expect($names)->not->toContain('drawer')
                    ->and($names)->not->toContain('rail')
                    ->and($names)->not->toContain('panel');

                foreach ($workspace['context'] as $section) {
                    expect($section['kind'])->toBeIn(['views', 'queues', 'entities', 'saved', 'actions'])
                        // No route or endpoint provides these, so nothing may claim they exist.
                        ->and($section['kind'])->not->toBeIn(['entities', 'saved']);
                }
            }
        }
    }
});

it('gives presentation hints no authority over the authorized model', function () {
    foreach ([actor('operator'), actor('user')] as $subject) {
        $full = navigationFor($subject, 'projects.index');
        $stripped = withoutPresentation($full);

        // Same workspaces, same context sections, same items, same active state.
        expect($stripped['currentWorkspace'])->toBe($full['currentWorkspace'])
            ->and(collect($stripped['workspaces'])->map(fn (array $w) => [
                $w['key'], $w['href'], $w['visit'], $w['isActive'], $w['context'],
            ])->all())->toBe(collect($full['workspaces'])->map(fn (array $w) => [
                $w['key'], $w['href'], $w['visit'], $w['isActive'], $w['context'],
            ])->all());
    }
});

it('namespaces presentation hints per family and bounds the panel default', function () {
    $navigation = navigationFor(actor('operator'), 'dashboard');

    foreach ($navigation['workspaces'] as $workspace) {
        expect(array_keys($workspace['presentation']))->toBe(['operational'])
            ->and(array_keys($workspace['presentation']['operational']))->toBe(['panel'])
            ->and($workspace['presentation']['operational']['panel'])->toBeIn(['open', 'collapsed', null]);
    }
});

it('does not vary presentation hints by capability', function () {
    $operator = workspaces(navigationFor(actor('operator'), 'dashboard'));
    $user = workspaces(navigationFor(actor('user'), 'dashboard'));

    foreach (array_intersect(array_keys($operator), array_keys($user)) as $key) {
        expect($user[$key]['presentation'])->toBe($operator[$key]['presentation']);
    }
});

it('treats emptiness semantically, with the hint agreeing rather than implying it', function () {
    $home = workspaces(navigationFor(actor('operator'), 'dashboard'))['home'];

    expect($home['context'])->toBe([])
        ->and($home['presentation']['operational']['panel'])->toBeNull()
        ->and($home['href'])->toBe(route('dashboard'));

    // A workspace WITH contextual navigation carries a real default.
    $projects = workspaces(navigationFor(actor('operator'), 'dashboard'))['projects'];

    expect($projects['context'])->not->toBe([])
        ->and($projects['presentation']['operational']['panel'])->toBe('open');
});

// ─────────────────────────────────────────────────────────────────────────────
// Capability filtering and recursive leakage
// ─────────────────────────────────────────────────────────────────────────────

it('gives an operator every workspace except the transitional viewer surface', function () {
    $keys = array_keys(workspaces(navigationFor(actor('operator'), 'dashboard')));

    expect($keys)->toContain('home', 'projects', 'tasks', 'helpdesk', 'time', 'directory', 'finance', 'system')
        // G3: an actor who can edit reaches the same content through System → Pages.
        ->and($keys)->not->toContain('resources');
});

it('withholds System and Directory from a user-role actor', function () {
    $keys = array_keys(workspaces(navigationFor(actor('user'), 'dashboard')));

    expect($keys)->toBe(['home', 'projects', 'tasks', 'helpdesk', 'time', 'finance', 'resources'])
        ->and($keys)->not->toContain('system')
        ->and($keys)->not->toContain('directory');
});

it('never leaks an operator or administrative destination to a user-role actor', function () {
    $user = actor('user');

    foreach (['dashboard', 'tickets.index', 'tasks.index', 'time.index', 'cms.index', 'projects.index'] as $route) {
        $navigation = navigationFor($user, $route);
        $hrefs = navigationValuesDeep($navigation, 'href');
        $keys = navigationValuesDeep($navigation, 'key');

        expect($hrefs)->not->toBeEmpty();

        foreach ($hrefs as $href) {
            expect($href)
                ->not->toContain('/admin/')
                ->not->toContain('/operator/')
                ->not->toContain('/crm/')
                ->not->toContain('/organizations');
        }

        foreach (['system', 'system.users', 'system.roles', 'system.pages', 'directory',
            'directory.people', 'directory.organizations', 'directory.portal-access',
            'helpdesk.queue', 'helpdesk.reports', 'time.reports', 'finance.invoices'] as $forbidden) {
            expect($keys)->not->toContain($forbidden);
        }
    }
});

it('filters contextual entries recursively by sub-capability', function () {
    $agent = workspaces(navigationFor(actor('user', ['tickets.assign']), 'dashboard'));
    $plain = workspaces(navigationFor(actor('user'), 'dashboard'));

    expect(array_keys(contextItems($agent['helpdesk'])))->toBe([
        'helpdesk.requests', 'helpdesk.queue', 'helpdesk.reports',
    ])->and(array_keys(contextItems($plain['helpdesk'])))->toBe(['helpdesk.requests']);

    $reporter = workspaces(navigationFor(actor('user', ['time.view_all']), 'dashboard'));

    expect(array_keys(contextItems($reporter['time'])))->toBe(['time.mine', 'time.allocation', 'time.reports'])
        ->and(array_keys(contextItems($plain['time'])))->toBe(['time.mine', 'time.allocation']);

    $manager = workspaces(navigationFor(actor('user', ['projects.manage']), 'dashboard'));

    expect(array_keys(contextItems($manager['projects'])))->toBe(['projects.all', 'projects.create'])
        ->and(array_keys(contextItems($plain['projects'])))->toBe(['projects.all']);
});

it('emits System with only the sections each administrative capability permits', function (
    array $extra,
    array $expectedItems,
) {
    $navigation = workspaces(navigationFor(actor('user', $extra), 'dashboard'));

    if ($expectedItems === []) {
        expect($navigation)->not->toHaveKey('system');

        return;
    }

    expect(array_keys(contextItems($navigation['system'])))->toBe($expectedItems)
        // The workspace destination is always the first surviving entry, never a filtered-out one.
        ->and($navigation['system']['href'])->toBe(contextItems($navigation['system'])[$expectedItems[0]]['href']);
})->with([
    'no administrative capability' => [[], []],
    'users.view only' => [['users.view'], ['system.users']],
    'roles.view only' => [['roles.view'], ['system.roles']],
    'cms.edit only' => [['cms.edit'], ['system.pages']],
    'users.view and cms.edit' => [['users.view', 'cms.edit'], ['system.users', 'system.pages']],
    'all three' => [['users.view', 'roles.view', 'cms.edit'], ['system.users', 'system.roles', 'system.pages']],
]);

it('omits a workspace entirely rather than emitting it empty', function () {
    // No billing capability at all: Finance has no permitted destination and must not appear
    // disabled, empty, or with a section that survived its last item.
    $subject = userDefaultsWithout('billing.view');
    $navigation = navigationFor($subject, 'dashboard');

    expect(workspaces($navigation))->not->toHaveKey('finance')
        ->and(navigationValuesDeep($navigation, 'href'))->not->toContain(route('billing.client.invoices.index'));

    foreach ($navigation['workspaces'] as $workspace) {
        foreach ($workspace['context'] as $section) {
            expect($section['items'])->not->toBeEmpty();
        }
    }
});

it('never resolves a workspace default destination to an inaccessible item', function () {
    foreach ([
        actor('operator'),
        actor('user'),
        actor('user', ['tickets.assign']),
        actor('user', ['time.view_all']),
        actor('user', ['crm.manage']),
        actor('user', ['cms.edit']),
        actor('user', ['roles.view']),
    ] as $subject) {
        $navigation = navigationFor($subject, 'dashboard');

        foreach ($navigation['workspaces'] as $workspace) {
            $destinations = collect($workspace['context'])
                ->reject(fn (array $section) => $section['kind'] === 'actions')
                ->flatMap(fn (array $section) => $section['items'])
                ->pluck('href')
                ->all();

            if ($destinations === []) {
                // A single-surface workspace supplies its own destination.
                expect($workspace['context'])->toBe([]);

                continue;
            }

            expect($workspace['href'])->toBe($destinations[0]);
        }
    }
});

it('preserves the Finance capability branch verbatim', function () {
    $operator = workspaces(navigationFor(actor('operator'), 'dashboard'))['finance'];
    $client = workspaces(navigationFor(actor('user'), 'dashboard'))['finance'];

    expect($operator['href'])->toBe(route('billing.invoices.index'))
        ->and(array_keys(contextItems($operator)))->toBe(['finance.invoices'])
        ->and($client['href'])->toBe(route('billing.client.invoices.index'))
        ->and(array_keys(contextItems($client)))->toBe(['finance.my-invoices'])
        // billing.manage wins over billing.view, as it did before the reshape.
        ->and(navigationValuesDeep($operator, 'href'))->not->toContain(route('billing.client.invoices.index'));
});

it('routes a workspace to the only entry its capabilities permit', function () {
    // tickets.assign without tickets.view, and time.view_all without time.log: both were reachable
    // before the reshape through the "Manage" group and must stay reachable now.
    $queueOnly = actorWithPermissions(['tickets.assign']);

    $helpdesk = workspaces(navigationFor($queueOnly, 'dashboard'))['helpdesk'];

    expect($helpdesk['href'])->toBe(route('operator.tickets.index'))
        ->and(array_keys(contextItems($helpdesk)))->toBe(['helpdesk.queue', 'helpdesk.reports']);

    $reportsOnly = actorWithPermissions(['time.view_all']);

    $time = workspaces(navigationFor($reportsOnly, 'dashboard'))['time'];

    expect($time['href'])->toBe(route('operator.time.index'))
        ->and(array_keys(contextItems($time)))->toBe(['time.reports']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Active state — including the three A1.10 collisions
// ─────────────────────────────────────────────────────────────────────────────

it('marks exactly one workspace and at most one contextual item active', function (
    string $route,
    array $query,
    ?string $workspace,
    ?string $item,
) {
    $navigation = navigationFor(actor('operator'), $route, [], $query);
    $active = navigationActiveKeysDeep($navigation);
    $expected = array_values(array_filter([$workspace, $item]));

    expect($navigation['currentWorkspace'])->toBe($workspace)
        ->and($active)->toBe($expected);
})->with([
    'dashboard' => ['dashboard', [], 'home', null],
    'projects index' => ['projects.index', [], 'projects', 'projects.all'],
    // A1.10 collision 3: "New project" is an action, so it never carries active state and only
    // "All projects" survives.
    'projects create' => ['projects.create', [], 'projects', 'projects.all'],
    'tasks default' => ['tasks.index', [], 'tasks', 'tasks.mine'],
    'tasks org view' => ['tasks.index', ['view' => 'org'], 'tasks', 'tasks.org'],
    // An unrecognized value clamps to "mine" in TaskController; navigation agrees.
    'tasks unknown view' => ['tasks.index', ['view' => 'nonsense'], 'tasks', 'tasks.mine'],
    'customer tickets' => ['tickets.index', [], 'helpdesk', 'helpdesk.requests'],
    'operator ticket queue' => ['operator.tickets.index', [], 'helpdesk', 'helpdesk.queue'],
    // A1.10 collision 1: `operator.tickets.*` used to match this route for the Queue entry too.
    'operator ticket reports' => ['operator.tickets.reports', [], 'helpdesk', 'helpdesk.reports'],
    'time index' => ['time.index', [], 'time', 'time.mine'],
    // A1.10 collision 2: `time.*` used to match the allocation route for My time too.
    'time allocation' => ['time.allocation', [], 'time', 'time.allocation'],
    'time reports' => ['operator.time.index', [], 'time', 'time.reports'],
    'crm contacts' => ['crm.contacts.index', [], 'directory', 'directory.people'],
    'crm companies' => ['crm.companies.index', [], 'directory', 'directory.organizations'],
    'organizations' => ['organizations.index', [], 'directory', 'directory.portal-access'],
    'operator invoices' => ['billing.invoices.index', [], 'finance', 'finance.invoices'],
    'admin users' => ['users.index', [], 'system', 'system.users'],
    'admin roles' => ['roles.index', [], 'system', 'system.roles'],
    'operator cms' => ['operator.cms.index', [], 'system', 'system.pages'],
    // A route inside no workspace: nothing is active and nothing is guessed.
    'profile' => ['profile.show', [], null, null],
]);

it('keeps an item active on the nested routes that belong to it', function () {
    $operator = actor('operator');

    expect(navigationActiveKeysDeep(navigationFor($operator, 'projects.board', ['project' => 1])))
        ->toBe(['projects', 'projects.all'])
        ->and(navigationActiveKeysDeep(navigationFor($operator, 'projects.milestones.index', ['project' => 1])))
        ->toBe(['projects', 'projects.all'])
        ->and(navigationActiveKeysDeep(navigationFor($operator, 'tickets.create')))
        ->toBe(['helpdesk', 'helpdesk.requests'])
        ->and(navigationActiveKeysDeep(navigationFor($operator, 'roles.edit', ['role' => 1])))
        ->toBe(['system', 'system.roles']);
});

it('resolves the client Finance branch active state on its own destination', function () {
    expect(navigationActiveKeysDeep(navigationFor(actor('user'), 'billing.client.invoices.index')))
        ->toBe(['finance', 'finance.my-invoices']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Visit / rendering mode
// ─────────────────────────────────────────────────────────────────────────────

it('preserves the visit mode of every destination through the reshape', function () {
    $navigation = workspaces(navigationFor(actor('operator'), 'dashboard'));

    $expected = [
        'home' => 'inertia',
        'projects' => 'inertia',
        'tasks' => 'inertia',
        'helpdesk' => 'document',
        'time' => 'inertia',
        'directory' => 'document',
        'finance' => 'document',
        'system' => 'document',
    ];

    foreach ($expected as $key => $visit) {
        expect($navigation[$key]['visit'])->toBe($visit);
    }

    // Contextual items carry their own mode: Time Reports is React, the ticket queue is Blade.
    expect(contextItems($navigation['time'])['time.reports']['visit'])->toBe('inertia')
        ->and(contextItems($navigation['time'])['time.allocation']['visit'])->toBe('inertia')
        ->and(contextItems($navigation['helpdesk'])['helpdesk.queue']['visit'])->toBe('document')
        ->and(contextItems($navigation['projects'])['projects.create']['visit'])->toBe('inertia')
        ->and(contextItems($navigation['system'])['system.users']['visit'])->toBe('document');

    // The transitional viewer surface is Blade.
    expect(workspaces(navigationFor(actor('user'), 'dashboard'))['resources']['visit'])->toBe('document');

    foreach (navigationValuesDeep(navigationFor(actor('operator'), 'dashboard'), 'visit') as $visit) {
        expect($visit)->toBeIn(['inertia', 'document']);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// G3 / Pages — the A1.6 actor profiles against the live authorization model
// ─────────────────────────────────────────────────────────────────────────────

it('applies the G3 rule for every A1.6 actor profile', function (
    array $permissions,
    bool $viewerPages,
    bool $systemPages,
) {
    $subject = actorWithPermissions($permissions);
    $navigation = navigationFor($subject, 'dashboard');
    $hrefs = navigationValuesDeep($navigation, 'href');
    $keys = array_keys(workspaces($navigation));

    expect(in_array(route('cms.index'), $hrefs, true))->toBe($viewerPages)
        ->and(in_array('resources', $keys, true))->toBe($viewerPages)
        ->and(in_array(route('operator.cms.index'), $hrefs, true))->toBe($systemPages);
})->with([
    // cms.view AND NOT cms.edit — the rule's whole purpose: customers keep published content.
    'user (role defaults)' => [PermissionCatalogue::userDefaults(), true, false],
    'helpdesk agent' => [['cms.view', 'tickets.view', 'tickets.assign'], true, false],
    'billing operator' => [['cms.view', 'billing.manage'], true, false],
    'crm manager' => [['cms.view', 'crm.manage'], true, false],
    // cms.edit present: the viewer entry retires in favour of System → Pages, which links the
    // published view of every row (A1.6).
    'cms editor with cms.view' => [['cms.view', 'cms.edit'], false, true],
    'cms editor without cms.view' => [['cms.edit'], false, true],
    // Neither capability: no page surface at all.
    'no cms capability' => [['tickets.view'], false, false],
]);

it('gives an operator System Pages and no duplicate viewer entry', function () {
    $navigation = navigationFor(actor('operator'), 'dashboard');
    $hrefs = navigationValuesDeep($navigation, 'href');

    expect($hrefs)->toContain(route('operator.cms.index'))
        ->and($hrefs)->not->toContain(route('cms.index'))
        ->and(array_keys(contextItems(workspaces($navigation)['system'])))
        ->toContain('system.pages');
});

it('keeps the viewer Pages surface active under its own transitional workspace', function () {
    expect(navigationActiveKeysDeep(navigationFor(actor('user'), 'cms.index')))->toBe(['resources']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Account-menu boundary (§12.4)
// ─────────────────────────────────────────────────────────────────────────────

it('emits navigation only, never account-menu items', function () {
    $navigation = navigationFor(actor('operator'), 'dashboard');
    $hrefs = navigationValuesDeep($navigation, 'href');

    expect($hrefs)->not->toContain(route('profile.show'))
        ->and($hrefs)->not->toContain(route('logout'))
        // One needle per call: `toContain` is variadic, so a negated multi-needle call would pass as
        // soon as any single needle was absent.
        ->and(collect($navigation['workspaces'])->pluck('key')->all())->not->toContain('management')
        ->and(collect($navigation['workspaces'])->pluck('key')->all())->not->toContain('primary')
        ->and(collect($navigation['workspaces'])->pluck('key')->all())->not->toContain('manage');
});

// ─────────────────────────────────────────────────────────────────────────────
// Reachability through the reshape
// ─────────────────────────────────────────────────────────────────────────────

it('keeps every pre-WP3 destination reachable in the canonical model', function () {
    // WP3 pinned this against the flattened compatibility projection; WP5 deleted that projection
    // with its last consumer, so the same guarantee is now asserted on the model both shells render.
    $before = [
        'operator' => [
            'tickets.index', 'projects.index', 'tasks.index', 'time.index', 'billing.invoices.index',
            'crm.companies.index', 'operator.tickets.index', 'operator.time.index',
            'organizations.index', 'operator.cms.index', 'users.index', 'roles.index',
        ],
        'user' => [
            'tickets.index', 'projects.index', 'tasks.index', 'time.index',
            'billing.client.invoices.index', 'cms.index',
        ],
    ];

    foreach ($before as $role => $routes) {
        $hrefs = navigationValuesDeep(navigationFor(actor($role), 'dashboard'), 'href');

        foreach ($routes as $route) {
            expect($hrefs)->toContain(route($route));
        }
    }
});
