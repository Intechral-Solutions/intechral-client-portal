<?php

/*
 * R0.9-CS1 review R-2: a stale Inertia asset version must not tell a customer which project ids exist. The version
 * check answers 409 as soon as it runs; it used to run after route-model binding, so an existing project got 409
 * while an unknown id got binding's 404. It now runs before binding, so for every request form an unauthorized
 * existing project and a nonexistent one are indistinguishable.
 */

use App\Http\Middleware\EnforceCustomerPilotSurface;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PilotTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** Every request form a client can send for a project URL. */
function versionForms(): array
{
    return [
        'html' => ['Accept' => 'text/html'],
        'json' => ['Accept' => 'application/json'],
        'inertia, current version' => ['X-Inertia' => 'true', 'X-Inertia-Version' => '__CURRENT__'],
        'inertia, stale version' => ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-stale-asset-version'],
        'inertia, no version' => ['X-Inertia' => 'true'],
    ];
}

/**
 * The asset version the server will compare against. It is registered by the middleware on a request, so before any
 * request `versionCurrent()` is empty: ask the middleware itself.
 */
function versionCurrent(): string
{
    return (string) (new HandleInertiaRequests)->version(Request::create('/'));
}

function versionHeaders(array $headers): array
{
    return array_map(fn ($value) => $value === '__CURRENT__' ? versionCurrent() : $value, $headers);
}

/** A response reduced to what a client can compare; only the redirect/location URL (which echoes the request) has the id taken out. */
function versionShape(TestResponse $response, int $id): array
{
    $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');

    return [
        'status' => $response->getStatusCode(),
        'location' => $location === null ? null : str_replace((string) $id, '{id}', $location),
        // Not normalized: a body that mentions an id is itself the leak, so equal bodies mean no id appears in one.
        'body' => $response->getContent(),
        'vary' => $response->headers->get('Vary'),
    ];
}

it('answers an unauthorized existing project and a nonexistent one identically, in every request form', function (string $form, array $headers) {
    $customer = pilotCustomer();
    pilotProjectFor($customer, 'Mine One');
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $absent = Project::query()->max('id') + 1000;
    pilotMode('pilot');

    $request = fn (int $id) => $this->actingAs($customer)->get("/projects/{$id}", versionHeaders($headers));

    $unauthorized = $request($theirs['project']->id);
    $missing = $request($absent);

    expect(versionShape($unauthorized, $theirs['project']->id))->toBe(versionShape($missing, $absent), $form)
        ->and($unauthorized->getContent())->not->toContain('Theirs Only')->not->toContain('PILOT-SECRET');
})->with(fn () => collect(versionForms())->map(fn ($headers, $form) => [$form, $headers])->all());

it('reaches the same answer for the stale version in the sensitive direction: no 409 for one and 404 for the other', function () {
    $customer = pilotCustomer();
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $absent = Project::query()->max('id') + 1000;
    pilotMode('pilot');
    $stale = ['X-Inertia' => 'true', 'X-Inertia-Version' => 'a-stale-asset-version'];

    $statuses = [
        $this->actingAs($customer)->get("/projects/{$theirs['project']->id}", $stale)->getStatusCode(),
        $this->actingAs($customer)->get("/projects/{$absent}", $stale)->getStatusCode(),
    ];

    expect($statuses[0])->toBe($statuses[1]);
});

it('still refuses an excluded module with a plain 404 whatever version the client sends', function () {
    $customer = pilotCustomer();
    pilotMode('pilot');

    foreach (['a-stale-asset-version', versionCurrent()] as $version) {
        $this->actingAs($customer)->get('/time', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertNotFound();
        $this->actingAs($customer)->get('/my/invoices', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertNotFound();
    }
});

it('runs the customer boundary first, then Inertia’s version check, then route-model binding', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();
    $at = fn (string $class) => array_search($class, $priority, true);

    expect($at(EnforceCustomerPilotSurface::class))->toBeInt()
        ->and($at(HandleInertiaRequests::class))->toBeInt()
        ->and($at(EnforceCustomerPilotSurface::class))->toBeLessThan($at(HandleInertiaRequests::class))
        ->and($at(HandleInertiaRequests::class))->toBeLessThan($at(SubstituteBindings::class));
});

it('keeps Inertia working for everyone after the reordering', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer, 'Mine One');
    $operator = pilotOperator();
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => versionCurrent()];

    foreach (['pilot', 'legacy'] as $mode) {
        pilotMode($mode);

        // A current-version visit gets the page as JSON, for a customer and for an operator.
        $this->actingAs($customer)->get('/projects', $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        $this->actingAs($customer)->get("/projects/{$fx['project']->id}", $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        $this->actingAs($customer)->get('/tickets', $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        $this->actingAs($operator)->get("/projects/{$fx['project']->id}", $headers)->assertOk()->assertHeader('X-Inertia', 'true');
        $this->actingAs($operator)->get('/projects', $headers)->assertOk();

        // A stale version still makes an existing page the client's problem to reload: 409 with the location, as before.
        $this->actingAs($operator)->get("/projects/{$fx['project']->id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location');
    }

    // Shared props are still built for a page in both orders: the middleware ran, so the share is intact.
    pilotMode('pilot');
    $this->actingAs($customer)->get('/projects')->assertInertia(fn (Assert $page) => $page->has('auth.permissions')->has('navigation')->has('shell'));
});

it('does not let the reordering open a route or reveal data: a refused route never reaches a controller', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer, 'Mine One');
    pilotMode('pilot');

    foreach (['/projects/'.$fx['project']->id.'/board', '/tasks', '/time', '/pages', '/operator/tickets'] as $uri) {
        foreach (versionForms() as $headers) {
            $response = $this->actingAs($customer)->get($uri, versionHeaders($headers));
            expect($response->getStatusCode())->toBeIn([404], $uri);
            expect($response->getContent())->not->toContain('PILOT-SECRET')->not->toContain('Mine One');
        }
    }
});
