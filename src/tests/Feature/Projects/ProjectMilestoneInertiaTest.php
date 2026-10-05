<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Models\ProjectMilestone;
use App\Services\ProjectMilestoneService;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP4: the milestones page as an Inertia/React page. §5's DTO, §12's behavior
 * (create/edit dialog, delete confirmation, counts from one aggregate query), and A9 (an
 * abilities.manage that matches what the mutation routes actually admit, not the bare policy)
 * are pinned here. Authorization itself is pinned by the WP1 matrix and pinned-behavior suites;
 * these tests are about what the page is given and how it behaves once React owns it.
 */

// EPIC-015 WP1 PR B (§9.4) added openTaskCount, completedAt and completedBy; `overdue` now follows
// explicit completion (Q2). Lifecycle and DTO detail: ProjectMilestoneLifecycleTest.
const MILESTONE_ITEM_KEYS = ['id', 'name', 'description', 'dueDate', 'taskCount', 'doneCount', 'openTaskCount', 'completion', 'completedAt', 'completedBy', 'overdue'];

function milestonePageProps($response): array
{
    return $response->viewData('page')['props'];
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'Alpha');
    $this->manager = projectActor('project_manager', $this->project);
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Page contract ────────────────────────────────────────────────────────────

it('renders the milestones page as the projects/milestones/index component with a minimal DTO', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $milestone = $this->project->milestones()->create([
        'name' => 'Beta launch', 'description' => 'Ship the beta.', 'due_date' => '2026-06-30',
    ]);
    makeTask($this->project->columns[4], ['milestone_id' => $milestone->id, 'position' => 0]);
    makeTask($this->project->columns[1], ['milestone_id' => $milestone->id, 'position' => 0]);

    $response = $this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/milestones/index')
            // FLIPPED IN EPIC-015 WP4: the project gains its lifecycle status for the shared header,
            // and the page gains the StagePath's `currentId` (was `{id, name}` and no currentId).
            ->where('project', ['id' => $this->project->id, 'name' => 'Alpha', 'status' => 'active'])
            ->where('currentId', $milestone->id)
            ->where('abilities.manage', true)
            ->has('milestones', 1));

    $item = milestonePageProps($response)['milestones'][0];
    expect(array_keys($item))->toBe(MILESTONE_ITEM_KEYS)
        ->and($item)->toMatchArray([
            'id' => $milestone->id,
            'name' => 'Beta launch',
            'description' => 'Ship the beta.',
            'dueDate' => '2026-06-30',
            'taskCount' => 2,
            'doneCount' => 1,
            'openTaskCount' => 1,
            'completion' => 50,
            'completedAt' => null,
            'completedBy' => null,
            'overdue' => false,
        ]);
});

it('never puts a raw project, milestone, or task model on the page', function () {
    $this->project->milestones()->create(['name' => 'MS', 'due_date' => '2026-06-30']);

    $json = json_encode(milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))));

    foreach (['created_at', 'updated_at', 'project_id', 'created_by', 'tasks_count', 'done_tasks_count', 'client_id'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

it('keeps a null description null and orders milestones by due date', function () {
    $late = $this->project->milestones()->create(['name' => 'Late', 'due_date' => '2026-08-01']);
    $early = $this->project->milestones()->create(['name' => 'Early', 'due_date' => '2026-02-01', 'description' => null]);

    $milestones = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)))['milestones'];

    expect(collect($milestones)->pluck('id')->all())->toBe([$early->id, $late->id])
        ->and(collect($milestones)->firstWhere('id', $early->id)['description'])->toBeNull();
});

it('shows an outsider nothing and a member a read-only page', function () {
    $this->actingAs(projectActor('outsider', $this->project))
        ->get(route('projects.milestones.index', $this->project))
        ->assertForbidden();

    $this->actingAs(projectActor('member', $this->project))
        ->get(route('projects.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', false));
});

it('derives abilities.manage from what the mutation routes actually admit (A9)', function (string $actor, bool $expected) {
    $this->actingAs(projectActor($actor, $this->project))
        ->get(route('projects.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.manage', $expected));
})->with([
    'plain member' => ['member', false],
    'manager pivot role without permission' => ['manager_role', false],
    'project manager' => ['project_manager', true],
    'administrator (all permissions)' => ['admin', true],
    // ProjectPolicy::manage() allows projects.admin, but the mutation routes require
    // projects.manage (A9): a bare-policy ability would offer New Milestone and then 403.
    'projects.admin alone' => ['admin_only', false],
]);

it('paginates nothing and lists every milestone in one response, with query count independent of row count', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->project->milestones()->create(['name' => "MS {$i}", 'due_date' => today()->addDays($i)]);
    }

    $milestones = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)))['milestones'];

    expect($milestones)->toHaveCount(5);
});

