<?php

use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Services\ProjectMilestoneService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR B (§9, Q2, INV-P9): explicit milestone completion.
 *
 *   NEW CONTRACT     — completion schema, Complete/Reopen, the DTO additions.
 *   FLIPPED IN PR B  — overdue no longer reads task progress (§16.3); the old rule is in the comment.
 *   PRESERVED        — the A9 management rule, the 404 for a foreign milestone, update/delete.
 *
 * Completion is `completed_at` (+ `completed_by`). Task progress (done linked tasks / linked tasks)
 * is informational only and never completes, reopens or blocks a milestone, and a milestone's
 * completion never moves, completes or reopens a task.
 */

/** Same actor meanings as ProjectSettingsAccessTest; who may complete/reopen (A9). */
const MILESTONE_LIFECYCLE_ACTORS = [
    'outsider' => 403,
    'member' => 403,             // customer (`user` role) member
    'manager_role' => 403,       // manager pivot WITHOUT projects.manage
    'admin_only' => 403,         // projects.admin alone: the policy allows, the route middleware does not
    'project_manager' => 'allow',
    'admin' => 'allow',
];

/** Fingerprint of every linked task, so a test can prove the milestone change moved nothing. */
function milestoneTaskFingerprint(ProjectMilestone $milestone): array
{
    return DB::table('tasks')->where('milestone_id', $milestone->id)->orderBy('id')
        ->get(['id', 'column_id', 'position', 'status', 'updated_at', 'assignee_id', 'due_date'])
        ->map(fn ($row) => (array) $row)
        ->all();
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Carbon::setTestNow('2026-06-15 12:00:00');
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'Alpha');
    $this->manager = projectActor('project_manager', $this->project);
    $this->milestone = $this->project->milestones()->create(['name' => 'Go-live', 'due_date' => '2026-06-30']);
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Schema ───────────────────────────────────────────────────────────────────

it('NEW CONTRACT: adds nullable completed_at and completed_by (FK to users, SET NULL on delete); existing milestones start incomplete', function () {
    $rule = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
        ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'project_milestones')
        ->where('REFERENCED_TABLE_NAME', 'users')
        ->pluck('DELETE_RULE')
        ->all();

    expect($rule)->toBe(['SET NULL'])
        ->and($this->milestone->fresh()->completed_at)->toBeNull()
        ->and($this->milestone->fresh()->completed_by)->toBeNull();
});

it('NEW CONTRACT: deleting the completing user keeps the milestone complete and nulls completed_by', function () {
    $completer = makeUser('operator');
    app(ProjectMilestoneService::class)->complete($this->milestone, $completer);

    $completer->delete();

    expect($this->milestone->fresh())
        ->completed_at->not->toBeNull()
        ->completed_by->toBeNull();
});

// ── Complete / Reopen ────────────────────────────────────────────────────────

it('NEW CONTRACT: an authorized manager completes a milestone, recording when and by whom', function () {
    $this->actingAs($this->manager)
        ->put(route('projects.milestones.complete', [$this->project, $this->milestone]))
        ->assertRedirect(route('projects.milestones.index', $this->project))
        ->assertSessionHas('success', 'Milestone completed.');

    expect($this->milestone->fresh())
        ->completed_at->toEqual(Carbon::parse('2026-06-15 12:00:00'))
        ->completed_by->toBe($this->manager->id);
});

it('NEW CONTRACT: completing an already complete milestone is a no-op success that keeps the original values', function () {
    $this->actingAs($this->manager)->put(route('projects.milestones.complete', [$this->project, $this->milestone]));

    Carbon::setTestNow('2026-06-20 09:00:00');
    $this->actingAs($this->admin)
        ->put(route('projects.milestones.complete', [$this->project, $this->milestone]))
        ->assertRedirect(route('projects.milestones.index', $this->project));

    expect($this->milestone->fresh())
        ->completed_at->toEqual(Carbon::parse('2026-06-15 12:00:00'))
        ->completed_by->toBe($this->manager->id);
});

it('NEW CONTRACT: reopen clears both fields, and reopening an open milestone is a no-op success', function () {
    $this->actingAs($this->manager)->put(route('projects.milestones.complete', [$this->project, $this->milestone]));

    $this->actingAs($this->admin)
        ->put(route('projects.milestones.reopen', [$this->project, $this->milestone]))
        ->assertRedirect(route('projects.milestones.index', $this->project))
        ->assertSessionHas('success', 'Milestone reopened.');
    expect($this->milestone->fresh())->completed_at->toBeNull()->completed_by->toBeNull();

    $this->actingAs($this->admin)
        ->put(route('projects.milestones.reopen', [$this->project, $this->milestone]))
        ->assertRedirect(route('projects.milestones.index', $this->project));
    expect($this->milestone->fresh())->completed_at->toBeNull()->completed_by->toBeNull();
});

