<?php

/*
 * EPIC-017 WP1 (P1): the permanent drift guard for the presentation resolver.
 *
 * The resolver chooses the Operational shell for a user who holds any operator-surface capability.
 * That is only safe while the capability sets are COMPLETE: a capability that gates an operator,
 * admin, CRM or System surface but is missing from the Operational set would hand a customer top bar
 * to someone whose only navigation is the rail. So this guard derives the gating capabilities from
 * what is actually registered — the routes and the navigation payload — instead of trusting the
 * hand-written list, and fails until a human classifies a new one.
 *
 *   R1  catalogue = customer defaults ⊔ operational ⊔ presentation-neutral
 *   R2  every route ability is a known, classified capability
 *   R3  a GET/HEAD page gated by a non-default ability carries an operational capability
 *   R4  routes under the operator, admin, CRM and organization prefixes are capability-gated
 *   R5  a capability that changes the Operational navigation is classified Operational, and every
 *       neutral capability leaves it unchanged
 *   R6  sentinel fixtures prove R2–R5 can fail
 *
 * Each rule is a pure function over route or payload descriptors, so the sentinels can feed it a
 * fixture the real routes never contain. Helper names carry the `presentation` prefix (Pest loads
 * every test file into one process).
 */

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/**
 * Operator-surface route prefixes: the first URI segment, or the stated path prefix.
 * `billing/invoices` is the operator invoice surface; its customer payment pages are the reasoned
 * exceptions in {@see presentationUngatedExceptions()}.
 */
const PRESENTATION_OPERATOR_SEGMENTS = ['operator', 'admin', 'crm', 'organizations', 'invitations'];
const PRESENTATION_OPERATOR_PATH_PREFIXES = ['billing/invoices'];

/** Routes under an operator prefix that are deliberately not capability-gated, with why. */
function presentationUngatedExceptions(): array
{
    return [
        'billing.invoices.pay' => 'A customer pays their own invoice; authorized by InvoicePolicy, not a capability.',
        'billing.invoices.pay.intent' => 'The same customer payment flow, authorized by InvoicePolicy.',
    ];
}

/** @return array<string, string> every catalogue permission mapped to default|operational|neutral */
function presentationClasses(): array
{
    $classes = [];

    foreach (PermissionCatalogue::userDefaults() as $capability) {
        $classes[$capability] = 'default';
    }

    foreach (ShellPresentationResolver::OPERATIONAL as $capability) {
        $classes[$capability] = 'operational';
    }

    foreach (array_keys(ShellPresentationResolver::PRESENTATION_NEUTRAL) as $capability) {
        $classes[$capability] = 'neutral';
    }

    return $classes;
}

/** The capability names a route's authorization middleware demands. */
function presentationRouteAbilities(Route $route): array
{
    $abilities = [];

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }

        if (! preg_match('/^(?:can|permission|Illuminate\\\\Auth\\\\Middleware\\\\Authorize|Spatie\\\\Permission\\\\Middleware\\\\PermissionMiddleware):(.+)$/', $middleware, $match)) {
            continue;
        }

        // `can:ability,Model` carries arguments after the first comma; `permission:a|b` lists several.
        $first = in_array(explode(':', $middleware, 2)[0], ['permission', 'Spatie\\Permission\\Middleware\\PermissionMiddleware'], true)
            ? preg_split('/[|,]/', $match[1])
            : [explode(',', $match[1])[0]];

        array_push($abilities, ...$first);
    }

    return array_values(array_unique($abilities));
}

/** @return list<array{name: string|null, methods: list<string>, uri: string, abilities: list<string>}> */
function presentationRoutes(): array
{
    return collect(app('router')->getRoutes()->getRoutes())->map(fn (Route $route) => [
        'name' => $route->getName(),
        'methods' => $route->methods(),
        'uri' => $route->uri(),
        'abilities' => presentationRouteAbilities($route),
    ])->all();
}

/** R2: abilities used by routes that are not classified. @return list<string> */
function presentationUnclassifiedAbilities(array $routes, array $classes): array
{
    $unknown = [];

    foreach ($routes as $route) {
        foreach ($route['abilities'] as $ability) {
            if (! isset($classes[$ability])) {
                $unknown[] = "{$ability} ({$route['uri']})";
            }
        }
    }

    return array_values(array_unique($unknown));
}

/** R3: GET/HEAD pages gated by a non-default ability but by no operational one. @return list<string> */
function presentationPagesWithoutOperational(array $routes, array $classes): array
{
    $offenders = [];

    foreach ($routes as $route) {
        if (array_intersect($route['methods'], ['GET', 'HEAD']) === []) {
            continue;
        }

        $nonDefault = array_filter($route['abilities'], fn (string $a) => ($classes[$a] ?? null) !== 'default');
        $operational = array_filter($route['abilities'], fn (string $a) => ($classes[$a] ?? null) === 'operational');

        if ($nonDefault !== [] && $operational === []) {
            $offenders[] = "{$route['uri']} (".implode(', ', $nonDefault).')';
        }
    }

    return $offenders;
}

