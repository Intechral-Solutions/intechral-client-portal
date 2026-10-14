<?php

/*
 * R0.9-CS1: what a pilot customer receives for projects. An exact-key allowlist of the page props, a raw-body
 * search for every internal sentinel, and the member / non-member / unauthorized-id cases, each checking the
 * HTTP response and the absence of the other account's data.
 */

use App\Models\CrmCompany;
use App\Models\Project;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** The page's own props (the shared set removed), as a plain array. */
function pilotPageProps(TestResponse $response): array
{
    $props = json_decode(json_encode($response->viewData('page')), true)['props'];

    return array_diff_key($props, array_flip(['errors', 'app', 'auth', 'shell', 'navigation', 'flash']));
}

it('sends a member the project name and status and milestone name and date, and nothing else', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    pilotMode('pilot');

    $response = $this->actingAs($customer)->get(route('projects.show', $fx['project']));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/customer-show'));

    expect(pilotPageProps($response))->toBe([
        'project' => ['name' => 'Customer Visible Project', 'status' => 'active'],
        'milestones' => [['name' => 'Go-live milestone', 'dueDate' => '2026-11-02']],
    ]);
});

it('puts no internal datum anywhere in the response, in either the document or the Inertia form', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    pilotMode('pilot');

    $needles = [
        'PILOT-SECRET', 'internal description', '98765', 'budget', 'description', 'Operator Name', $fx['operator']->email,
        '2026-12-31', '2026-01-05', 'health', 'completed', 'completedBy', 'completedAt', 'taskCount', 'memberCount', 'totalMinutes',
    ];

    foreach ([
        $this->actingAs($customer)->get(route('projects.show', $fx['project'])),
        $this->actingAs($customer)->get(route('projects.show', $fx['project']), ['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion()]),
        $this->actingAs($customer)->get(route('projects.index')),
    ] as $response) {
        $response->assertOk();

        foreach ($needles as $needle) {
            expect($response->getContent())->not->toContain($needle, "[{$needle}] reached the customer");
        }
    }
});

it('proves the sentinels exist for an operator, so the absence above is not vacuous', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    pilotMode('pilot');

    $body = $this->actingAs($fx['operator'])->get(route('projects.show', $fx['project']))->getContent();

    expect($body)->toContain('PILOT-SECRET')->toContain('98765.43');
});

it('lists only the projects the customer is a member of, with an id, a name and a status', function () {
    $customer = pilotCustomer();
    $mine = pilotProjectFor($customer, 'Mine One');
    $stranger = pilotCustomer();
    $theirs = pilotProjectFor($stranger, 'Theirs Only');
    pilotMode('pilot');

    $response = $this->actingAs($customer)->get(route('projects.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('projects/customer-index'));

    $rows = pilotPageProps($response)['projects']['data'];
    expect($rows)->toBe([['id' => $mine['project']->id, 'name' => 'Mine One', 'status' => 'active']])
        ->and($response->getContent())->not->toContain('Theirs Only')
        ->and(array_keys(pilotPageProps($response)))->toBe(['projects']);
});

it('answers a project the customer is not a member of exactly as one that does not exist', function () {
    $customer = pilotCustomer();
    pilotProjectFor($customer, 'Mine One');
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $absent = Project::query()->max('id') + 1000;
    pilotMode('pilot');

    foreach (['html', 'json', 'inertia'] as $kind) {
        $request = fn (int $id) => match ($kind) {
            'json' => $this->actingAs($customer)->getJson("/projects/{$id}"),
            'inertia' => $this->actingAs($customer)->get("/projects/{$id}", ['X-Inertia' => 'true', 'X-Inertia-Version' => inertia()->getVersion()]),
            default => $this->actingAs($customer)->get("/projects/{$id}"),
        };

        $unauthorized = $request($theirs['project']->id);
        $missing = $request($absent);

        expect($unauthorized->getStatusCode())->toBe(404, $kind)
            ->and($missing->getStatusCode())->toBe(404, $kind)
            ->and($unauthorized->getContent())->not->toContain('Theirs Only')->not->toContain('PILOT-SECRET')->not->toContain('Go-live');

        // Not merely the same status: the same body, so nothing but the id could tell them apart. (The framework's own
        // JSON 404 for an unknown id names the model and the id even with debug off; the customer boundary replaces it.)
        expect($unauthorized->getContent())->toBe($missing->getContent(), $kind);
    }
});

it('keeps the 403 for a customer who lost projects.view, and for it alone', function () {
    $noProjects = pilotCustomRoleUser('no-projects-view-2', array_values(array_diff(
        PermissionCatalogue::userDefaults(),
        ['projects.view'],
    )));
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    pilotMode('pilot');

    // Decided before any record is loaded, so it cannot reveal whether the id exists.
    $this->actingAs($noProjects)->get("/projects/{$theirs['project']->id}")->assertForbidden();
    $this->actingAs($noProjects)->get('/projects/9999999')->assertForbidden();
});

it('does not widen visibility: a company link or an operator role on the other side changes nothing', function () {
    $customer = pilotCustomer();
    $theirs = pilotProjectFor(pilotCustomer(), 'Theirs Only');
    $company = CrmCompany::factory()->create();
    $theirs['project']->companies()->attach($company->id);
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('projects.show', $theirs['project']))->assertNotFound();
    expect(pilotPageProps($this->actingAs($customer)->get(route('projects.index')))['projects']['data'])->toBe([]);
});

it('gives a customer who lost projects.view a 403 on both project routes, not the data', function () {
    $noProjects = pilotCustomRoleUser('no-projects-view', array_values(array_diff(
        PermissionCatalogue::userDefaults(),
        ['projects.view'],
    )));
    $fx = pilotProjectFor($noProjects);
    pilotMode('pilot');

    $this->actingAs($noProjects)->get(route('projects.index'))->assertForbidden();
    $this->actingAs($noProjects)->get(route('projects.show', $fx['project']))->assertForbidden();
});

it('sends milestones as a name and a due date only, whatever their state', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    $fx['project']->milestones()->create(['name' => 'Planned later', 'description' => 'PILOT-SECRET later', 'due_date' => '2026-12-01']);
    $fx['project']->milestones()->create(['name' => 'Overdue and open', 'description' => 'PILOT-SECRET overdue', 'due_date' => '2020-01-01']);
    pilotMode('pilot');

    $response = $this->actingAs($customer)->get(route('projects.show', $fx['project']));

    // Ordered by due date; a completed, a planned and an overdue milestone look identical.
    expect(pilotPageProps($response)['milestones'])->toBe([
        ['name' => 'Overdue and open', 'dueDate' => '2020-01-01'],
        ['name' => 'Go-live milestone', 'dueDate' => '2026-11-02'],
        ['name' => 'Planned later', 'dueDate' => '2026-12-01'],
    ]);
});

it('keeps the customer presentation off an operator even when the operator is also a project member', function () {
    $fx = pilotProjectFor(pilotCustomer());
    pilotMode('pilot');

    $this->actingAs($fx['operator'])->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->component('projects/index'));
});

it('does not apply the customer presentation in legacy mode', function () {
    $customer = pilotCustomer();
    $fx = pilotProjectFor($customer);
    pilotMode('legacy');

    $this->actingAs($customer)->get(route('projects.show', $fx['project']))
        ->assertInertia(fn (Assert $page) => $page->component('projects/show'));
});