it('NEW CONTRACT: a zero-task milestone is a legitimate checkpoint that can be completed manually', function () {
    $kickoff = $this->project->milestones()->create(['name' => 'Kickoff', 'due_date' => '2026-06-01']);
    expect($kickoff->tasks()->count())->toBe(0)->and($kickoff->isOverdue())->toBeTrue();

    $this->actingAs($this->manager)->put(route('projects.milestones.complete', [$this->project, $kickoff]))->assertRedirect();

    expect($kickoff->fresh())->isCompleted()->toBeTrue()->isOverdue()->toBeFalse();
});

it('enforces the A9 rule on Complete and Reopen and changes nothing when denied', function (string $actor, string|int $expected) {
    $user = projectActor($actor, $this->project);
    $complete = $this->actingAs($user)->put(route('projects.milestones.complete', [$this->project, $this->milestone]));

    if ($expected === 403) {
        $complete->assertForbidden();
        expect($this->milestone->fresh()->completed_at)->toBeNull();

        app(ProjectMilestoneService::class)->complete($this->milestone, $this->admin);
        $this->actingAs($user)->put(route('projects.milestones.reopen', [$this->project, $this->milestone]))->assertForbidden();
        expect($this->milestone->fresh()->completed_by)->toBe($this->admin->id);

        return;
    }

    $complete->assertRedirect(route('projects.milestones.index', $this->project));
    expect($this->milestone->fresh()->completed_by)->toBe($user->id);
    $this->actingAs($user)->put(route('projects.milestones.reopen', [$this->project, $this->milestone]))->assertRedirect();
    expect($this->milestone->fresh()->completed_at)->toBeNull();
})->with(fn () => collect(MILESTONE_LIFECYCLE_ACTORS)->map(fn ($expected, $actor) => [$actor, $expected])->all());

it('sends a guest to login on Complete and Reopen', function () {
    $this->put(route('projects.milestones.complete', [$this->project, $this->milestone]))->assertRedirect('/login');
    $this->put(route('projects.milestones.reopen', [$this->project, $this->milestone]))->assertRedirect('/login');
    expect($this->milestone->fresh()->completed_at)->toBeNull();
});

it('PRESERVED: a foreign-project milestone is 404 for an authorized manager, and authorization answers first for an outsider', function () {
    $other = makeProject($this->admin, 'Beta');
    $foreign = $other->milestones()->create(['name' => 'Foreign', 'due_date' => '2026-06-30']);

    foreach (['complete', 'reopen'] as $action) {
        $this->actingAs($this->manager)->put(route("projects.milestones.{$action}", [$this->project, $foreign]))->assertNotFound();
        $this->actingAs($this->admin)->put(route("projects.milestones.{$action}", [$this->project, $foreign]))->assertNotFound();
        $this->actingAs(projectActor('outsider', $this->project))->put(route("projects.milestones.{$action}", [$this->project, $foreign]))->assertForbidden();
    }

    expect($foreign->fresh()->completed_at)->toBeNull();
});

it('PRESERVED: the update route never touches completion, even when the payload names it', function () {
    app(ProjectMilestoneService::class)->complete($this->milestone, $this->manager);

    $this->actingAs($this->admin)->put(route('projects.milestones.update', [$this->project, $this->milestone]), [
        'name' => 'Renamed', 'due_date' => '2026-07-01', 'completed_at' => null, 'completed_by' => $this->admin->id,
    ])->assertRedirect();

    expect($this->milestone->fresh())
        ->name->toBe('Renamed')
        ->completed_at->toEqual(Carbon::parse('2026-06-15 12:00:00'))
        ->completed_by->toBe($this->manager->id);

    $open = $this->project->milestones()->create(['name' => 'Open', 'due_date' => '2026-07-01']);
    $this->actingAs($this->admin)->put(route('projects.milestones.update', [$this->project, $open]), [
        'name' => 'Still open', 'due_date' => '2026-07-01', 'completed_at' => '2026-06-01 00:00:00', 'completed_by' => $this->admin->id,
    ])->assertRedirect();
    expect($open->fresh())->completed_at->toBeNull()->completed_by->toBeNull();
});