/** R4: routes under an operator prefix with no capability gate and no reasoned exception. @return list<string> */
function presentationUngatedOperatorRoutes(array $routes, array $exceptions): array
{
    $offenders = [];

    foreach ($routes as $route) {
        $segment = explode('/', $route['uri'])[0];
        $isOperatorPath = in_array($segment, PRESENTATION_OPERATOR_SEGMENTS, true);

        foreach (PRESENTATION_OPERATOR_PATH_PREFIXES as $prefix) {
            $isOperatorPath = $isOperatorPath || str_starts_with($route['uri'], $prefix);
        }

        if ($isOperatorPath && $route['abilities'] === [] && ! isset($exceptions[$route['name'] ?? ''])) {
            $offenders[] = implode('|', $route['methods']).' '.$route['uri'];
        }
    }

    return $offenders;
}

/**
 * R5: a capability whose presence changes the Operational navigation must be Operational, and a
 * neutral one must change nothing. Returns a message per violation, or an empty list.
 *
 * @return list<string>
 */
function presentationNavMismatch(string $capability, string $class, array $baseline, array $withCapability): array
{
    if ($baseline !== $withCapability && $class !== 'operational') {
        return ["{$capability} changes the Operational navigation but is classified {$class}"];
    }

    return [];
}

/** The Operational navigation for a user, as a plain array, on the dashboard route. */
function presentationNavFor(User $user): array
{
    $request = Request::create(route('dashboard', [], false));
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('dashboard'));

    return json_decode(json_encode(
        app(NavigationBuilder::class)->build($request, ShellPresentation::Operational),
    ), true);
}

function presentationActor(?string $capability = null): User
{
    $user = User::factory()->create();
    $user->assignRole('user');

    if ($capability !== null) {
        $user->givePermissionTo($capability);
    }

    return User::findOrFail($user->id);
}

// ── R1 ────────────────────────────────────────────────────────────────────

it('R1: partitions the catalogue into customer defaults, operational and presentation-neutral', function () {
    $defaults = PermissionCatalogue::userDefaults();
    $operational = ShellPresentationResolver::OPERATIONAL;
    $neutral = array_keys(ShellPresentationResolver::PRESENTATION_NEUTRAL);
    $all = PermissionCatalogue::all();

    // Pairwise disjoint, so a capability is in exactly one set.
    expect(array_intersect($defaults, $operational))->toBe([])
        ->and(array_intersect($defaults, $neutral))->toBe([])
        ->and(array_intersect($operational, $neutral))->toBe([])
        // Complete and exact: nothing unclassified, nothing classified that the catalogue lacks.
        ->and(array_values(array_diff($all, $defaults, $operational, $neutral)))->toBe([], 'unclassified catalogue capabilities')
        ->and(array_values(array_diff([...$defaults, ...$operational, ...$neutral], $all)))->toBe([], 'classified but not in the catalogue')
        ->and(count($all))->toBe(count($defaults) + count($operational) + count($neutral));
});

it('R1: records the approved sizes and a reason for every neutral capability', function () {
    expect(ShellPresentationResolver::OPERATIONAL)->toHaveCount(11)
        ->and(ShellPresentationResolver::PRESENTATION_NEUTRAL)->toHaveCount(19)
        ->and(PermissionCatalogue::userDefaults())->toHaveCount(12);

    foreach (ShellPresentationResolver::PRESENTATION_NEUTRAL as $capability => $reason) {
        expect(trim($reason))->not->toBe('', "{$capability} has no reason");
    }
});

// ── R2 to R4 over the real routes ─────────────────────────────────────────

it('finds the routes and the abilities it is asserting over', function () {
    $routes = presentationRoutes();
    $abilities = collect($routes)->pluck('abilities')->flatten()->unique()->values()->all();

    // A change in how routes are gated that empties these would make every rule below vacuous.
    expect(count($routes))->toBeGreaterThan(100)
        ->and($abilities)->toContain('tickets.assign', 'crm.manage', 'billing.manage', 'users.view', 'tickets.view');
});

it('R2: every ability a route demands is a known, classified capability', function () {
    expect(presentationUnclassifiedAbilities(presentationRoutes(), presentationClasses()))
        ->toBe([], 'Classify the capability in ShellPresentationResolver (OPERATIONAL or PRESENTATION_NEUTRAL) or in the customer defaults');
});

it('R3: every page gated by a non-default ability carries an operational capability', function () {
    expect(presentationPagesWithoutOperational(presentationRoutes(), presentationClasses()))
        ->toBe([], 'Holding only these would reach a page the Focused shell has no navigation for');
});

