<?php

/*
 * EPIC-017 WP1 (§9.3): the Blade Focused frame.
 *
 * A customer reaches Blade pages (the Helpdesk until WP2, own invoices, invoice payment, resources and
 * the 403 page) that would otherwise throw them back into the operator rail. `layouts/app.blade.php`
 * branches on the one resolver's answer and renders the customer top bar, fed the SAME Focused
 * navigation the React shell receives. This file owns the Blade half of the parity contract: same
 * labels, order, destinations and current item, same account menu, same landmarks, and no operator
 * chrome. The React half is `customer-shell.test.tsx`; the real-browser half is
 * `tests/Browser/customer-shell.spec.ts`. Structure is deliberately not compared byte for byte.
 *
 * Helper names carry the `focusedFrame` prefix (Pest loads every test file into one process).
 */

use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Shell\ShellPresentation;
use App\Support\Initials;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function focusedFrameUser(string $role = 'user', array $permissions = []): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return User::findOrFail($user->id);
}

/** Renders a URL as the actor and returns the HTML and a queryable DOM. */
function focusedFrameLoad(User $actor, string $url, int $status = 200): array
{
    $response = test()->actingAs($actor)->get($url);
    $response->assertStatus($status);
    $html = $response->getContent();

    $dom = new DOMDocument;
    // libxml predates HTML5 elements such as <nav>, <main> and <dialog>; it parses them correctly but
    // reports each as unknown, so the reports are discarded rather than treated as failures.
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return [$html, new DOMXPath($dom)];
}

/** The Focused payload the server built for this actor and route, from the one builder. */
function focusedFramePayload(User $actor, string $routeName): array
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $actor);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName($routeName));

    return app(NavigationBuilder::class)->build($request, ShellPresentation::Focused);
}

/** Destinations compared by path, query and fragment: scheme and host legitimately differ. */
function focusedFramePath(string $href): string
{
    $parts = parse_url($href);

    return ($parts['path'] ?? '')
        .(isset($parts['query']) ? '?'.$parts['query'] : '')
        .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
}

/** @return list<array{label: string, href: string, current: bool}> */
function focusedFrameLinks(DOMXPath $xpath, string $query): array
{
    return array_values(array_map(fn (DOMElement $link) => [
        'label' => trim(preg_replace('/\s+/', ' ', $link->textContent)),
        'href' => focusedFramePath($link->getAttribute('href')),
        'current' => $link->getAttribute('aria-current') === 'page',
    ], iterator_to_array($xpath->query($query))));
}

/** @return list<array{label: string, href: string, current: bool}> */
function focusedFrameExpected(array $navigation): array
{
    return array_map(fn (array $workspace) => [
        'label' => $workspace['label'],
        'href' => focusedFramePath($workspace['href']),
        'current' => $workspace['isActive'],
    ], $navigation['workspaces']);
}

const FOCUSED_FRAME_BAR = '//header[@data-shell-topbar]//nav[@aria-label="Main"][@data-shell-topnav]//a';
const FOCUSED_FRAME_SHEET = '//header[@data-shell-topbar]//dialog[@data-shell-sheet="focused"]//nav[@aria-label="Main"]//a';

it('renders the Focused navigation exactly as the server projected it, in the bar and the sheet', function (string $routeName) {
    $actor = focusedFrameUser();
    [, $page] = focusedFrameLoad($actor, route($routeName));

    $expected = focusedFrameExpected(focusedFramePayload($actor, $routeName));

    expect(focusedFrameLinks($page, FOCUSED_FRAME_BAR))->toBe($expected)
        // The sheet carries the same set below 900px, from the same payload.
        ->and(focusedFrameLinks($page, FOCUSED_FRAME_SHEET))->toBe($expected)
        ->and(array_column($expected, 'label'))->toBe(['Home', 'Support', 'Projects', 'Invoices', 'Resources']);
})->with([
    'the Helpdesk list' => ['tickets.index'],
    'a new request' => ['tickets.create'],
    'own invoices' => ['billing.client.invoices.index'],
    'resources' => ['cms.index'],
]);

