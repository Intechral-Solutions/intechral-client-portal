<?php

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Queries\TaskQuery;
use App\Rules\AccessibleTimeContext;
use App\Services\ProjectIntegrityAudit;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR A, §8.4 and the owner ruling that closed it: a task linked to BOTH a project and
 * a ticket (`project_id != NULL AND ticket_id != NULL`) is MALFORMED and has NO valid product kind.
 * It is neither a project task nor a ticket task: not "project wins", not "ticket wins". No
 * application path writes it (EPIC-014 INV-13); only a direct write can. It is refused and
 * unsurfaced, never auto-deleted or auto-migrated, and the integrity audit keeps flagging it.
 *
 * Written first as OBSERVED characterization against `58c58f1`, where the authorities disagreed.
 * After the ruling the disagreeing ones were FLIPPED IN WP1 in place (each keeps what it asserted
 * before); the ones that already refused the row are PRESERVED:
 *
 *   PRESERVED      — TaskPolicy, TaskQuery, the integrity audit.
 *   FLIPPED IN WP1 — the model classifier, the project aggregates, the board, the project-task
 *                    routes, new-time eligibility, project time attribution.
 *
 * Historical safety is unchanged and pinned here: an unchanged historical attribution stays
 * editable (EPIC-014 decision (c)), a running timer can be stopped, a billed entry stays locked.
 *
 * Fixture: project P with an open column; a ticket T owned by `$this->ticketOwner` (not a project
 * member); the dual-linked task is assigned to `$this->member` (a project member) and overdue.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->open = $this->project->columns()->where('is_done_column', false)->orderBy('position')->first();
    $this->done = $this->project->columns()->where('is_done_column', true)->first();
    $this->member = projectActor('member', $this->project);
    $this->manager = projectActor('project_manager', $this->project);
    $this->ticketOwner = makeUser();
    $this->ticket = Ticket::factory()->create(['user_id' => $this->ticketOwner->id]);

    $this->normal = makeTask($this->open, ['title' => 'Normal board task']);
    $this->dual = makeTask($this->open, [
        'title' => 'Dual linked', 'ticket_id' => $this->ticket->id, 'assignee_id' => $this->member->id,
        'due_date' => today()->subDay(),
    ]);
});

// ── The classifier and the scope ──────────────────────────────────────────────

it('FLIPPED IN WP1: the model names the malformed kind, and a valid-kind scope excludes exactly that shape', function () {
    $standalone = Task::factory()->standalone()->create();
    $ticketTask = Task::factory()->standalone()->create(['ticket_id' => $this->ticket->id]);

    expect($this->dual->isMalformedKind())->toBeTrue()
        ->and($this->normal->isMalformedKind())->toBeFalse()
        ->and($standalone->isMalformedKind())->toBeFalse()
        ->and($ticketTask->isMalformedKind())->toBeFalse();

    $valid = Task::ofValidKind()->pluck('id')->all();
    expect($valid)->toContain($this->normal->id, $standalone->id, $ticketTask->id)
        ->and(array_values(array_intersect($valid, [$this->dual->id])))->toBe([]);

    // Task::kind() is deliberately unchanged ("board" for this row); the classifier is the authority.
    expect($this->dual->kind())->toBe(Task::KIND_BOARD);
});

// ── Already refusing: preserved ───────────────────────────────────────────────

it('PRESERVED: TaskPolicy treats the row as malformed and accepts no ability for anyone', function () {
    foreach (['view', 'update', 'complete', 'reopen', 'delete', 'assign', 'move'] as $ability) {
        expect(Gate::forUser($this->member)->allows($ability, $this->dual))->toBeFalse("member {$ability}")
            ->and(Gate::forUser(makeUser('operator'))->allows($ability, $this->dual))->toBeFalse("operator {$ability}")
            ->and(Gate::forUser($this->ticketOwner)->allows($ability, $this->dual))->toBeFalse("ticket owner {$ability}");
    }

    expect(Gate::forUser($this->member)->allows('view', $this->normal))->toBeTrue();
});

