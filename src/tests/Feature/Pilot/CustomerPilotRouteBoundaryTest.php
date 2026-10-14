<?php

/*
 * R0.9-CS1: the server-side route boundary. Every negative here would fail if EnforceCustomerPilotSurface were
 * removed, because each excluded route is demonstrably reachable by the same customer with the gate off.
 *
 *   1. A sweep over every registered route, through the middleware itself.
 *   2. Direct URL, JSON and mutation attempts against real records, with the response body and the database
 *      checked for disclosure and change.
 *   3. The same customer with the gate off, to prove the gate (not a policy) is what refuses them.
 *   4. Operators, guests and the Stripe webhook untouched.
 */

use App\Http\Middleware\EnforceCustomerPilotSurface;
use App\Models\CmsPage;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** The status the middleware alone gives this actor on this route, or 200 when it lets the request through. */
function pilotMiddlewareStatus(RoutingRoute $route, ?User $user): int
{
    $request = Request::create('/'.ltrim($route->uri(), '/'), $route->methods()[0]);
    $request->setRouteResolver(fn () => $route);
    $request->setUserResolver(fn () => $user);

    try {
        (new EnforceCustomerPilotSurface)->handle($request, fn () => response('reached the controller'));
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return 200;
}

/** @return list<RoutingRoute> */
function pilotRoutes(): array
{
    return Route::getRoutes()->getRoutes();
}

// ── 1. The sweep ──────────────────────────────────────────────────────────────

it('refuses every registered route that is not on the mode’s allowlist, unnamed routes included', function (string $mode, int $minimumRefused) {
    pilotMode($mode);
    $customer = pilotCustomer();
    $refused = 0;
    $allowed = [];

    foreach (pilotRoutes() as $route) {
        $status = pilotMiddlewareStatus($route, $customer);

        if (CustomerPilotGate::allowsRoute($route->getName())) {
            $allowed[] = $route->getName();
            expect($status)->toBe(200, "{$route->getName()} is on the {$mode} allowlist and must reach its controller");

            continue;
        }

        $refused++;
        expect($status)->toBe(404, ($route->getName() ?? '(unnamed) ').' '.$route->uri()." must be refused to a {$mode} customer");
    }

    // The sweep cannot pass vacuously: it saw many refusals, and every allowlisted name was exercised.
    expect($refused)->toBeGreaterThan($minimumRefused)
        ->and($allowed)->toEqualCanonicalizing(array_keys(CustomerPilotGate::routes(CustomerPilotGate::mode())));
})->with([
    'closed' => ['closed', 120],
    'pilot' => ['pilot', 110],
]);

it('refuses the Helpdesk and projects to a closed customer, which pilot mode allows', function () {
    $customer = pilotCustomer();

    foreach (['/tickets', '/tickets/create', '/projects', '/projects/1'] as $uri) {
        pilotMode('closed');
        expect(pilotMiddlewareStatus(pilotRouteFor($uri), $customer))->toBe(404, "closed {$uri}");

        pilotMode('pilot');
        expect(pilotMiddlewareStatus(pilotRouteFor($uri), $customer))->toBe(200, "pilot {$uri}");
    }
});

/** The registered GET route a path resolves to. */
function pilotRouteFor(string $uri): RoutingRoute
{
    return Route::getRoutes()->match(Request::create($uri, 'GET'));
}

it('lets every route through for an operator and a guest in every mode, and for a customer in legacy mode only', function () {
    $operator = pilotOperator();
    $customer = pilotCustomer();

    foreach (['closed', 'pilot', 'legacy', 'nonsense', ''] as $mode) {
        pilotMode($mode);

        foreach (pilotRoutes() as $route) {
            expect(pilotMiddlewareStatus($route, $operator))->toBe(200, "operator on {$route->uri()} ({$mode})")
                ->and(pilotMiddlewareStatus($route, null))->toBe(200, "guest on {$route->uri()} ({$mode})");
        }
    }

    pilotMode('legacy');
    foreach (pilotRoutes() as $route) {
        expect(pilotMiddlewareStatus($route, $customer))->toBe(200, "customer, legacy, on {$route->uri()}");
    }
});

it('refuses a customer everywhere but the account routes when the configuration is missing', function () {
    config(['portal' => []]);
    $customer = pilotCustomer();

    foreach (pilotRoutes() as $route) {
        $expected = CustomerPilotGate::allowsRoute($route->getName()) ? 200 : 404;
        expect(pilotMiddlewareStatus($route, $customer))->toBe($expected, "{$route->uri()} with no portal configuration");
    }

    expect(CustomerPilotGate::allowsRoute('tickets.index'))->toBeFalse()
        ->and(CustomerPilotGate::allowsRoute('dashboard'))->toBeTrue();
});

it('gives the capability-less customer a 403 on a project route, before any record is loaded', function () {
    pilotMode('pilot');
    $noProjects = pilotCustomRoleUser('no-projects', array_values(array_diff(
        PermissionCatalogue::userDefaults(),
        ['projects.view'],
    )));

    // A project id that does not exist: a 404 here would mean route-model binding ran first.
    $this->actingAs($noProjects)->get('/projects/999999')->assertForbidden();
    $this->actingAs($noProjects)->get(route('projects.index'))->assertForbidden();
});

it('is registered in the web group ahead of route-model binding', function () {
    $kernelPriority = app(Kernel::class)->getMiddlewarePriority();
    $position = fn (string $class) => array_search($class, $kernelPriority, true);

    expect($position(EnforceCustomerPilotSurface::class))->toBeInt()
        ->and($position(EnforceCustomerPilotSurface::class))->toBeLessThan($position(SubstituteBindings::class))
        ->and($position(EnforceCustomerPilotSurface::class))->toBeGreaterThan($position(StartSession::class));

    expect(Route::getMiddlewareGroups()['web'])->toContain(EnforceCustomerPilotSurface::class);
});

// ── 2 and 3. Direct access to real records ────────────────────────────────────

/**
 * Every excluded request a customer could make, against real records. Each entry is a closure so the
 * ids exist when it runs.
 *
 * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
 */
function pilotExcludedRequests(array $fx, Invoice $invoice, CmsPage $page): array
{
    $p = $fx['project']->id;
    $t = $fx['task']->id;
    $item = $fx['task']->checklistItems()->first()->id;
    $m = $fx['milestone']->id;
    $entry = $fx['entry']->id;

    return [
        // Finance and payments
        'invoice list' => ['GET', '/my/invoices'],
        'invoice detail' => ['GET', "/my/invoices/{$invoice->id}"],
        'pay page' => ['GET', "/billing/invoices/{$invoice->id}/pay"],
        'pay intent' => ['POST', "/billing/invoices/{$invoice->id}/pay/intent"],
        'operator invoices' => ['GET', '/billing/invoices'],
        // Time
        'time page' => ['GET', '/time'],
        'time allocation' => ['GET', '/time/allocation'],
        'active timers json' => ['GET', '/time/timers/active'],
        'time context options' => ['GET', '/time/context-options'],
        'log time' => ['POST', '/time', ['date' => '2026-10-01', 'duration_minutes' => 30, 'description' => 'x']],
        'start timer' => ['POST', '/time/timer/start', ['description' => 'x']],
        'edit time entry' => ['PUT', "/time/{$entry}", ['description' => 'changed']],
        'delete time entry' => ['DELETE', "/time/{$entry}"],
        'project time' => ['GET', "/projects/{$p}/time"],
        // Standalone tasks
        'tasks list' => ['GET', '/tasks'],
        'task detail' => ['GET', "/tasks/{$t}"],
        'create task' => ['POST', '/tasks', ['title' => 'sneaky']],
        'update task' => ['PUT', "/tasks/{$t}", ['title' => 'changed']],
        'delete task' => ['DELETE', "/tasks/{$t}"],
        'complete task' => ['PUT', "/tasks/{$t}/complete"],
        'bulk tasks' => ['POST', '/tasks/bulk', ['action' => 'complete', 'ids' => [$t]]],
        // Project board, tasks, comments, checklists
        'project board' => ['GET', "/projects/{$p}/board"],
        'project tasks list' => ['GET', "/projects/{$p}/tasks"],
        'project task detail' => ['GET', "/projects/{$p}/tasks/{$t}"],
        'create project task' => ['POST', "/projects/{$p}/tasks", ['title' => 'sneaky']],
        'update project task' => ['PUT', "/projects/{$p}/tasks/{$t}", ['title' => 'changed']],
        'delete project task' => ['DELETE', "/projects/{$p}/tasks/{$t}"],
        'move project task' => ['PUT', "/projects/{$p}/tasks/{$t}/move", ['column_id' => 1, 'position' => 0]],
        'comment on task' => ['POST', "/projects/{$p}/tasks/{$t}/comments", ['body' => 'sneaky comment']],
        'add checklist item' => ['POST', "/projects/{$p}/tasks/{$t}/checklist", ['title' => 'sneaky']],
        'toggle checklist item' => ['PUT', "/projects/{$p}/tasks/{$t}/checklist/{$item}/toggle"],
        'delete checklist item' => ['DELETE', "/projects/{$p}/tasks/{$t}/checklist/{$item}"],
        // Milestones page and mutations
        'milestones page' => ['GET', "/projects/{$p}/milestones"],
        'complete milestone' => ['PUT', "/projects/{$p}/milestones/{$m}/complete"],
        'delete milestone' => ['DELETE', "/projects/{$p}/milestones/{$m}"],
        // Project administration
        'edit project' => ['GET', "/projects/{$p}/edit"],
        'update project' => ['PUT', "/projects/{$p}", ['name' => 'renamed', 'status' => 'active']],
        'delete project' => ['DELETE', "/projects/{$p}"],
        'sync members' => ['PUT', "/projects/{$p}/members", ['members' => []]],
        // CMS
        'pages list' => ['GET', '/pages'],
        'page detail' => ['GET', "/pages/{$page->slug}"],
        'cms admin' => ['GET', '/operator/cms'],
        'cms create' => ['POST', '/operator/cms', ['title' => 'x', 'slug' => 'x', 'body' => 'x']],
        // Operator surfaces
        'operator queue' => ['GET', '/operator/tickets'],
        'crm' => ['GET', '/crm/companies'],
        'users admin' => ['GET', '/admin/users'],
        'roles admin' => ['GET', '/admin/roles'],
        'send invitation' => ['POST', '/invitations', ['email' => 'x@example.com']],
        // SSO for a signed-in customer (link intent)
        'sso redirect' => ['GET', '/auth/google/redirect'],
        'sso callback' => ['GET', '/auth/google/callback'],
        // The Stripe endpoint is for Stripe, not for a signed-in customer
        'stripe webhook' => ['POST', '/webhooks/stripe', ['type' => 'payment_intent.succeeded']],
    ];
}

function pilotExcludedFixture(): array
{
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    $invoice = pilotInvoiceFor($customer);
    $page = CmsPage::factory()->create(['title' => 'PILOT-SECRET page', 'slug' => 'pilot-secret-page', 'status' => 'published', 'published_at' => now()]);

    return [$customer, $fx, $invoice, $page];
}

it('refuses every excluded direct URL, verb and JSON request with a 404 that discloses nothing', function () {
    [$customer, $fx, $invoice, $page] = pilotExcludedFixture();
    pilotMode('pilot');

    foreach (pilotExcludedRequests($fx, $invoice, $page) as $label => $request) {
        [$method, $uri] = $request;
        $payload = $request[2] ?? [];

        foreach ([false, true] as $json) {
            $response = $json
                ? $this->actingAs($customer)->json($method, $uri, $payload)
                : $this->actingAs($customer)->call($method, $uri, $payload);

            expect($response->getStatusCode())->toBe(404, "{$label} ({$method} {$uri}".($json ? ', JSON' : '').')');

            foreach (['PILOT-SECRET', $invoice->invoice_number, 'Customer Visible Project'] as $needle) {
                expect($response->getContent())->not->toContain($needle, "{$label} leaked [{$needle}]");
            }
        }
    }
});

it('leaves every excluded record exactly as it was after the refused mutations', function () {
    [$customer, $fx, $invoice, $page] = pilotExcludedFixture();
    pilotMode('pilot');

    $before = [
        'tasks' => Task::count(), 'comments' => TaskComment::count(), 'entries' => TimeEntry::count(),
        'checklist' => $fx['task']->checklistItems()->count(), 'milestones' => $fx['project']->milestones()->count(),
        'task' => $fx['task']->fresh()->only(['title', 'status', 'column_id']),
        'project' => $fx['project']->fresh()->only(['name', 'status']),
        'milestone_completed' => $fx['milestone']->fresh()->completed_at?->toIso8601String(),
        'invoice' => $invoice->fresh()->status,
    ];

    foreach (pilotExcludedRequests($fx, $invoice, $page) as $request) {
        if ($request[0] !== 'GET') {
            $this->actingAs($customer)->call($request[0], $request[1], $request[2] ?? []);
        }
    }

    expect(Task::count())->toBe($before['tasks'])
        ->and(TaskComment::count())->toBe($before['comments'])
        ->and(TimeEntry::count())->toBe($before['entries'])
        ->and($fx['task']->checklistItems()->count())->toBe($before['checklist'])
        ->and($fx['project']->milestones()->count())->toBe($before['milestones'])
        ->and($fx['task']->fresh()->only(['title', 'status', 'column_id']))->toBe($before['task'])
        ->and($fx['project']->fresh()->only(['name', 'status']))->toBe($before['project'])
        ->and($fx['milestone']->fresh()->completed_at?->toIso8601String())->toBe($before['milestone_completed'])
        ->and($invoice->fresh()->status)->toBe($before['invoice'])
        ->and(Project::whereKey($fx['project']->id)->exists())->toBeTrue();
});

it('reaches the same excluded GET pages with the gate off, which proves the gate is what refuses them', function () {
    [$customer, $fx, $invoice, $page] = pilotExcludedFixture();
    pilotMode('legacy');

    $p = $fx['project']->id;
    foreach ([
        '/my/invoices' => 200,
        "/my/invoices/{$invoice->id}" => 200,
        '/time' => 200,
        '/time/timers/active' => 200,
        '/tasks' => 200,
        "/projects/{$p}/board" => 200,
        "/projects/{$p}/tasks" => 200,
        "/projects/{$p}/milestones" => 200,
        '/pages' => 200,
        "/pages/{$page->slug}" => 200,
    ] as $uri => $status) {
        $this->actingAs($customer)->get($uri)->assertStatus($status);
    }

    // Sentinels are really in the data, so the "leaks nothing" assertions above are not vacuous.
    $this->actingAs($customer)->get("/projects/{$p}/tasks")->assertSee('PILOT-SECRET', false);
});

it('refuses a customer who is not a member of the project the same way, and adds nothing to disclose', function () {
    [$customer, $fx] = pilotExcludedFixture();
    $other = pilotCustomer();
    pilotMode('pilot');

    $p = $fx['project']->id;
    foreach (["/projects/{$p}/board", "/projects/{$p}/tasks", "/projects/{$p}/milestones", "/projects/{$p}/time"] as $uri) {
        $this->actingAs($other)->get($uri)->assertNotFound();
    }
});

// ── 4. Operators, guests, the Stripe webhook ──────────────────────────────────

it('leaves operator workflows reachable with the gate on', function () {
    [, $fx, $invoice, $page] = pilotExcludedFixture();
    $operator = pilotOperator();
    pilotMode('pilot');

    $p = $fx['project']->id;
    foreach ([
        '/billing/invoices', "/billing/invoices/{$invoice->id}", '/time', '/tasks', "/projects/{$p}", "/projects/{$p}/board",
        "/projects/{$p}/tasks", "/projects/{$p}/milestones", "/projects/{$p}/edit", '/operator/tickets', '/crm/companies',
        '/operator/cms', '/admin/users', '/admin/roles', '/pages', '/dashboard', '/profile',
    ] as $uri) {
        $this->actingAs($operator)->get($uri)->assertOk();
    }

    // An operator mutation still works (a project comment), so the gate is not merely allowing reads.
    $this->actingAs($operator)->post("/projects/{$p}/tasks/{$fx['task']->id}/comments", ['body' => 'operator reply'])
        ->assertRedirect();
    expect(TaskComment::where('body', 'operator reply')->exists())->toBeTrue();
});

it('shows an operator the full internal project overview, not the customer one', function () {
    [, $fx] = pilotExcludedFixture();
    pilotMode('pilot');

    $this->actingAs($fx['operator'])->get("/projects/{$fx['project']->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('projects/show')
            ->has('health')->has('tasks')->has('budget'));
});

it('does not touch guests: authentication pages and the sign-in redirect still work', function () {
    pilotMode('pilot');

    $this->get('/login')->assertOk();
    $this->get('/forgot-password')->assertOk();
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/projects')->assertRedirect('/login');
});

it('does not touch the Stripe webhook for Stripe, and still verifies the signature', function () {
    pilotMode('pilot');

    // No session, no user: the gate has nothing to restrict, so the controller's own signature check answers.
    $this->postJson('/webhooks/stripe', ['type' => 'payment_intent.succeeded'], ['Stripe-Signature' => 't=1,v1=bad'])
        ->assertStatus(400);
});