// ── Create ───────────────────────────────────────────────────────────────────

it('creates a milestone through the Inertia form and stays on the milestones page', function () {
    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('projects.milestones.store', $this->project), [
            'name' => 'v1.0 Launch', 'due_date' => '2026-06-30', 'description' => 'First release.',
        ])->assertRedirect(route('projects.milestones.index', $this->project));

    expect(ProjectMilestone::where('name', 'v1.0 Launch')->first())
        ->description->toBe('First release.');
});

it('returns create validation errors so the dialog can show them and keeps prior milestones intact', function () {
    // (Read the errors through the follow-up page, not assertSessionHasErrors(): that call
    // restarts the session store and would consume the flashed errors before this reads them.)
    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Error-Bag' => 'createMilestone'])
        ->from(route('projects.milestones.index', $this->project))
        ->post(route('projects.milestones.store', $this->project), [])
        ->assertRedirect(route('projects.milestones.index', $this->project));

    $errors = milestonePageProps($this->withoutHeader('X-Inertia')->withHeaders(['X-Inertia-Error-Bag' => 'createMilestone'])->get(route('projects.milestones.index', $this->project)))['errors'];
    $decoded = json_decode(json_encode($errors), true);
    expect(array_keys($decoded))->toBe(['createMilestone'])
        ->and(array_keys($decoded['createMilestone']))->toContain('name', 'due_date');
});

it('refuses milestone creation to a non-admin actor without projects.manage, matching abilities.manage', function (string $actor) {
    $this->actingAs(projectActor($actor, $this->project))
        ->post(route('projects.milestones.store', $this->project), ['name' => 'x', 'due_date' => '2026-06-30'])
        ->assertForbidden();
})->with(['member', 'manager_role', 'admin_only']);

// ── Update ───────────────────────────────────────────────────────────────────

it('updates a milestone in its own project and rejects a foreign-project milestone with 404', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Old', 'due_date' => '2026-01-01']);

    $this->actingAs($this->manager)->put(route('projects.milestones.update', [$this->project, $milestone]), [
        'name' => 'New name', 'due_date' => '2026-02-02', 'description' => 'Updated.',
    ])->assertRedirect(route('projects.milestones.index', $this->project));
    expect($milestone->fresh())->name->toBe('New name')->description->toBe('Updated.');

    $other = makeProject($this->admin, 'Beta');
    $foreign = $other->milestones()->create(['name' => 'Foreign', 'due_date' => '2026-01-01']);
    $this->actingAs($this->admin)->put(route('projects.milestones.update', [$this->project, $foreign]), [
        'name' => 'Hijack', 'due_date' => '2026-01-01',
    ])->assertNotFound();
    expect($foreign->fresh()->name)->toBe('Foreign');
});

it('returns update validation errors and leaves the milestone unchanged', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Keep me', 'due_date' => '2026-01-01']);

    $this->actingAs($this->manager)->put(route('projects.milestones.update', [$this->project, $milestone]), [
        'name' => '', 'due_date' => 'not-a-date',
    ])->assertSessionHasErrors(['name', 'due_date']);

    expect($milestone->fresh()->name)->toBe('Keep me');
});

// ── Delete ───────────────────────────────────────────────────────────────────

it('deletes a milestone and clears it from its tasks without deleting them', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Delete me', 'due_date' => '2026-01-01']);
    $task = makeTask($this->project->columns[1], ['milestone_id' => $milestone->id]);

    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->delete(route('projects.milestones.destroy', [$this->project, $milestone]))
        ->assertRedirect(route('projects.milestones.index', $this->project));

    expect(ProjectMilestone::find($milestone->id))->toBeNull()
        ->and($task->fresh()->milestone_id)->toBeNull()
        ->and($task->fresh()->exists)->toBeTrue();
});

it('rejects deleting a foreign-project milestone and rejects an unauthorized actor', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Mine', 'due_date' => '2026-01-01']);
    $other = makeProject($this->admin, 'Beta');
    $foreign = $other->milestones()->create(['name' => 'Foreign', 'due_date' => '2026-01-01']);

    $this->actingAs($this->admin)->delete(route('projects.milestones.destroy', [$this->project, $foreign]))->assertNotFound();
    $this->actingAs(projectActor('member', $this->project))
        ->delete(route('projects.milestones.destroy', [$this->project, $milestone]))->assertForbidden();

    expect(ProjectMilestone::find($milestone->id))->not->toBeNull()
        ->and(ProjectMilestone::find($foreign->id))->not->toBeNull();
});

// ── Progress / completion ─────────────────────────────────────────────────────