it('marks exactly the server current item, in the bar and the sheet', function (string $routeName, string $current) {
    $actor = focusedFrameUser();
    [, $page] = focusedFrameLoad($actor, route($routeName));

    foreach ([FOCUSED_FRAME_BAR, FOCUSED_FRAME_SHEET] as $query) {
        $marked = array_column(array_filter(focusedFrameLinks($page, $query), fn (array $link) => $link['current']), 'label');

        expect(array_values($marked))->toBe([$current]);
    }
})->with([
    'tickets' => ['tickets.index', 'Support'],
    'new request' => ['tickets.create', 'Support'],
    'own invoices' => ['billing.client.invoices.index', 'Invoices'],
    'resources' => ['cms.index', 'Resources'],
]);

it('draws the customer frame and none of the operator chrome', function (string $routeName) {
    // The actor may log time, so a timer would show here if the frame ever drew one.
    $actor = focusedFrameUser();
    expect($actor->can('time.log'))->toBeTrue();

    [$html, $page] = focusedFrameLoad($actor, route($routeName));

    expect($page->query('//*[@data-shell="focused"][@data-shell-renderer="blade"]')->length)->toBe(1)
        ->and($page->query('//*[@data-shell="operational"]')->length)->toBe(0);

    foreach (['data-shell-rail', 'data-shell-drawer', 'data-shell-utility', 'data-shell-toggle', 'data-shell-timer', 'data-shell-canvas'] as $hook) {
        expect($page->query("//*[@{$hook}]")->length)->toBe(0, $hook);
    }

    expect($page->query('//nav[@aria-label="Breadcrumb"]')->length)->toBe(0)
        ->and($page->query('//nav[@aria-label="Workspaces"]')->length)->toBe(0)
        ->and($html)->not->toContain('Show workspace views');
})->with(['tickets.index', 'tickets.create', 'billing.client.invoices.index', 'cms.index']);

it('renders the Direction D customer landmarks with the skip link first', function () {
    [, $page] = focusedFrameLoad(focusedFrameUser(), route('tickets.index'));

    $focusable = $page->query('//body//*[self::a[@href] or self::button or self::input or self::select or self::textarea][not(ancestor::dialog)]');

    expect($focusable->item(0)->getAttribute('href'))->toBe('#main-content')
        ->and(trim($focusable->item(0)->textContent))->toBe('Skip to content')
        ->and($page->query('//main[@id="main-content"][@tabindex="-1"]')->length)->toBe(1)
        ->and($page->query('//header[@data-shell-topbar]')->length)->toBe(1)
        // The header holds the Main nav; main follows it.
        ->and($page->query('//header//nav[@aria-label="Main"]')->length)->toBeGreaterThanOrEqual(1)
        ->and($page->query('//header/following::main')->length)->toBe(1)
        // The shell adds no heading, so the page's own h1 stays the only one.
        ->and($page->query('//*[@data-shell="focused"]//*[self::h1 or self::h2 or self::h3][not(ancestor::main)][not(ancestor::dialog)]')->length)->toBe(0)
        ->and($page->query('//h1')->length)->toBeLessThanOrEqual(1);
});

it('keeps the menu button and the account control in the bar, with the account outside the nav', function () {
    [, $page] = focusedFrameLoad(focusedFrameUser(), route('tickets.index'));

    $trigger = $page->query('//button[@data-shell-sheet-trigger]')->item(0);

    expect($trigger->getAttribute('aria-label'))->toBe('Open navigation')
        ->and($trigger->getAttribute('aria-haspopup'))->toBe('dialog')
        ->and($trigger->getAttribute('aria-expanded'))->toBe('false')
        ->and($trigger->getAttribute('aria-controls'))->toBe('shell-sheet')
        ->and($page->query('//dialog[@id="shell-sheet"][@aria-labelledby="shell-sheet-title"]//h2[@id="shell-sheet-title"]')->length)->toBe(1)
        ->and($page->query('//header//button[@data-shell-account]')->length)->toBe(1)
        ->and($page->query('//nav[@aria-label="Main"]//button[@data-shell-account]')->length)->toBe(0)
        // The sheet holds navigation only: the account control stays in the bar.
        ->and($page->query('//dialog[@data-shell-sheet]//*[@data-shell-account]')->length)->toBe(0);
});

