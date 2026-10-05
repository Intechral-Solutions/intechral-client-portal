<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\ProjectMilestoneService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP2 (Q5, §11, §12, INV-P8, INV-P15, INV-P16): `projects.show` renders the project Overview.
 *
 * The DTO itself (every key, value and rule) is pinned directly in ProjectOverviewPresenterTest (WP1).
 * These tests pin what WP2 adds on top of it, through real HTTP requests:
 *   - the route renders the `projects/show` Inertia component, authorized by ProjectPolicy::view;
 *   - the page's own props ARE the presenter's output, with nothing added or re-derived beside it;
 *   - the gated keys (budget, members, abilities.openSettings, time) reach the browser by KEY
 *     PRESENCE exactly as the presenter decides, per §7 actor shape, including the customer member;
 *   - creation lands on the Overview, and the generic project links target it.
 */

/** The props a real request's page carries, without the shell's shared props. */
function overviewPageProps($response): array
{
    $props = $response->viewData('page')['props'];

    return array_diff_key($props, array_flip(OVERVIEW_SHARED_PROPS));
}

/** Shared by every Inertia page (HandleInertiaRequests), so never part of the Overview contract. */
const OVERVIEW_SHARED_PROPS = ['app', 'auth', 'shell', 'navigation', 'flash', 'errors'];

/** JSON round trip: what the browser actually receives (an empty PHP array becomes `[]`). */
function asSerialized(array $value): array
{
    return json_decode(json_encode($value), true);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Carbon::setTestNow('2026-06-15 12:00:00');
    $this->owner = makeUser('operator', ['name' => 'Owner Olive', 'email' => 'olive@example.test']);
    $this->project = makeProject($this->owner, 'Alpha');
    $this->project->update([
        'description' => 'The Alpha project.', 'start_date' => '2026-06-01', 'target_date' => '2026-07-31', 'budget' => 2500,
    ]);
    [, $this->todo, , , $this->done] = $this->project->columns->all();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Route ────────────────────────────────────────────────────────────────────

it('renders the Overview as the projects/show component instead of redirecting', function () {
    $this->actingAs($this->owner)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('project.id', $this->project->id)
            ->where('project.name', 'Alpha'));
});

