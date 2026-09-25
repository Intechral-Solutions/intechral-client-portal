<?php

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Ticket;
use App\Services\ProjectService;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1, step 1: behavior characterized against the ORIGINAL backend, before any
 * production change, that WP1 deliberately leaves alone. Alongside these, the original suite
 * recorded the behavior WP1 changes on purpose (member task mutation, foreign ids, position
 * numbering, time-history nulling, ...); those assertions were flipped in place by the fixes
 * and now live, as the new contract, in the sibling Project*Test files.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->columns = $this->project->columns;
    $this->todo = $this->columns[1];
    $this->done = $this->columns[4];
});

// ── Authorization ────────────────────────────────────────────────────────────

it('PINNED: outsiders and non-member assignees are refused on structural task routes', function (string $actor) {
    $task = makeTask($this->todo);
    $user = projectActor($actor, $this->project, $task);

    $this->actingAs($user)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->todo->id, 'title' => 'x', 'priority' => 'low',
    ])->assertForbidden();
    $this->actingAs($user)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'x', 'priority' => 'low',
    ])->assertForbidden();
    $this->actingAs($user)->putJson(route('projects.tasks.move', [$this->project, $task]), [
        'column_id' => $this->done->id, 'position' => 0,
    ])->assertForbidden();
    $this->actingAs($user)->delete(route('projects.tasks.destroy', [$this->project, $task]))->assertForbidden();
    $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $task]))->assertForbidden();
})->with(['outsider', 'assignee']);

it('PINNED (A9): projects.admin without projects.manage is stopped by route middleware on project management pages', function () {
    $admin = projectActor('admin_only', $this->project);

    $this->actingAs($admin)->get(route('projects.create'))->assertForbidden();
    $this->actingAs($admin)->post(route('projects.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($admin)->get(route('projects.edit', $this->project))->assertForbidden();
    $this->actingAs($admin)->put(route('projects.update', $this->project), ['name' => 'x', 'status' => 'active'])->assertForbidden();
    $this->actingAs($admin)->delete(route('projects.destroy', $this->project))->assertForbidden();
    $this->actingAs($admin)->put(route('projects.companies.sync', $this->project), ['companies' => []])->assertForbidden();
    $this->actingAs($admin)->post(route('projects.milestones.store', $this->project), ['name' => 'm', 'due_date' => '2030-01-01'])->assertForbidden();

    // ...while the policy-only routes admit them.
    $this->actingAs($admin)->get(route('projects.board', $this->project))->assertOk();
    $this->actingAs($admin)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->todo->id, 'title' => 'By admin only', 'priority' => 'low',
    ])->assertRedirect();
});

it('PINNED: members, managers and admins may comment and toggle checklist items; outsiders and assignees may not', function () {
    $task = makeTask($this->todo);
    $item = TaskChecklistItem::factory()->create(['task_id' => $task->id]);

    foreach (['member', 'manager_role', 'project_manager', 'admin'] as $actor) {
        $user = projectActor($actor, $this->project);
        $this->actingAs($user)->post(route('projects.tasks.comments.store', [$this->project, $task]), ['body' => "hi {$actor}"])->assertRedirect();
        // Redirect-back, not JSON (WP7): the React panel reconciles against the authoritative
        // `checklist` prop via a partial reload, the same contract the board's move uses.
        $this->actingAs($user)->putJson(route('projects.tasks.checklist.toggle', [$this->project, $task, $item->id]))->assertRedirect();
    }

    foreach (['outsider', 'assignee'] as $actor) {
        $user = projectActor($actor, $this->project, $task);
        $this->actingAs($user)->post(route('projects.tasks.comments.store', [$this->project, $task]), ['body' => 'no'])->assertForbidden();
        $this->actingAs($user)->putJson(route('projects.tasks.checklist.toggle', [$this->project, $task, $item->id]))->assertForbidden();
    }
});

// ── Status contract (§15) and standalone tasks (D3) ──────────────────────────

it('PINNED: done/effectiveStatus per task kind and null-column edge', function () {
    $inOpen = makeTask($this->todo);
    $inDone = makeTask($this->done);
    $standaloneDone = Task::factory()->standalone()->create(['status' => 'done']);
    $standaloneProgress = Task::factory()->standalone()->create(['status' => 'in_progress']);
    $ticketTask = Task::factory()->standalone()->create([
        'ticket_id' => Ticket::factory()->create()->id, 'status' => 'done',
    ]);

    expect($inOpen->isDone())->toBeFalse()
        ->and($inOpen->effectiveStatus())->toBe('To Do')
        ->and($inDone->isDone())->toBeTrue()
        ->and($inDone->effectiveStatus())->toBe('Done')
        ->and($standaloneDone->isDone())->toBeTrue()
        ->and($standaloneDone->effectiveStatus())->toBe('Done')
        ->and($standaloneProgress->isDone())->toBeFalse()
        ->and($standaloneProgress->effectiveStatus())->toBe('In Progress')
        ->and($ticketTask->isDone())->toBeTrue();

    // Null-column edge: the FK is SET NULL when a column row disappears, so the task falls back
    // to the raw status. It appears on no board (§15 rule 5).
    $orphan = makeTask($this->todo, ['status' => 'in_progress']);
    $this->todo->delete();
    $orphan->refresh();
    expect($orphan->column_id)->toBeNull()
        ->and($orphan->isDone())->toBeFalse()
        ->and($orphan->effectiveStatus())->toBe('In Progress');
});

it('PINNED: a board move never touches tasks.status', function () {
    $task = makeTask($this->todo, ['status' => 'todo']);

    app(ProjectService::class)->moveTask($task, $this->done->id, 0);

    expect($task->fresh()->status)->toBe('todo')
        ->and($task->fresh()->isDone())->toBeTrue();
});

it('PINNED (D3): standalone tasks have no route beyond tasks.index and tasks.store', function () {
    $names = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'tasks'))
        ->map(fn ($route) => $route->getName())
        ->sort()->values()->all();

    expect($names)->toBe(['tasks.index', 'tasks.store']);
});