it('PRESERVED: the canonical task query excludes the row for everyone', function () {
    expect(TaskQuery::authorizedFor($this->member)->pluck('tasks.id')->all())->toContain($this->normal->id);

    foreach ([$this->member, $this->ticketOwner, $this->manager, makeUser('operator')] as $actor) {
        expect(array_values(array_intersect(TaskQuery::authorizedFor($actor)->pluck('tasks.id')->all(), [$this->dual->id])))->toBe([]);
    }
});

it('PRESERVED: the integrity audit flags the row, and the row is neither deleted nor migrated', function () {
    expect((new ProjectIntegrityAudit)->run()['tasks_linked_to_project_and_ticket'])->toBe(1)
        ->and($this->dual->fresh()->only(['project_id', 'ticket_id', 'assignee_id']))
        ->toBe(['project_id' => $this->project->id, 'ticket_id' => $this->ticket->id, 'assignee_id' => $this->member->id]);
});

// ── Aggregates ────────────────────────────────────────────────────────────────

it('FLIPPED IN WP1: project aggregates no longer count the row (total, done, overdue and progress)', function () {
    // Before: tasks_count 2, overdue 1 (the dual row counted as an open overdue project task).
    $stats = Project::withTaskStats()->findOrFail($this->project->id);

    expect((int) $stats->tasks_count)->toBe(1)
        ->and((int) $stats->done_tasks_count)->toBe(0)
        ->and((int) $stats->overdue_tasks_count)->toBe(0)
        ->and($stats->completionFromCounts())->toBe(0);

    // Before: the same row in the Done column counted as done progress (1 of 2 = 50%).
    $this->dual->update(['column_id' => $this->done->id]);
    $done = Project::withTaskStats()->findOrFail($this->project->id);
    expect((int) $done->tasks_count)->toBe(1)
        ->and((int) $done->done_tasks_count)->toBe(0)
        ->and($done->completionFromCounts())->toBe(0);

    // A valid done task still counts, so progress is real: 1 of 2 valid tasks.
    makeTask($this->done, ['title' => 'Valid done']);
    $mixed = Project::withTaskStats()->findOrFail($this->project->id);
    expect((int) $mixed->tasks_count)->toBe(2)->and((int) $mixed->done_tasks_count)->toBe(1)->and($mixed->completionFromCounts())->toBe(50);
});

it('FLIPPED IN WP1: the per-project helpers agree with the aggregates (they cannot disagree on this row)', function () {
    $project = $this->project->fresh();

    expect($project->overdueTasks())->toBe(0)
        ->and($project->completionPercentage())->toBe(0)
        ->and(Project::withTaskStats()->findOrFail($project->id)->completionFromCounts())->toBe($project->completionPercentage());

    $this->dual->update(['column_id' => $this->done->id]);
    expect($project->fresh()->completionPercentage())->toBe(0);
});

it('FLIPPED IN WP1: milestone task counts and completion exclude the row', function () {
    $milestone = ProjectMilestone::create(['project_id' => $this->project->id, 'name' => 'M1', 'due_date' => today()->addWeek()]);
    $this->normal->update(['milestone_id' => $milestone->id]);
    $this->dual->update(['milestone_id' => $milestone->id, 'column_id' => $this->done->id]);

    $counted = ProjectMilestone::withTaskCounts()->findOrFail($milestone->id);

    expect((int) $counted->tasks_count)->toBe(1)
        ->and((int) $counted->done_tasks_count)->toBe(0)
        ->and($counted->completionFromCounts())->toBe(0)
        ->and($milestone->fresh()->completionPercentage())->toBe(0);
});

// ── Board ─────────────────────────────────────────────────────────────────────

it('FLIPPED IN WP1: the board no longer renders the row, and the row is not deleted', function () {
    $columns = $this->actingAs($this->member)->get(route('projects.board', $this->project))->assertOk()
        ->viewData('page')['props']['columns'];

    $rendered = collect($columns)->flatMap(fn ($c) => collect($c['tasks'])->pluck('title'))->all();

    expect($rendered)->toContain('Normal board task')->not->toContain('Dual linked')
        ->and(Task::whereKey($this->dual->id)->exists())->toBeTrue();
});