it('keeps the board at its own URI, so /board is unchanged', function () {
    expect(route('projects.board', $this->project, false))->toBe("/projects/{$this->project->id}/board")
        ->and(route('projects.show', $this->project, false))->toBe("/projects/{$this->project->id}");

    $this->actingAs($this->owner)->get(route('projects.board', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/board'));
});

it('is authorized by ProjectPolicy::view', function (string $kind, int $status) {
    $actor = settingsAccessActor($kind, $this->project);

    $this->actingAs($actor)->get(route('projects.show', $this->project))->assertStatus($status);
})->with([
    'outsider' => ['outsider', 403],
    'customer member' => ['customer_member', 200],
    'member without permissions' => ['no_permission_member', 200],
    'manager role' => ['manager_role', 200],
    'project manager' => ['project_manager', 200],
    'operator, not a member' => ['admin', 200],
    'projects.admin alone (A9)' => ['admin_only', 200],
]);

it('sends a guest to login and an unknown project to 404', function () {
    $this->get(route('projects.show', $this->project))->assertRedirect(route('login'));
    $this->actingAs($this->owner)->get('/projects/999999')->assertNotFound();
});

it('marks the Projects workspace and its All projects view active on the Overview', function () {
    $navigation = $this->actingAs($this->owner)->get(route('projects.show', $this->project))
        ->viewData('page')['props']['navigation'];

    $active = [];
    $collect = function (mixed $node) use (&$collect, &$active): void {
        if (! is_array($node)) {
            return;
        }
        if (($node['isActive'] ?? null) === true && isset($node['key'])) {
            $active[] = $node['key'];
        }
        foreach ($node as $child) {
            $collect($child);
        }
    };
    $collect($navigation);

    // One active view (ShellContractTest's single-active-item rule), owned by projects.all.
    expect(array_values(array_filter($active, fn (string $key) => str_contains($key, '.'))))->toBe(['projects.all']);
});

// ── One presenter, not a second copy ─────────────────────────────────────────

// FLIPPED IN EPIC-015 WP5: the page adds exactly one key of workspace chrome, `tabs` (the
// navigation's optional Time tab), to the unchanged presenter output. Was: no page-level additions.
it('passes the presenter output through unchanged, with only the workspace tabs added', function (string $kind) {
    $milestone = $this->project->milestones()->create(['name' => 'Kickoff', 'due_date' => '2026-06-10']);
    app(ProjectMilestoneService::class)->complete($milestone, $this->owner);
    $this->project->milestones()->create(['name' => 'Missed', 'due_date' => '2026-06-12']);
    makeTask($this->todo, ['due_date' => '2026-06-01']);
    makeTask($this->done);
    $actor = settingsAccessActor($kind, $this->project);
    TimeEntry::factory()->create(['user_id' => $actor->id, 'project_id' => $this->project->id, 'duration_minutes' => 30, 'timer_started_at' => null]);

    $props = overviewPageProps($this->actingAs($actor)->get(route('projects.show', $this->project))->assertOk());
    $dto = ProjectOverviewPresenter::overview($this->project->fresh(), $actor->fresh());

    expect($props)->toBe(asSerialized([...$dto, 'tabs' => ProjectPresenter::workspaceTabs($actor->fresh())]))
        // The Time tab is offered exactly when the Overview carries a `time` key: one seam.
        ->and($props['tabs']['time'])->toBe(array_key_exists('time', $props));
})->with(array_keys(array_filter(SETTINGS_ACCESS_ACTORS, fn ($allowed, $kind) => $kind !== 'outsider', ARRAY_FILTER_USE_BOTH)));

// ── Customer-safe props (INV-P8, INV-P16), through HTTP ──────────────────────

it('sends a customer member no budget, no roster, no Settings ability and only their own time', function () {
    $customer = settingsAccessActor('customer_member', $this->project);
    $colleague = makeUser('user', ['name' => 'Colleague Carl', 'email' => 'carl@example.test']);
    $this->project->members()->attach($colleague->id, ['role' => 'member']);
    TimeEntry::factory()->create(['user_id' => $customer->id, 'project_id' => $this->project->id, 'duration_minutes' => 40, 'timer_started_at' => null]);
    TimeEntry::factory()->create(['user_id' => $colleague->id, 'project_id' => $this->project->id, 'duration_minutes' => 500, 'timer_started_at' => null]);
    $milestone = $this->project->milestones()->create(['name' => 'Kickoff', 'due_date' => '2026-06-10']);
    app(ProjectMilestoneService::class)->complete($milestone, $this->owner);

    $props = overviewPageProps($this->actingAs($customer)->get(route('projects.show', $this->project))->assertOk());

    expect($props)->not->toHaveKey('budget')
        ->and($props)->not->toHaveKey('members')
        ->and($props['abilities'])->toBe([])
        ->and($props['time'])->toBe(['scope' => 'own', 'totalMinutes' => 40])
        // Accepted provenance (§9.4): the one person who completed a milestone, name only.
        ->and($props['milestones']['items'][0]['completedBy'])->toBe(['id' => $this->owner->id, 'name' => 'Owner Olive']);

    $json = json_encode($props);
    expect($json)->not->toContain('Colleague Carl')
        ->and($json)->not->toContain('@example.test')
        ->and($json)->not->toContain('2500')
        ->and($json)->not->toContain('email');
});

it('gives the browser the gated keys exactly when projects.edit admits the actor (INV-P16)', function (string $kind) {
    $actor = settingsAccessActor($kind, $this->project);
    $settings = $this->actingAs($actor)->get(route('projects.edit', $this->project))->status() === 200;

    $props = overviewPageProps($this->actingAs($actor)->get(route('projects.show', $this->project))->assertOk());

    expect($settings)->toBe(SETTINGS_ACCESS_ACTORS[$kind])
        ->and(array_key_exists('budget', $props))->toBe($settings, "budget key for {$kind}")
        ->and(array_key_exists('members', $props))->toBe($settings, "members key for {$kind}")
        ->and(($props['abilities']['openSettings'] ?? null) === true)->toBe($settings, "openSettings for {$kind}");
})->with(array_keys(array_filter(SETTINGS_ACCESS_ACTORS, fn ($allowed, $kind) => $kind !== 'outsider', ARRAY_FILTER_USE_BOTH)));

it('keeps an authorized null budget as a present key', function () {
    $this->project->update(['budget' => null]);

    $props = overviewPageProps($this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk());

    expect($props)->toHaveKey('budget')->and($props['budget'])->toBeNull();
});

it('sends no time key to a member with neither time permission, and all-user time with time.view_all', function () {
    $none = settingsAccessActor('no_permission_member', $this->project);
    $staff = settingsAccessActor('staff_member', $this->project);

    expect(overviewPageProps($this->actingAs($none)->get(route('projects.show', $this->project))))->not->toHaveKey('time')
        ->and(overviewPageProps($this->actingAs($staff)->get(route('projects.show', $this->project)))['time']['scope'])->toBe('all');
});

it('serializes an empty abilities set as a JSON array the page treats as no ability', function () {
    $member = settingsAccessActor('customer_member', $this->project);

    $raw = $this->actingAs($member)->get(route('projects.show', $this->project))->viewData('page')['props']['abilities'];

    // Recorded, not normalized (Amendment 1 finding 2): `[]`, typed `Partial<{openSettings: true}>`.
    expect(json_encode($raw))->toBe('[]');
});

// ── Landing and generic links (Q5, §11.3) ────────────────────────────────────

it('lands a newly created project on its Overview, which renders for its creator', function () {
    $manager = projectActor('project_manager', $this->project);

    $response = $this->actingAs($manager)->post(route('projects.store'), ['name' => 'Fresh', 'status' => 'active']);
    $fresh = Project::where('name', 'Fresh')->firstOrFail();

    $response->assertRedirect(route('projects.show', $fresh));
    $this->actingAs($manager)->get(route('projects.show', $fresh))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/show')
            ->where('health.state', 'insufficient_data')
            ->where('tasks.total', 0)
            ->where('milestones.total', 0));
});

it('points the task list project context, a generic project link, at the Overview', function () {
    $member = projectActor('member', $this->project);
    makeTask($this->todo, ['title' => 'Mine', 'assignee_id' => $member->id]);

    $row = collect($this->actingAs($member)->get(route('tasks.index'))->viewData('page')['props']['tasks']['data'])
        ->firstWhere('title', 'Mine');

    expect($row['context']['url'])->toBe(route('projects.show', $this->project))
        ->and($row['url'])->toBe(route('projects.tasks.show', [$this->project, $row['id']]));
});

it('points a direct project time entry context at the Overview, and a task entry at its task', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $member->id]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'project_id' => $this->project->id, 'description' => 'direct', 'timer_started_at' => null]);
    TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $task->id, 'description' => 'on task', 'timer_started_at' => null]);

    $entries = collect($this->actingAs($member)->get(route('time.index'))->viewData('page')['props']['entries']['data']);

    expect($entries->firstWhere('description', 'direct')['context']['url'])->toBe(route('projects.show', $this->project))
        ->and($entries->firstWhere('description', 'on task')['context']['url'])->toBe(route('projects.tasks.show', [$this->project, $task]));
});

it('points a running direct-project timer context at the Overview', function () {
    $member = projectActor('member', $this->project);

    $response = $this->actingAs($member)->postJson(route('time.timer.start'), ['project_id' => $this->project->id])->assertSuccessful();

    expect($response->json('context.url'))->toBe(route('projects.show', $this->project));
});

it('keeps explicit board actions on the board: deleting a task returns to it', function () {
    $task = makeTask($this->todo);

    $this->actingAs($this->owner)->delete(route('projects.tasks.destroy', [$this->project, $task]))
        ->assertRedirect(route('projects.board', $this->project));
});
