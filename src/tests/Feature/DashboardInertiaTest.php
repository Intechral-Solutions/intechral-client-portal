<?php

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/**
 * EPIC-013 WP7, §25.1 "Home DTO minimality": "`dashboard` still ships exactly `metrics`,
 * `recentTickets`, `quickActions`, `crmSummary` — no new props."
 *
 * An Inertia response's top-level props are the page's own props merged with every prop
 * `HandleInertiaRequests::share()` (plus Inertia's own `errors`) adds to every response on every
 * page, so asserting the raw top-level key set directly would break the instant an unrelated page
 * gains a legitimate shared prop — which is not what §25.1 is protecting. Listing the shared keys by
 * name, rather than trying to infer them, is what makes the remainder trustworthy as *Home's own*
 * props: this list is the one place a real change to what every page shares must show up, so it
 * cannot go unnoticed the way a silently-passing diff could.
 *
 * @return array<int, string>
 */
function inertiaSharedPropKeys(): array
{
    return ['errors', 'app', 'auth', 'shell', 'navigation', 'flash'];
}

it('requires authentication for the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect('/login');
});

it('renders scoped user dashboard data without model leakage', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();

    Ticket::factory()->open()->for($user, 'user')->create(['title' => 'Visible ticket']);
    Ticket::factory()->open()->for($other, 'user')->create(['title' => 'Hidden ticket']);
    $project = Project::factory()->active()->create();
    $project->members()->attach($user, ['role' => 'member']);
    Project::factory()->active()->create();
    TimeEntry::factory()->create(['user_id' => $user->id, 'date' => now(), 'duration_minutes' => 90]);
    Invoice::factory()->sent()->create(['client_id' => $user->id]);
    Invoice::factory()->sent()->create(['client_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->has('metrics', 4)
            ->where('metrics.0.value', 1)
            ->where('metrics.1.value', 1)
            ->where('metrics.1.visit', 'inertia')
            ->where('metrics.2.value', '1.5h')
            ->where('metrics.2.visit', 'inertia')
            ->where('metrics.3.value', 1)
            ->where('quickActions.1.visit', 'inertia')
            ->where('quickActions.2.key', 'projects')
            ->where('quickActions.2.visit', 'inertia')
            ->has('recentTickets', 1)
            ->where('recentTickets.0.subject', 'Visible ticket')
            ->missing('recentTickets.0.description')
            ->where('crmSummary', null));
});

it('renders operator-wide dashboard data and crm summary', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    Ticket::factory()->open()->count(2)->create();

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->where('metrics.0.value', 2)
            ->has('crmSummary.companies')
            ->has('crmSummary.contacts')
            ->has('crmSummary.orgs'));
});

it('ships exactly the four DTO props §25.1 names, and nothing WP7 fabricated', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $response = $this->actingAs($operator)->get(route('dashboard'));
    $response->assertInertia(fn (Assert $page) => $page->component('dashboard/index'));

    // `AssertableInertia::toArray()` returns the whole page wrapper (component/props/url/version),
    // not the props alone, so the raw props are read the same way `fromTestResponse()` itself does:
    // straight off the view data.
    $page = json_decode(json_encode($response->viewData('page')), true);

    // Everything on the response beyond what every page shares (`inertiaSharedPropKeys()`) is
    // Home's own. §25.1 says that set is exactly these four names — proven here, not merely
    // spot-checked, so a fabricated fifth prop (an "approvals" list, a "project health" score,
    // "SLA" or "my work" data, watch lists, notifications — the §21.1 concepts this epic explicitly
    // excludes) is caught by name, whatever it is called.
    $ownProps = array_values(array_diff(array_keys($page['props']), inertiaSharedPropKeys()));

    expect($ownProps)->toEqualCanonicalizing([
        'metrics',
        'recentTickets',
        'quickActions',
        'crmSummary',
    ]);
});