// ── Direct project-task routes (F2) ───────────────────────────────────────────

it('FLIPPED IN WP1 (F2): every project-task route answers 404 for the malformed row, even for a manager', function () {
    $item = $this->dual->checklistItems()->create(['title' => 'x', 'completed' => false, 'position' => 0]);
    $p = $this->project;
    $t = $this->dual;

    $calls = [
        'show' => fn () => $this->get(route('projects.tasks.show', [$p, $t])),
        'update' => fn () => $this->put(route('projects.tasks.update', [$p, $t]), ['title' => 'Edited', 'priority' => 'low']),
        'destroy' => fn () => $this->delete(route('projects.tasks.destroy', [$p, $t])),
        'move' => fn () => $this->put(route('projects.tasks.move', [$p, $t]), ['column_id' => $this->done->id, 'position' => 0]),
        'comment' => fn () => $this->post(route('projects.tasks.comments.store', [$p, $t]), ['body' => 'hi']),
        'checklist store' => fn () => $this->post(route('projects.tasks.checklist.store', [$p, $t]), ['title' => 'y']),
        'checklist toggle' => fn () => $this->put(route('projects.tasks.checklist.toggle', [$p, $t, $item->id]), ['completed' => true]),
        'checklist destroy' => fn () => $this->delete(route('projects.tasks.checklist.destroy', [$p, $t, $item->id])),
    ];

    $before = $t->fresh()->toArray();

    foreach ($calls as $name => $call) {
        foreach ([$this->manager, makeUser('operator')] as $actor) {
            $this->actingAs($actor);
            $call()->assertNotFound("{$name} as {$actor->id}");
        }
    }

    // Untouched: not edited, not moved, not deleted, no comment, checklist intact.
    expect($t->fresh()->toArray())->toBe($before)
        ->and($t->comments()->count())->toBe(0)
        ->and($t->checklistItems()->count())->toBe(1)
        ->and($item->fresh()->completed)->toBeFalse();
});

it('PRESERVED (F2): a valid project task still works on the same routes, under the existing authorization', function () {
    $item = $this->normal->checklistItems()->create(['title' => 'x', 'completed' => false, 'position' => 0]);

    $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $this->normal]))->assertOk();
    $this->actingAs($this->manager)->put(route('projects.tasks.update', [$this->project, $this->normal]), ['title' => 'Renamed', 'priority' => 'low'])->assertSessionHasNoErrors();
    $this->actingAs($this->member)->post(route('projects.tasks.comments.store', [$this->project, $this->normal]), ['body' => 'hi'])->assertSessionHasNoErrors();
    $this->actingAs($this->member)->put(route('projects.tasks.checklist.toggle', [$this->project, $this->normal, $item->id]), ['completed' => true])->assertSessionHasNoErrors();

    // A member still cannot use a structural route (403, not 404): the order of checks is unchanged.
    $this->actingAs($this->member)->put(route('projects.tasks.update', [$this->project, $this->normal]), ['title' => 'No', 'priority' => 'low'])->assertForbidden();

    // And a task of ANOTHER project is still 404 on this project's route.
    $other = makeProject(null, 'Other');
    $foreign = makeTask($other->columns[0]);
    $this->actingAs($this->manager)->get(route('projects.tasks.show', [$this->project, $foreign]))->assertNotFound();

    expect($this->normal->fresh()->title)->toBe('Renamed')->and($item->fresh()->completed)->toBeTrue();
});

// ── New-time eligibility ──────────────────────────────────────────────────────