it('PRESERVED: a completed milestone can still be deleted, and its tasks keep existing with no milestone', function () {
    $task = makeTask($this->project->columns[1], ['milestone_id' => $this->milestone->id]);
    app(ProjectMilestoneService::class)->complete($this->milestone, $this->manager);

    $this->actingAs($this->manager)->delete(route('projects.milestones.destroy', [$this->project, $this->milestone]))->assertRedirect();

    expect(ProjectMilestone::find($this->milestone->id))->toBeNull()
        ->and($task->fresh()->milestone_id)->toBeNull();
});

// ── Independence from task progress (INV-P9) ─────────────────────────────────

it('NEW CONTRACT: completing and reopening a milestone moves, completes and reopens no linked task, and stops no timer', function () {
    $open = makeTask($this->project->columns[1], ['milestone_id' => $this->milestone->id, 'assignee_id' => $this->manager->id]);
    makeTask($this->project->columns[4], ['milestone_id' => $this->milestone->id, 'position' => 0]);
    $running = TimeEntry::factory()->create([
        'user_id' => $this->manager->id, 'task_id' => $open->id, 'duration_minutes' => 0,
        'timer_started_at' => now()->subHour(), 'stopped_at' => null,
    ]);
    $before = milestoneTaskFingerprint($this->milestone);

    Carbon::setTestNow('2026-06-16 12:00:00');
    $this->actingAs($this->manager)->put(route('projects.milestones.complete', [$this->project, $this->milestone]));
    expect(milestoneTaskFingerprint($this->milestone))->toBe($before)
        ->and($open->fresh()->isDone())->toBeFalse()
        ->and($running->fresh()->isRunning())->toBeTrue();

    $this->actingAs($this->manager)->put(route('projects.milestones.reopen', [$this->project, $this->milestone]));
    expect(milestoneTaskFingerprint($this->milestone))->toBe($before)
        ->and($running->fresh()->isRunning())->toBeTrue();
});

it('NEW CONTRACT: finishing every linked task does not complete the milestone, and reopening a task under a completed one leaves it complete', function () {
    $task = makeTask($this->project->columns[1], ['milestone_id' => $this->milestone->id]);

    $this->actingAs($this->admin)->put(route('tasks.complete', $task))->assertRedirect();
    expect($task->fresh()->isDone())->toBeTrue()
        ->and($this->milestone->fresh()->completed_at)->toBeNull()
        ->and($this->milestone->fresh()->completionPercentage())->toBe(100);

    app(ProjectMilestoneService::class)->complete($this->milestone, $this->manager);
    $this->actingAs($this->admin)->put(route('tasks.reopen', $task))->assertRedirect();

    expect($task->fresh()->isDone())->toBeFalse()
        ->and($this->milestone->fresh()->completed_by)->toBe($this->manager->id);

    $item = collect($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->viewData('page')['props']['milestones'])->firstWhere('id', $this->milestone->id);
    expect($item)->toMatchArray(['openTaskCount' => 1, 'taskCount' => 1, 'doneCount' => 0, 'completion' => 0])
        ->and($item['completedAt'])->not->toBeNull();
});

// ── Overdue (Q2) ─────────────────────────────────────────────────────────────

it('FLIPPED IN PR B: overdue is due before today and not completed, whatever the task progress', function () {
    // Before: overdue was `due < today AND task completion < 100`, so a milestone with every task
    // done was never overdue and a zero-task milestone was overdue forever.
    $allDone = $this->project->milestones()->create(['name' => 'All tasks done', 'due_date' => '2026-06-01']);
    makeTask($this->project->columns[4], ['milestone_id' => $allDone->id]);
    $zeroTask = $this->project->milestones()->create(['name' => 'Zero tasks', 'due_date' => '2026-06-01']);
    $completedLate = $this->project->milestones()->create(['name' => 'Completed late', 'due_date' => '2026-06-01']);
    makeTask($this->project->columns[1], ['milestone_id' => $completedLate->id]);
    app(ProjectMilestoneService::class)->complete($completedLate, $this->manager);
    $dueToday = $this->project->milestones()->create(['name' => 'Due today', 'due_date' => '2026-06-15']);

    $byName = collect($this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->viewData('page')['props']['milestones'])->keyBy('name');

    expect($byName['All tasks done']['overdue'])->toBeTrue()
        ->and($byName['Zero tasks']['overdue'])->toBeTrue()
        ->and($byName['Completed late']['overdue'])->toBeFalse()
        ->and($byName['Due today']['overdue'])->toBeFalse()
        ->and($byName['Go-live']['overdue'])->toBeFalse();

    // The SQL scope and the per-row rule agree.
    expect(ProjectMilestone::where('project_id', $this->project->id)->overdue()->orderBy('id')->pluck('id')->all())
        ->toBe(ProjectMilestone::where('project_id', $this->project->id)->orderBy('id')->get()
            ->filter->isOverdue()->pluck('id')->values()->all());
});