it('renders the same personal account menu as the rail, anchored below a circular 34px trigger', function () {
    $actor = User::factory()->create(['name' => 'Grace Brewster Hopper']);
    $actor->assignRole('user');
    [, $page] = focusedFrameLoad(User::findOrFail($actor->id), route('tickets.index'));

    $trigger = $page->query('//button[@data-shell-account]')->item(0);

    expect($trigger->getAttribute('aria-label'))->toBe('Account menu: Grace Brewster Hopper')
        ->and($trigger->getAttribute('aria-haspopup'))->toBe('menu')
        ->and($trigger->getAttribute('aria-expanded'))->toBe('false')
        ->and(trim($trigger->textContent))->toBe(Initials::from('Grace Brewster Hopper'))
        // The circle IS the trigger: no square tile, and the 34px avatar size.
        ->and($trigger->getAttribute('class'))->toContain('rounded-full')
        ->and($trigger->getAttribute('class'))->not->toContain('rounded-[6px]')
        ->and($page->query('//button[@data-shell-account]/span[contains(@class, "h-8.5")]')->length)->toBe(1);

    $menu = '//*[@id="shell-account-menu"][@role="menu"]';
    $items = array_map(
        fn (DOMElement $item) => trim(preg_replace('/\s+/', ' ', $item->textContent)),
        iterator_to_array($page->query($menu.'//*[@role="menuitem" or @role="menuitemradio"]')),
    );

    expect($items)->toBe([
        'Profile', 'Security & MFA', 'Connected accounts', 'Sessions',
        'light', 'dark',
        'Notifications Not available yet',
        'Sign out',
    ])
        ->and(array_map(
            fn (DOMElement $link) => $link->getAttribute('href'),
            iterator_to_array($page->query($menu.'//a')),
        ))->toBe(['/profile', '/profile#security', '/profile#connected-accounts', '/profile#sessions'])
        ->and($page->query($menu.'//form[@method="POST"][@action="/logout"]//button[@type="submit"]')->length)->toBe(1)
        ->and($page->query($menu.'//*[@role="group"][@aria-label="Appearance"]')->length)->toBe(1)
        // Opens below and right-aligned, not beside the trigger like the rail's menu.
        ->and($page->query($menu)->item(0)->getAttribute('class'))->toContain('top-full right-0')
        ->and($page->query($menu)->item(0)->getAttribute('class'))->not->toContain('md:left-full');
});

it('names every anchor target of the account menu on the profile page it links to', function () {
    // The Blade menu links to /profile#security, #connected-accounts and #sessions (N6); the React
    // profile page owns the ids (its Vitest pins them). Here: the links the Blade frame emits.
    [, $page] = focusedFrameLoad(focusedFrameUser(), route('tickets.index'));

    $hrefs = array_map(
        fn (DOMElement $link) => $link->getAttribute('href'),
        iterator_to_array($page->query('//*[@id="shell-account-menu"]//a')),
    );

    expect($hrefs)->toContain('/profile#security', '/profile#connected-accounts', '/profile#sessions');
});

it('never renders an operator, admin, CRM or organization destination outside the page content', function (string $routeName) {
    [, $page] = focusedFrameLoad(focusedFrameUser(), route($routeName));

    $hrefs = array_map(
        fn (DOMElement $link) => $link->getAttribute('href'),
        iterator_to_array($page->query('//*[@data-shell="focused"]//a[not(ancestor::main)]')),
    );

    expect($hrefs)->not->toBeEmpty();

    foreach ($hrefs as $href) {
        foreach (['/admin/', '/operator/', '/crm/', '/organizations'] as $forbidden) {
            expect(str_contains($href, $forbidden))->toBeFalse("{$href} contains {$forbidden}");
        }
    }

    expect(count(focusedFrameLinks($page, FOCUSED_FRAME_BAR)))->toBeLessThanOrEqual(5);
})->with(['tickets.index', 'billing.client.invoices.index', 'cms.index']);

