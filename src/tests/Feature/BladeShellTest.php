<?php

/*
 * EPIC-013 WP5: the Blade Direction D shell, asserted on the HTML it actually renders.
 *
 * Every assertion compares what Blade draws against the canonical payload NavigationBuilder produces for
 * the same actor and route, parsed as a DOM rather than searched as a string. That is the WP5 contract:
 * Blade projects the server's model — same workspaces, order, labels, destinations, active state and
 * authorization result — and adds, removes, re-orders and computes nothing of its own (L14, §14.1).
 */

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use App\Support\Initials;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function bladeShellActor(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** Renders a Blade page as the actor and returns it as queryable DOM. */
function bladeShellPage(User $actor, string $routeName): DOMXPath
{
    $html = test()->actingAs($actor)->get(route($routeName))->assertOk()->getContent();

    $dom = new DOMDocument;
    // libxml predates HTML5 elements such as <nav>, <main> and <dialog>; it parses them correctly but
    // reports each as unknown, so the reports are discarded rather than treated as failures.
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** The canonical payload for the same actor and route, from the one builder. */
function bladeShellPayload(User $actor, string $routeName): array
{
    $request = Request::create(route($routeName, [], false));
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $actor);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return app(NavigationBuilder::class)->build($request);
}

/**
 * Destinations compared by path, query and fragment: the page is rendered by the HTTP test client and
 * the reference payload is built outside it, so their scheme and host legitimately differ.
 */
function bladeShellPath(string $href): string
{
    $parts = parse_url($href);

    return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
}

/** @return list<array{href: string, label: string, current: bool}> */
function bladeShellLinks(DOMXPath $xpath, string $query): array
{
    $links = [];

    foreach ($xpath->query($query) as $link) {
        $links[] = [
            'href' => bladeShellPath($link->getAttribute('href')),
            'label' => trim(preg_replace('/\s+/', ' ', $link->textContent)),
            'current' => $link->getAttribute('aria-current') === 'page',
        ];
    }

    return $links;
}

/** @return list<array{href: string, label: string, current: bool}> */
function bladeShellExpectedWorkspaces(array $navigation): array
{
    return array_map(fn (array $workspace) => [
        'href' => bladeShellPath($workspace['href']),
        'label' => $workspace['label'],
        'current' => $workspace['isActive'],
    ], $navigation['workspaces']);
}

/** @return list<array{href: string, label: string, current: bool}> */
function bladeShellExpectedContext(array $navigation): array
{
    $current = collect($navigation['workspaces'])->firstWhere('key', $navigation['currentWorkspace']);
    $items = [];

    foreach ($current['context'] ?? [] as $section) {
        foreach ($section['items'] as $item) {
            $items[] = [
                'href' => bladeShellPath($item['href']),
                'label' => $item['label'],
                // Presentation refuses the strip on an action, whatever the payload says.
                'current' => $section['kind'] !== 'actions' && $item['isActive'],
            ];
        }
    }

    return $items;
}

const BLADE_RAIL = '//div[@data-shell-rail]/nav[@aria-label="Workspaces"]//a';
const BLADE_SHEET = '//dialog[@data-shell-sheet]/div/nav[@aria-label="Workspaces"]//a';
const BLADE_DRAWER = '//div[@data-shell-drawer]/nav//a';

// ─────────────────────────────────────────────────────────────────────────────
// Projection of the canonical model
// ─────────────────────────────────────────────────────────────────────────────

it('renders the canonical workspaces and context exactly as the server built them', function (string $role, string $routeName) {
    $actor = bladeShellActor($role);
    $page = bladeShellPage($actor, $routeName);
    $navigation = bladeShellPayload($actor, $routeName);

    $workspaces = bladeShellExpectedWorkspaces($navigation);
    $context = bladeShellExpectedContext($navigation);

    // Same workspaces, same order, same labels, same destinations, same active state — in the rail and
    // in the narrow-width sheet. Nothing added, nothing pruned, nothing re-ordered.
    expect(bladeShellLinks($page, BLADE_RAIL))->toBe($workspaces)
        ->and(bladeShellLinks($page, BLADE_SHEET))->toBe($workspaces)
        // The current workspace's context, projected into the panel and the sheet alike.
        ->and(bladeShellLinks($page, BLADE_DRAWER))->toBe($context)
        ->and(bladeShellLinks($page, '//dialog[@data-shell-sheet]/div/nav[contains(@aria-label, " views")]//a'))->toBe($context);
})->with([
    'operator, Helpdesk queue' => ['operator', 'operator.tickets.index'],
    'operator, Helpdesk reports' => ['operator', 'operator.tickets.reports'],
    'operator, System users' => ['operator', 'users.index'],
    'operator, System roles' => ['operator', 'roles.index'],
    'operator, Directory people' => ['operator', 'crm.contacts.index'],
    'operator, Finance invoices' => ['operator', 'billing.invoices.index'],
    'member, Helpdesk requests' => ['user', 'tickets.index'],
    'member, Finance own invoices' => ['user', 'billing.client.invoices.index'],
    'member, Resources' => ['user', 'cms.index'],
]);

it('keeps active state server-owned: aria-current is exactly the payload isActive set', function (
    string $role,
    string $routeName,
    string $workspace,
    ?string $item,
) {
    $actor = bladeShellActor($role);
    $page = bladeShellPage($actor, $routeName);

    $current = fn (string $query) => array_values(array_map(
        fn (array $link) => $link['label'],
        array_filter(bladeShellLinks($page, $query), fn (array $link) => $link['current']),
    ));

    expect($current(BLADE_RAIL))->toBe([$workspace])
        ->and($current(BLADE_DRAWER))->toBe($item === null ? [] : [$item]);

    // The breadcrumb ends with the current page: the active view, or the workspace when it has none.
    $crumb = $page->query('//header[@data-shell-utility]//nav[@aria-label="Breadcrumb"]//*[@aria-current="page"]');

    expect($crumb->length)->toBe(1)
        ->and(trim($crumb->item(0)->textContent))->toBe($item ?? $workspace);
})->with([
    // The three A1.10 collision routes resolved server-side; Blade must not re-derive them.
    'reports, not queue' => ['operator', 'operator.tickets.reports', 'Helpdesk', 'Reports'],
    'queue' => ['operator', 'operator.tickets.index', 'Helpdesk', 'Queue'],
    'nested roles route' => ['operator', 'roles.create', 'System', 'Roles'],
    'client finance branch' => ['user', 'billing.client.invoices.index', 'Finance', 'My invoices'],
    'single-surface Resources' => ['user', 'cms.index', 'Resources', null],
]);

it('stamps the pre-paint inputs on the Blade root from the payload', function () {
    $operator = bladeShellActor('operator');
    $html = bladeShellPage($operator, 'operator.tickets.index')->query('/html')->item(0);

    expect($html->getAttribute('data-workspace'))->toBe('helpdesk')
        ->and($html->getAttribute('data-drawer-default'))->toBe('open');

    $member = bladeShellActor('user');
    $resources = bladeShellPage($member, 'cms.index');
    $root = $resources->query('/html')->item(0);

    // No panel: no default is stamped, the shell says so, and no panel or toggle is rendered at all.
    expect($root->getAttribute('data-workspace'))->toBe('resources')
        ->and($root->hasAttribute('data-drawer-default'))->toBeFalse()
        ->and($resources->query('//*[@data-shell="operational"]')->item(0)->getAttribute('data-panel'))->toBe('false')
        ->and($resources->query('//*[@data-shell-drawer]')->length)->toBe(0)
        ->and($resources->query('//*[@data-shell-toggle]')->length)->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization: Blade reveals exactly what the server permits
// ─────────────────────────────────────────────────────────────────────────────

it('never renders a destination the server withheld from a member', function () {
    $member = bladeShellActor('user');
    $page = bladeShellPage($member, 'tickets.index');

    $shellHrefs = array_map(
        fn (DOMElement $link) => $link->getAttribute('href'),
        iterator_to_array($page->query('//*[@data-shell="operational"]//a[not(ancestor::main)]')),
    );

    expect($shellHrefs)->not->toBeEmpty();

    foreach ($shellHrefs as $href) {
        foreach (['/admin/', '/operator/', '/crm/', '/organizations'] as $forbidden) {
            expect(str_contains($href, $forbidden))->toBeFalse("{$href} contains {$forbidden}");
        }
    }

    $labels = array_column(bladeShellLinks($page, BLADE_RAIL), 'label');

    expect($labels)->not->toContain('System')
        ->and($labels)->not->toContain('Directory')
        // The ninth, transitional workspace stays for the actor the server gives it to (G3).
        ->and($labels)->toContain('Resources');
});

it('keeps Resources off the operator rail, where System Pages replaces it', function () {
    $labels = array_column(bladeShellLinks(bladeShellPage(bladeShellActor('operator'), 'tickets.index'), BLADE_RAIL), 'label');

    expect($labels)->not->toContain('Resources')
        ->and($labels)->toContain('System')
        ->and($labels)->toContain('Directory');
});

// ─────────────────────────────────────────────────────────────────────────────
// Structure, landmarks and the personal-only account menu
// ─────────────────────────────────────────────────────────────────────────────

it('renders the Direction D landmarks with the skip link first and one h1', function () {
    $page = bladeShellPage(bladeShellActor('operator'), 'operator.tickets.index');

    $focusable = $page->query('//body//*[self::a[@href] or self::button or self::input or self::select or self::textarea][not(ancestor::dialog)]');

    // The skip link is the first focusable element and targets the main landmark, which can take focus.
    expect($focusable->item(0)->getAttribute('href'))->toBe('#main-content')
        ->and(trim($focusable->item(0)->textContent))->toBe('Skip to content')
        ->and($page->query('//main[@id="main-content"][@tabindex="-1"]')->length)->toBe(1)
        ->and($page->query('//header[@data-shell-utility]')->length)->toBe(1)
        ->and($page->query('//nav[@aria-label="Helpdesk views"][ancestor::div[@data-shell-drawer]]')->length)->toBe(1);

    // "Workspaces" holds workspace links only: brand, toggle and account sit outside it (A1.9).
    expect($page->query('//nav[@aria-label="Workspaces"]//button')->length)->toBe(0)
        ->and($page->query('//nav[@aria-label="Workspaces"]//*[contains(@class, "brand-mark")]')->length)->toBe(0);

    // The shell adds no heading, so the page's own h1 stays the only one (A8.10).
    expect($page->query('//*[@data-shell="operational"]//*[self::h1 or self::h2 or self::h3][not(ancestor::main)][not(ancestor::dialog)]')->length)->toBe(0)
        ->and($page->query('//h1')->length)->toBeLessThanOrEqual(1);
});

it('renders the account control from server initials and keeps its menu personal', function () {
    $operator = User::factory()->create(['name' => 'Grace Brewster Hopper']);
    $operator->assignRole('operator');
    $page = bladeShellPage($operator->fresh(), 'operator.tickets.index');

    $trigger = $page->query('//button[@data-shell-account]')->item(0);

    expect($trigger->getAttribute('aria-label'))->toBe('Account menu: Grace Brewster Hopper')
        ->and($trigger->getAttribute('aria-haspopup'))->toBe('menu')
        ->and($trigger->getAttribute('aria-expanded'))->toBe('false')
        ->and(trim($trigger->textContent))->toBe(Initials::from('Grace Brewster Hopper'));

    $menu = '//*[@id="shell-account-menu"][@role="menu"]';
    $items = array_map(
        fn (DOMElement $item) => trim(preg_replace('/\s+/', ' ', $item->textContent)),
        iterator_to_array($page->query($menu.'//*[@role="menuitem" or @role="menuitemradio"]')),
    );

    // The committed destinations, in order — and nothing administrative, even for an actor who holds
    // every permission (L15, §17).
    expect($items)->toBe([
        'Profile', 'Security & MFA', 'Connected accounts', 'Sessions',
        'light', 'dark',
        'Notifications Not available yet',
        'Sign out',
    ]);

    expect(array_map(
        fn (DOMElement $link) => $link->getAttribute('href'),
        iterator_to_array($page->query($menu.'//a')),
    ))->toBe(['/profile', '/profile#security', '/profile#connected-accounts', '/profile#sessions']);

    expect($page->query($menu.'//*[@aria-disabled="true"]')->length)->toBe(1)
        ->and($page->query($menu.'//form[@method="POST"][@action="/logout"]//button[@type="submit"]')->length)->toBe(1)
        ->and($page->query($menu.'//*[@role="group"][@aria-label="Appearance"]')->length)->toBe(1);
});

it('retires the pre-Direction-D Blade shell chrome', function () {
    $html = test()->actingAs(bladeShellActor('operator'))->get(route('operator.tickets.index'))->getContent();

    // The legacy bar, its standalone theme toggle, its mobile menu, its "Manage" grouping and the
    // authenticated footer are all gone; none of their hooks survive.
    foreach (['id="theme-toggle"', 'id="user-menu-btn"', 'id="mobile-menu"', 'Primary navigation', 'All rights reserved', 'max-w-7xl px-4 sm:px-6 lg:px-8"><div class="flex h-16'] as $retired) {
        expect(str_contains($html, $retired))->toBeFalse("still renders {$retired}");
    }

    expect(str_contains($html, '>Manage<'))->toBeFalse();
});

it('renders the canonical brand mark, instance-scoped', function () {
    $page = bladeShellPage(bladeShellActor('operator'), 'operator.tickets.index');
    $mark = $page->query('//*[@data-shell-brand]//*[local-name()="svg"]')->item(0);

    expect($mark)->not->toBeNull()
        ->and($mark->getAttribute('class'))->toBe('brand-mark')
        ->and($mark->getAttribute('aria-label'))->toBe('Intechral')
        ->and($page->query('//*[@data-shell-brand]//*[local-name()="title" or local-name()="desc"]')->length)->toBe(0);
});

it('renders the shell for a guest without navigation or an account control', function () {
    // `errors/403` is the one Blade page a guest can reach through the shell.
    $request = Request::create('/forbidden-for-guests');
    app()->instance('request', $request);
    $request->setUserResolver(fn () => null);

    $html = view('errors.403')->render();

    expect(str_contains($html, 'href="#main-content"'))->toBeTrue()
        ->and(str_contains($html, '<main id="main-content"'))->toBeTrue()
        ->and(str_contains($html, 'aria-label="Workspaces"'))->toBeFalse()
        ->and(str_contains($html, 'data-shell-account'))->toBeFalse()
        ->and(str_contains($html, 'data-workspace='))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// The shared pre-paint bootstrap
// ─────────────────────────────────────────────────────────────────────────────

it('inlines one shared pre-paint bootstrap before any stylesheet, on both roots', function () {
    $operator = bladeShellActor('operator');

    $blade = test()->actingAs($operator)->get(route('operator.tickets.index'))->getContent();
    $inertia = test()->actingAs($operator)->get(route('projects.index'))->getContent();
    $source = (string) file_get_contents(resource_path('js/shell/bootstrap.js'));
    // The inlined body is the source file minus its explanatory header.
    $body = trim((string) preg_replace('#^/\*.*?\*/\s*#s', '', $source));

    foreach (['Blade' => $blade, 'Inertia' => $inertia] as $renderer => $html) {
        $script = strpos($html, $body);
        $stylesheet = strpos($html, 'rel="stylesheet"');

        expect(substr_count($html, $body))->toBe(1, "{$renderer} inlines the bootstrap once")
            ->and($script)->toBeLessThan($stylesheet === false ? PHP_INT_MAX : $stylesheet)
            ->and($script)->toBeLessThan((int) strpos($html, '<script type="module"'))
            // The two per-root theme scripts it replaced are gone.
            ->and(substr_count($html, "localStorage.getItem('theme')"))->toBe(0);
    }

    // The Inertia root stamps the same inputs, from the payload it already resolved.
    expect($inertia)->toContain('data-workspace="projects"')
        ->and($inertia)->toContain('data-drawer-default="open"');
});

it('stamps no workspace on a guest Inertia page', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();

    expect(str_contains($html, 'data-workspace='))->toBeFalse()
        ->and(str_contains($html, 'data-drawer-default='))->toBeFalse()
        ->and(substr_count($html, "readItem('theme')"))->toBe(1);
});