// ── DTO (§9.4) ───────────────────────────────────────────────────────────────

it('NEW CONTRACT: the milestone item carries completedAt, completedBy {id, name}, openTaskCount and the recomputed overdue', function () {
    makeTask($this->project->columns[1], ['milestone_id' => $this->milestone->id]);
    makeTask($this->project->columns[4], ['milestone_id' => $this->milestone->id, 'position' => 1]);
    $completer = tap(makeUser('user', ['name' => 'Cleo Completer', 'email' => 'cleo@example.test']))->givePermissionTo('projects.manage');
    $this->project->members()->attach($completer->id, ['role' => 'manager']);
    $this->actingAs($completer)->put(route('projects.milestones.complete', [$this->project, $this->milestone]));

    $item = $this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->viewData('page')['props']['milestones'][0];

    expect($item)->toMatchArray([
        'taskCount' => 2, 'doneCount' => 1, 'openTaskCount' => 1, 'completion' => 50,
        'completedAt' => Carbon::parse('2026-06-15 12:00:00')->toIso8601String(),
        'completedBy' => ['id' => $completer->id, 'name' => 'Cleo Completer'],
        'overdue' => false,
    ])->and(json_encode($item))->not->toContain('cleo@example.test');
});

it('NEW CONTRACT: a customer member deliberately receives completedBy (provenance) and no email', function () {
    $customer = projectActor('member', $this->project);
    $completer = makeUser('operator', ['name' => 'Pat Provenance', 'email' => 'pat@example.test']);
    app(ProjectMilestoneService::class)->complete($this->milestone, $completer);

    $response = $this->actingAs($customer)->get(route('projects.milestones.index', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('abilities.manage', false)
            ->where('milestones.0.completedBy', ['id' => $completer->id, 'name' => 'Pat Provenance']));

    expect(json_encode($response->viewData('page')['props']))->not->toContain('pat@example.test');
});

it('NEW CONTRACT: completedBy is null for an open milestone and after the completer is deleted', function () {
    $item = fn () => $this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->viewData('page')['props']['milestones'][0];

    expect($item()['completedBy'])->toBeNull()->and($item()['completedAt'])->toBeNull();

    $gone = makeUser('operator');
    app(ProjectMilestoneService::class)->complete($this->milestone, $gone);
    $gone->delete();

    expect($item()['completedBy'])->toBeNull()->and($item()['completedAt'])->not->toBeNull();
});

it('NEW CONTRACT: milestone task progress excludes the malformed dual-linked task (PR-A ruling)', function () {
    makeTask($this->project->columns[1], ['milestone_id' => $this->milestone->id]);
    $dual = makeTask($this->project->columns[4], ['milestone_id' => $this->milestone->id, 'position' => 1]);
    DB::table('tasks')->where('id', $dual->id)->update(['ticket_id' => Ticket::factory()->create()->id]);
    expect(Task::find($dual->id)->isMalformedKind())->toBeTrue();

    $item = $this->actingAs($this->admin)->get(route('projects.milestones.index', $this->project))
        ->viewData('page')['props']['milestones'][0];

    expect($item)->toMatchArray(['taskCount' => 1, 'doneCount' => 0, 'openTaskCount' => 1, 'completion' => 0]);
});

it('NEW CONTRACT: the service is the only writer: completion fields are not mass assignable', function () {
    $milestone = $this->project->milestones()->create([
        'name' => 'Sneaky', 'due_date' => '2026-07-01', 'completed_at' => now(), 'completed_by' => $this->admin->id,
    ]);

    expect($milestone->fresh())->completed_at->toBeNull()->completed_by->toBeNull();
    expect((new ProjectMilestone)->getFillable())->not->toContain('completed_at')
        ->and((new ProjectMilestone)->getFillable())->not->toContain('completed_by');
});