it('stamps no panel on the root and inlines the one shared pre-paint bootstrap before any stylesheet', function () {
    [$html, $page] = focusedFrameLoad(focusedFrameUser(), route('tickets.index'));
    $root = $page->query('/html')->item(0);
    $body = trim((string) preg_replace('#^/\*.*?\*/\s*#s', '', (string) file_get_contents(resource_path('js/shell/bootstrap.js'))));
    $stylesheet = strpos($html, 'rel="stylesheet"');

    // No contextual panel exists in this presentation, so none is stamped for the bootstrap to open.
    expect($root->hasAttribute('data-drawer-default'))->toBeFalse()
        ->and($root->hasAttribute('data-drawer-surface'))->toBeFalse()
        ->and(substr_count($html, $body))->toBe(1)
        ->and(strpos($html, $body))->toBeLessThan($stylesheet === false ? PHP_INT_MAX : $stylesheet);
});

it('draws the brand once, as a labelled mark with a hidden wordmark', function () {
    [, $page] = focusedFrameLoad(focusedFrameUser(), route('tickets.index'));

    expect($page->query('//header//svg[contains(@class, "brand-mark")][@aria-label="Intechral"]')->length)->toBe(1)
        ->and($page->query('//header//span[@aria-hidden="true"][normalize-space()="Intechral"]')->length)->toBe(1);
});

it('renders the 403 page inside the customer frame when a customer reaches an operator route', function () {
    [, $page] = focusedFrameLoad(focusedFrameUser(), '/admin/users', 403);

    expect($page->query('//*[@data-shell="focused"]')->length)->toBe(1)
        ->and($page->query('//*[@data-shell="operational"]')->length)->toBe(0);
});

it('renders the invoice payment page inside the customer frame', function () {
    $actor = focusedFrameUser();
    $invoice = Invoice::factory()->sent()->create(['client_id' => $actor->id]);

    // The payment page asks Stripe for a client secret; the frame is what is under test here.
    $this->mock(InvoiceService::class, fn ($mock) => $mock->shouldReceive('createPaymentIntent')->andReturn('pi_test_secret'));

    [, $page] = focusedFrameLoad($actor, route('billing.invoices.pay', $invoice));
    $expected = focusedFrameExpected(focusedFramePayload($actor, 'billing.invoices.pay'));

    expect($page->query('//*[@data-shell="focused"]')->length)->toBe(1)
        ->and(focusedFrameLinks($page, FOCUSED_FRAME_BAR))->toBe($expected)
        ->and(array_column(array_filter($expected, fn (array $l) => $l['current']), 'label'))->toBe(['Invoices']);
});

it('renders an own invoice inside the customer frame', function () {
    $actor = focusedFrameUser();
    $invoice = Invoice::factory()->sent()->create(['client_id' => $actor->id]);

    [, $page] = focusedFrameLoad($actor, route('billing.client.invoices.show', $invoice));

    expect($page->query('//*[@data-shell="focused"]')->length)->toBe(1)
        ->and(array_values(array_column(array_filter(focusedFrameLinks($page, FOCUSED_FRAME_BAR), fn (array $l) => $l['current']), 'label')))->toBe(['Invoices']);
});

it('leaves the Operational frame to anyone who holds an operator-surface capability', function (string $role, array $permissions) {
    [, $page] = focusedFrameLoad(focusedFrameUser($role, $permissions), route('tickets.index'));

    expect($page->query('//*[@data-shell="operational"][@data-shell-renderer="blade"]')->length)->toBe(1)
        ->and($page->query('//*[@data-shell="focused"]')->length)->toBe(0)
        ->and($page->query('//*[@data-shell-rail]')->length)->toBe(1);
})->with([
    'the operator' => ['operator', []],
    'a customer who also manages projects' => ['user', ['projects.manage']],
    'a customer who also sees every task' => ['user', ['tasks.view_all']],
]);

it('includes no Helpdesk logic in the shell partials', function () {
    foreach (['topbar', 'focused-sheet', 'skip-link'] as $partial) {
        $source = (string) file_get_contents(resource_path("views/layouts/partials/shell/{$partial}.blade.php"));

        expect(preg_match('/ticket|helpdesk|support/i', $source))->toBe(0, "{$partial} mentions the Helpdesk");
    }

    // The account-menu partial is shared with the rail and may not branch on a module either.
    expect(preg_match('/ticket|helpdesk/i', (string) file_get_contents(resource_path('views/layouts/partials/shell/account-menu.blade.php'))))->toBe(0);
});