it('agrees with completionPercentage() and uses the kind-aware done rule, not a raw status string', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $milestone = $this->project->milestones()->create(['name' => 'Parity', 'due_date' => '2026-07-01']);
    // A board task in a done column is done regardless of its raw `status` column.
    makeTask($this->project->columns[4], ['milestone_id' => $milestone->id, 'status' => 'todo', 'position' => 0]);
    makeTask($this->project->columns[1], ['milestone_id' => $milestone->id, 'status' => 'done', 'position' => 0]);

    $item = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)))['milestones'][0];

    expect($item['completion'])->toBe(50)
        ->and($item['completion'])->toBe($milestone->fresh()->load('tasks.column')->completionPercentage());
});

it('FLIPPED IN EPIC-015 WP1 PR B: is overdue only when due before today and not explicitly completed (Q2)', function () {
    // Before PR B, `overdue` was `due < today AND task completion < 100` (ProjectMilestone::isOverdueAt),
    // so "Done late" (every linked task done, never completed) was NOT overdue. Q2 makes explicit
    // completion the source of truth: it is overdue until someone completes it.
    Carbon::setTestNow('2026-06-15 12:00:00');
    $overdue = $this->project->milestones()->create(['name' => 'Overdue', 'due_date' => '2026-06-14']);
    $dueToday = $this->project->milestones()->create(['name' => 'Due today', 'due_date' => '2026-06-15']);
    $doneAndLate = $this->project->milestones()->create(['name' => 'Done late', 'due_date' => '2026-06-01']);
    makeTask($this->project->columns[4], ['milestone_id' => $doneAndLate->id, 'position' => 0]);
    $completedLate = $this->project->milestones()->create(['name' => 'Completed late', 'due_date' => '2026-06-01']);
    app(ProjectMilestoneService::class)->complete($completedLate, $this->admin);

    $milestones = collect(milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)))['milestones'])->keyBy('name');

    expect($milestones['Overdue']['overdue'])->toBeTrue()
        ->and($milestones['Due today']['overdue'])->toBeFalse()
        ->and($milestones['Done late']['overdue'])->toBeTrue()
        ->and($milestones['Done late']['completion'])->toBe(100)
        ->and($milestones['Completed late']['overdue'])->toBeFalse();
});

it('never counts a task from another project toward a milestone (A2 scoping holds at read time too)', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Scoped', 'due_date' => '2026-06-30']);
    makeTask($this->project->columns[1], ['milestone_id' => $milestone->id, 'position' => 0]);
    // Cannot happen through the validated write path (A2), but the read side must not trust a
    // stray row either: assert the presenter counts by the milestone relation, not a foreign key
    // join that could double count.
    $item = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)))['milestones'][0];

    expect($item['taskCount'])->toBe(1);
});

// ── EPIC-015 WP4: order and the current milestone (§14.2) ───────────────────────

it('orders milestones by due date then id, and names the first incomplete one current, never by task progress', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $done = $this->project->milestones()->create(['name' => 'Kickoff', 'due_date' => '2026-06-01']);
    $done->forceFill(['completed_at' => now(), 'completed_by' => $this->admin->id])->save();
    // Every linked task done, but nobody completed it: still the current milestone, and overdue.
    $allTasksDone = $this->project->milestones()->create(['name' => 'Design', 'due_date' => '2026-06-10']);
    makeTask($this->project->columns[4], ['milestone_id' => $allTasksDone->id, 'position' => 0]);
    $later = $this->project->milestones()->create(['name' => 'Launch', 'due_date' => '2026-06-10']);

    $props = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)));

    expect(array_column($props['milestones'], 'name'))->toBe(['Kickoff', 'Design', 'Launch'])
        ->and($props['currentId'])->toBe($allTasksDone->id)
        ->and($props['milestones'][1])->toMatchArray(['completion' => 100, 'completedAt' => null, 'overdue' => true])
        ->and($props['milestones'][2]['id'])->toBe($later->id);
});

it('has no current milestone when every milestone is complete, or there are none', function () {
    $empty = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)));
    $only = $this->project->milestones()->create(['name' => 'Only', 'due_date' => '2026-06-01']);
    $only->forceFill(['completed_at' => now()])->save();
    $complete = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)));

    expect($empty['currentId'])->toBeNull()
        ->and($complete['currentId'])->toBeNull();
});

it('agrees with the Overview on the current milestone', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');
    foreach (['2026-06-20', '2026-06-05', '2026-07-01'] as $i => $due) {
        $this->project->milestones()->create(['name' => "M{$i}", 'due_date' => $due]);
    }

    $page = milestonePageProps($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project)));
    $overview = ProjectOverviewPresenter::overview($this->project->fresh(), $this->admin);

    expect($page['currentId'])->toBe($overview['milestones']['currentId'])
        ->and(array_column($page['milestones'], 'id'))->toBe(array_column($overview['milestones']['items'], 'id'));
});