it('R4: every operator, admin, CRM and organization route is capability-gated', function () {
    expect(presentationUngatedOperatorRoutes(presentationRoutes(), presentationUngatedExceptions()))
        ->toBe([], 'An operator route gated only inside its controller is invisible to this guard');
});

it('R4: every reasoned exception still names a registered route', function () {
    $names = collect(presentationRoutes())->pluck('name')->filter()->all();

    foreach (presentationUngatedExceptions() as $name => $reason) {
        expect($names)->toContain($name)
            ->and(trim($reason))->not->toBe('');
    }
});

// ── R5 over the navigation ────────────────────────────────────────────────

it('R5: a capability that changes the Operational navigation is classified Operational, and neutral ones change nothing', function (string $capability, string $class) {
    $baseline = presentationNavFor(presentationActor());
    $withCapability = presentationNavFor(presentationActor($capability));

    expect(presentationNavMismatch($capability, $class, $baseline, $withCapability))->toBe([]);
})->with(function () {
    foreach (presentationClasses() as $capability => $class) {
        if ($class !== 'default') {
            yield $capability => [$capability, $class];
        }
    }
});

it('R5: its baseline is a customer navigation the operator capabilities visibly extend', function () {
    // The rule is vacuous if no capability ever changes the payload, so a known one must.
    $baseline = presentationNavFor(presentationActor());
    $queue = presentationNavFor(presentationActor(PermissionCatalogue::TICKETS_ASSIGN));

    expect($baseline)->not->toBe($queue)
        ->and(collect($baseline['workspaces'])->pluck('key')->all())->not->toContain('directory', 'system');
});

// ── R6 sentinels: the rules can fail ──────────────────────────────────────

it('R6: R2 reports an ability nobody classified', function () {
    $routes = [['name' => 'x.index', 'methods' => ['GET'], 'uri' => 'operator/widgets', 'abilities' => ['widgets.manage']]];

    expect(presentationUnclassifiedAbilities($routes, presentationClasses()))->toBe(['widgets.manage (operator/widgets)']);
});

it('R6: R3 reports a page whose only non-default gate is a neutral capability', function () {
    $routes = [
        ['name' => 'a', 'methods' => ['GET', 'HEAD'], 'uri' => 'admin/reports', 'abilities' => ['users.manage']],
        ['name' => 'b', 'methods' => ['GET', 'HEAD'], 'uri' => 'admin/users', 'abilities' => ['users.view', 'users.manage']],
        ['name' => 'c', 'methods' => ['POST'], 'uri' => 'admin/roles', 'abilities' => ['roles.manage']],
        ['name' => 'd', 'methods' => ['GET', 'HEAD'], 'uri' => 'tickets', 'abilities' => ['tickets.view']],
    ];

    // Only the first is an offender: the second holds an operational capability, the third is not a
    // page, the fourth is a customer default.
    expect(presentationPagesWithoutOperational($routes, presentationClasses()))->toBe(['admin/reports (users.manage)']);
});

it('R6: R4 reports an ungated operator route and honours only a reasoned exception', function () {
    $routes = [
        ['name' => 'p.index', 'methods' => ['GET'], 'uri' => 'operator/widgets', 'abilities' => []],
        ['name' => 'billing.invoices.pay', 'methods' => ['GET'], 'uri' => 'billing/invoices/{invoice}/pay', 'abilities' => []],
        ['name' => 'invitation.show', 'methods' => ['GET'], 'uri' => 'invitation/{token}', 'abilities' => []],
        ['name' => 'tickets.index', 'methods' => ['GET'], 'uri' => 'tickets', 'abilities' => []],
    ];

    expect(presentationUngatedOperatorRoutes($routes, presentationUngatedExceptions()))->toBe(['GET operator/widgets']);
});

it('R6: R5 reports a neutral or default capability that changes the navigation, and accepts an operational one', function () {
    $before = ['workspaces' => [['key' => 'home']]];
    $after = ['workspaces' => [['key' => 'home'], ['key' => 'system']]];

    expect(presentationNavMismatch('users.manage', 'neutral', $before, $after))
        ->toBe(['users.manage changes the Operational navigation but is classified neutral'])
        ->and(presentationNavMismatch('users.view', 'operational', $before, $after))->toBe([])
        ->and(presentationNavMismatch('users.manage', 'neutral', $before, $before))->toBe([]);
});

it('R6: the ability parser reads can: and permission: middleware, ignores everything else', function () {
    $route = new Route(['GET'], 'operator/x', fn () => null);
    $route->middleware(['auth', 'can:tickets.assign', 'can:view,App\\Models\\Ticket', 'permission:crm.manage|users.view', 'throttle:5,1']);

    expect(presentationRouteAbilities($route))->toBe(['tickets.assign', 'view', 'crm.manage', 'users.view']);
});