it('FLIPPED IN WP1: the row is denied as a NEW time context for everyone — member, departed assignee, ticket owner, operator', function () {
    // Before: admitted for a member (project view), for the stored assignee after leaving
    // (assignee shortcut) and for a ticket owner (ticket-view fallback).
    $operator = makeUser('operator');

    foreach ([$this->member, $this->ticketOwner, $operator, $this->manager] as $actor) {
        expect(AccessibleTimeContext::allows($actor, 'task', $this->dual->id))->toBeFalse("actor {$actor->id}");
    }

    $this->project->members()->detach($this->member->id);
    expect(AccessibleTimeContext::allows($this->member->fresh(), 'task', $this->dual->id))->toBeFalse();

    // Through the real routes: a timer and manual time are refused, and the picker never offers it.
    $this->actingAs($this->ticketOwner)->postJson(route('time.timer.start'), ['task_id' => $this->dual->id])->assertUnprocessable();
    $this->actingAs($operator)->post(route('time.store'), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $this->dual->id,
    ])->assertSessionHasErrors('task_id');

    $labels = collect($this->actingAs($this->member->fresh())->getJson(route('time.context.options', ['type' => 'task']))->json())->pluck('label')->all();
    expect($labels)->not->toContain('Dual linked');
});

it('PRESERVED: ordinary board, standalone and ticket tasks are untouched by the malformed check', function () {
    $standalone = Task::factory()->standalone()->create(['assignee_id' => $this->ticketOwner->id, 'status' => 'todo']);
    $ticketTask = Task::factory()->standalone()->create(['ticket_id' => $this->ticket->id, 'assignee_id' => null, 'status' => 'todo']);

    expect(AccessibleTimeContext::allows($this->member, 'task', $this->normal->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows($this->ticketOwner, 'task', $standalone->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows($this->ticketOwner, 'task', $ticketTask->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows(makeUser(), 'task', $ticketTask->id))->toBeFalse();
});

// ── Historical safety, unchanged ──────────────────────────────────────────────

it('PRESERVED (decision (c)) and FLIPPED IN WP1: an existing entry keeps its UNCHANGED malformed task and stays editable, but nothing can be moved ONTO it', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->member->id, 'task_id' => $this->dual->id, 'date' => today()->toDateString(), 'duration_minutes' => 30]);

    $this->actingAs($this->member)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'description' => 'Edited', 'task_id' => $this->dual->id,
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes']))->toBe(['task_id' => $this->dual->id, 'duration_minutes' => 120]);

    // But it cannot be MOVED onto the malformed task from elsewhere (a new attribution).
    $elsewhere = TimeEntry::factory()->create(['user_id' => $this->member->id, 'task_id' => $this->normal->id, 'date' => today()->toDateString()]);
    $this->actingAs($this->member)->put(route('time.update', $elsewhere), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $this->dual->id,
    ])->assertSessionHasErrors('task_id');
});

it('PRESERVED: a running timer on the malformed task can still be stopped, and a billed entry stays locked', function () {
    $running = TimeEntry::factory()->running()->create(['user_id' => $this->member->id, 'task_id' => $this->dual->id]);
    $this->actingAs($this->member)->postJson(route('time.timer.stop', $running))->assertOk();
    expect($running->fresh()->isRunning())->toBeFalse();

    $billed = TimeEntry::factory()->billed()->create(['user_id' => $this->member->id, 'task_id' => $this->dual->id, 'date' => today()->toDateString()]);
    $before = $billed->fresh()->toArray();
    $this->actingAs($this->member)->put(route('time.update', $billed), [
        'date' => today()->toDateString(), 'hours' => 3, 'task_id' => $this->dual->id,
    ])->assertSessionHasErrors('time_entry');
    expect($billed->fresh()->toArray())->toBe($before);
});

// ── Cross-surface consistency ─────────────────────────────────────────────────

it('FLIPPED IN WP1: the contradiction is gone — the board, the project count and the global Tasks list all show one task', function () {
    $this->member->givePermissionTo('tasks.view_all');

    $this->actingAs($this->member)->get(route('tasks.index', ['view' => 'all', 'completion' => 'any']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('tasks.total', 1));

    $boardCount = collect($this->actingAs($this->member)->get(route('projects.board', $this->project))->viewData('page')['props']['columns'])
        ->sum(fn ($c) => count($c['tasks']));

    expect($boardCount)->toBe(1)
        ->and((int) Project::withTaskStats()->findOrFail($this->project->id)->tasks_count)->toBe(1);
});
