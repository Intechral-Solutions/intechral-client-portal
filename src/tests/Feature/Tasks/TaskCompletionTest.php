<?php

use App\Exceptions\DoneColumnConfigurationException;
use App\Exceptions\UnsupportedTaskOperationException;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §8, Q1, Q2, P3, P4 (WP2): explicit Complete / Reopen.
 *
 * Board: Complete appends the task to the tail of the project's single Done column through
 * ProjectService; Reopen appends it to the tail of the first non-Done column by position (ties by
 * id). Both are no-op successes when the task is already in the requested state, decided under
 * ProjectService's own column and task locks. `tasks.status` is never written for a board task
 * (INV-2). Zero or several Done columns (and Reopen with no open column) fail with a
 * `complete`/`reopen` validation error, never a guess or a 500 (INV-8).
 * Standalone: `status` is the authority: Complete sets done, Reopen sets todo (only when done).
 * Ticket-kind and malformed rows are refused; no time entry is ever touched (INV-15).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->service = app(TaskService::class);
    $this->project = makeProject();
    $this->columns = $this->project->columns()->orderBy('position')->get();
    [$this->backlog, $this->todo, $this->progress, $this->review, $this->done] = $this->columns->all();
    $this->manager = projectActor('project_manager', $this->project);
});

/** A task and its column/position/status, for before/after comparison. */
function placement(Task $task): array
{
    $fresh = $task->fresh();

    return [...$fresh->only(['column_id', 'position', 'status']), 'updated_at' => $fresh->updated_at->toDateTimeString()];
}

// ── Board Complete ───────────────────────────────────────────────────────────

it('completes a board task by appending it to the tail of the Done column, leaving status alone', function () {
    $done = [makeTask($this->done, ['position' => 0]), makeTask($this->done, ['position' => 1])];
    [$a, $task, $c] = [makeTask($this->todo, ['position' => 0]), makeTask($this->todo, ['position' => 1, 'status' => 'in_progress']), makeTask($this->todo, ['position' => 2])];

    $this->service->complete($task);

    expect($task->fresh()->column_id)->toBe($this->done->id)
        ->and($task->fresh()->isDone())->toBeTrue()
        ->and($task->fresh()->status)->toBe('in_progress')                       // INV-2
        ->and(columnOrder($this->done))->toBe([$done[0]->id, $done[1]->id, $task->id]) // P3: tail
        ->and(columnOrder($this->todo))->toBe([$a->id, $c->id])
        ->and(columnIsDense($this->done))->toBeTrue()
        ->and(columnIsDense($this->todo))->toBeTrue();                          // INV-3
});

it('finds the Done column by its flag, never by name or position', function () {
    $this->done->update(['is_done_column' => false]);
    $this->review->update(['is_done_column' => true]);
    $task = makeTask($this->todo);

    $this->service->complete($task);

    expect($task->fresh()->column_id)->toBe($this->review->id);
});

it('treats Complete of a task already in the Done column as a no-op: no move, no reorder', function () {
    [$first, $task, $last] = [makeTask($this->done, ['position' => 0]), makeTask($this->done, ['position' => 1]), makeTask($this->done, ['position' => 2])];
    $before = placement($task);

    $this->travel(5)->minutes();
    $this->service->complete($task);

    expect(placement($task))->toBe($before)
        ->and(columnOrder($this->done))->toBe([$first->id, $task->id, $last->id]);
});

it('completes a board task that lost its column by placing it in the Done column (P4), not by writing status', function () {
    $task = makeTask($this->todo, ['status' => 'todo']);
    $task->update(['column_id' => null]);
    $existing = makeTask($this->done, ['position' => 0]);

    $this->service->complete($task);

    expect($task->fresh()->only(['column_id', 'position', 'status']))
        ->toBe(['column_id' => $this->done->id, 'position' => 1, 'status' => 'todo'])
        ->and(columnOrder($this->done))->toBe([$existing->id, $task->id]);
});

it('decides "already done" under the locks: a task moved into Done after the unlocked read is left where it landed', function () {
    // Simulates another request dropping the task at the head of Done between ProjectService's
    // unlocked column read and its locks. Deciding "not done yet" from that stale read would
    // append it to the tail, a spurious reorder; deciding under the locks sees it already done.
    [$x, $y] = [makeTask($this->done, ['position' => 1]), makeTask($this->done, ['position' => 2])];
    $task = makeTask($this->todo, ['position' => 0]);
    $injected = false;
    DB::listen(function ($query) use ($task, &$injected) {
        if (! $injected && str_starts_with($query->sql, 'select `id`, `column_id` from `tasks`')) {
            $injected = true;
            DB::table('tasks')->where('id', $task->id)->update(['column_id' => $this->done->id, 'position' => 0]);
        }
    });

    $this->service->complete($task);

    expect($injected)->toBeTrue()
        ->and(columnOrder($this->done))->toBe([$task->id, $x->id, $y->id]);
});

// ── Board Reopen ─────────────────────────────────────────────────────────────

it('reopens a board task into the tail of the first non-Done column by position (Backlog by default)', function () {
    $waiting = makeTask($this->backlog, ['position' => 0]);
    [$task, $other] = [makeTask($this->done, ['position' => 0, 'status' => 'done']), makeTask($this->done, ['position' => 1])];

    $this->service->reopen($task);

    expect($task->fresh()->column_id)->toBe($this->backlog->id)
        ->and($task->fresh()->isDone())->toBeFalse()
        ->and($task->fresh()->status)->toBe('done')                             // INV-2, even when stale
        ->and(columnOrder($this->backlog))->toBe([$waiting->id, $task->id])
        ->and(columnOrder($this->done))->toBe([$other->id])
        ->and(columnIsDense($this->done))->toBeTrue();
});

it('picks the first open column by position with ties broken by id, whatever the names', function () {
    // Two open columns share position 0; the lower id wins. The Done flag sits on position 1.
    $this->backlog->update(['is_done_column' => false]);
    $this->done->update(['is_done_column' => false, 'position' => 0]);
    $this->todo->update(['is_done_column' => true]);
    $task = makeTask($this->todo);

    $this->service->reopen($task);

    expect($task->fresh()->column_id)->toBe(min($this->backlog->id, $this->done->id));
});

it('treats Reopen of an open board task as a no-op: it stays where it is', function () {
    [$a, $task] = [makeTask($this->progress, ['position' => 0]), makeTask($this->progress, ['position' => 1])];
    $before = placement($task);

    $this->travel(5)->minutes();
    $this->service->reopen($task);

    expect(placement($task))->toBe($before)
        ->and(columnOrder($this->progress))->toBe([$a->id, $task->id]);
});

it('reopens a legacy column-less board task only when its status says done (P4 edge)', function () {
    $doneOrphan = makeTask($this->todo, ['status' => 'done']);
    $openOrphan = makeTask($this->todo, ['status' => 'in_progress']);
    Task::whereKey([$doneOrphan->id, $openOrphan->id])->update(['column_id' => null]);

    $this->service->reopen($doneOrphan);
    $this->service->reopen($openOrphan);

    expect($doneOrphan->fresh()->only(['column_id', 'status']))->toBe(['column_id' => $this->backlog->id, 'status' => 'done'])
        ->and($doneOrphan->fresh()->isDone())->toBeFalse()
        ->and($openOrphan->fresh()->only(['column_id', 'status']))->toBe(['column_id' => null, 'status' => 'in_progress']);
});

// ── Broken Done configuration (INV-8) ────────────────────────────────────────

it('refuses Complete and Reopen with no Done column, changing nothing', function (string $operation) {
    $this->project->columns()->update(['is_done_column' => false]);
    $task = makeTask($this->todo);
    $before = placement($task);

    expect(fn () => $this->service->{$operation}($task))->toThrow(DoneColumnConfigurationException::class);
    expect(placement($task))->toBe($before);
})->with(['complete', 'reopen']);

it('refuses Complete and Reopen with several Done columns, changing nothing', function (string $operation) {
    $this->review->update(['is_done_column' => true]);
    $task = makeTask($this->done);
    $before = placement($task);

    expect(fn () => $this->service->{$operation}($task))->toThrow(DoneColumnConfigurationException::class);
    expect(placement($task))->toBe($before);
})->with(['complete', 'reopen']);

it('refuses Reopen when every column is a Done column (no open destination)', function () {
    $this->project->columns()->where('id', '!=', $this->done->id)->delete();
    $task = makeTask($this->done);

    try {
        $this->service->reopen($task);
        $this->fail('Expected a configuration error.');
    } catch (DoneColumnConfigurationException $e) {
        expect($e->getMessage())->toContain('no open column');
    }
    expect($task->fresh()->column_id)->toBe($this->done->id);
});

it('answers the routes with a validation error on the operation key, never a 500 or a guess', function (string $route, string $key, string $phrase) {
    $this->project->columns()->update(['is_done_column' => false]);
    $task = makeTask($this->todo);

    $this->actingAs($this->manager)->from('/tasks')->put(route($route, $task))
        ->assertRedirect('/tasks')
        ->assertSessionHasErrors([$key => "This project's board has no single Done column, so tasks cannot be {$phrase} from here. A project administrator needs to correct the board."]);

    expect($task->fresh()->column_id)->toBe($this->todo->id);
})->with([
    'complete' => ['tasks.complete', 'complete', 'completed'],
    'reopen' => ['tasks.reopen', 'reopen', 'reopened'],
]);

// ── Standalone ───────────────────────────────────────────────────────────────

it('completes a standalone task by status and reopens it to todo', function (string $from) {
    $task = Task::factory()->standalone()->create(['status' => $from]);

    $this->service->complete($task);
    expect($task->fresh()->only(['status', 'project_id', 'column_id']))->toBe(['status' => 'done', 'project_id' => null, 'column_id' => null]);

    $this->service->reopen($task);
    expect($task->fresh()->status)->toBe('todo');
})->with(['todo', 'in_progress']);

it('is idempotent on standalone tasks without churning the row', function () {
    $done = Task::factory()->standalone()->create(['status' => 'done']);
    $open = Task::factory()->standalone()->create(['status' => 'in_progress']);
    [$doneBefore, $openBefore] = [$done->fresh()->toArray(), $open->fresh()->toArray()];

    $this->travel(5)->minutes();
    $this->service->complete($done);
    $this->service->reopen($open);   // not done: Reopen does not demote in_progress to todo

    expect($done->fresh()->toArray())->toBe($doneBefore)
        ->and($open->fresh()->toArray())->toBe($openBefore);
});

// ── Ticket-kind and malformed rows ───────────────────────────────────────────

it('refuses ticket-kind and dual-linked rows before changing anything', function (string $operation) {
    $ticketTask = Task::factory()->standalone()->create(['ticket_id' => Ticket::factory()->create()->id, 'status' => 'todo']);
    $dual = makeTask($this->todo, ['ticket_id' => Ticket::factory()->create()->id]);
    [$ticketBefore, $dualBefore] = [$ticketTask->fresh()->toArray(), $dual->fresh()->toArray()];

    expect(fn () => $this->service->{$operation}($ticketTask))->toThrow(UnsupportedTaskOperationException::class)
        ->and(fn () => $this->service->{$operation}($dual))->toThrow(UnsupportedTaskOperationException::class);

    expect($ticketTask->fresh()->toArray())->toBe($ticketBefore)
        ->and($dual->fresh()->toArray())->toBe($dualBefore);
})->with(['complete', 'reopen']);

it('decides the kind from the stored row, not from a stale caller model', function () {
    // A caller holding an out-of-date model (here: one that never saw the ticket link) must not be
    // able to talk the service into mutating a row that is not board or standalone.
    $task = Task::factory()->standalone()->create(['status' => 'todo']);
    $stale = Task::find($task->id);
    Task::whereKey($task->id)->update(['ticket_id' => Ticket::factory()->create()->id]);

    expect(fn () => $this->service->complete($stale))->toThrow(UnsupportedTaskOperationException::class);
    expect($task->fresh()->status)->toBe('todo');
});

// ── INV-15: time entries are never touched ───────────────────────────────────

it('leaves every time entry byte-identical, running timers included (INV-15)', function () {
    $worker = projectActor('member', $this->project);
    $board = makeTask($this->todo, ['assignee_id' => $worker->id]);
    $standalone = Task::factory()->standalone()->create(['assignee_id' => $worker->id]);
    foreach ([$board, $standalone] as $task) {
        TimeEntry::factory()->running()->create(['user_id' => $worker->id, 'task_id' => $task->id]);
        TimeEntry::factory()->create(['user_id' => $worker->id, 'task_id' => $task->id]);
        TimeEntry::factory()->billed()->create(['user_id' => $worker->id, 'task_id' => $task->id]);
    }
    $snapshot = fn () => DB::table('time_entries')->orderBy('id')->get()->toJson();
    $before = $snapshot();

    foreach ([$board, $standalone] as $task) {
        $this->service->complete($task);
        $this->service->reopen($task);
    }

    expect($snapshot())->toBe($before);
});

// ── Routes and authorization (Q1) ────────────────────────────────────────────

it('lets a manager and a member-assignee Complete and Reopen a board task through the routes', function (string $actor) {
    $task = makeTask($this->todo);
    $user = match ($actor) {
        'manager' => $this->manager,
        'member_assignee' => tap(projectActor('member', $this->project), fn (User $u) => $task->update(['assignee_id' => $u->id])),
        'operator' => makeUser('operator'),
    };

    $this->actingAs($user)->from('/tasks')->put(route('tasks.complete', $task))
        ->assertRedirect('/tasks')->assertSessionHasNoErrors()->assertSessionHas('success', 'Task completed.');
    expect($task->fresh()->column_id)->toBe($this->done->id);

    $this->actingAs($user)->from('/tasks')->put(route('tasks.reopen', $task))
        ->assertRedirect('/tasks')->assertSessionHas('success', 'Task reopened.');
    expect($task->fresh()->column_id)->toBe($this->backlog->id);
})->with(['manager', 'member_assignee', 'operator']);

it('denies Complete and Reopen to everyone else, changing nothing', function (string $actor) {
    $task = makeTask($this->todo);
    $user = match ($actor) {
        'outsider' => makeUser(),
        'plain_member' => projectActor('member', $this->project),
        'manager_pivot_only' => projectActor('manager_role', $this->project),
        'non_member_assignee' => projectActor('assignee', $this->project, $task),
        'departed_assignee' => tap(projectActor('member', $this->project), function (User $u) use ($task) {
            $task->update(['assignee_id' => $u->id]);
            $this->project->members()->detach($u->id);
        }),
    };
    $before = placement($task);

    $this->actingAs($user)->put(route('tasks.complete', $task))->assertForbidden();
    $this->actingAs($user)->put(route('tasks.reopen', $task))->assertForbidden();

    expect(placement($task))->toBe($before);
})->with(['outsider', 'plain_member', 'manager_pivot_only', 'non_member_assignee', 'departed_assignee']);

it('restores Complete to a rejoined current assignee', function () {
    $user = makeUser();
    $task = makeTask($this->todo, ['assignee_id' => $user->id]);
    $this->actingAs($user)->put(route('tasks.complete', $task))->assertForbidden();

    $this->project->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user)->put(route('tasks.complete', $task))->assertRedirect();
    expect($task->fresh()->column_id)->toBe($this->done->id);
});

it('never lets a member-assignee move, edit or assign the task through Complete rights', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $member->id]);

    $this->actingAs($member)->putJson(route('projects.tasks.move', [$this->project, $task]), ['column_id' => $this->progress->id, 'position' => 0])
        ->assertForbidden();
    $this->actingAs($member)->put(route('projects.tasks.update', [$this->project, $task]), ['title' => 'x', 'priority' => 'low'])
        ->assertForbidden();
    $this->actingAs($member)->put(route('tasks.assignee.update', $task), ['assignee_id' => null])
        ->assertForbidden();

    expect($task->fresh()->only(['column_id', 'title', 'assignee_id']))
        ->toBe(['column_id' => $this->todo->id, 'title' => $task->title, 'assignee_id' => $member->id]);
});

it('lets the standalone creator and current assignee Complete and Reopen, nobody else', function (string $actor, bool $allowed) {
    $creator = makeUser();
    $assignee = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $creator->id, 'assignee_id' => $assignee->id, 'status' => 'todo']);
    $user = match ($actor) {
        'creator' => $creator,
        'assignee' => $assignee,
        'stranger' => makeUser(),
        'operator' => makeUser('operator'),
    };

    $response = $this->actingAs($user)->put(route('tasks.complete', $task));

    if ($allowed) {
        $response->assertRedirect()->assertSessionHasNoErrors();
        expect($task->fresh()->status)->toBe('done');
        $this->actingAs($user)->put(route('tasks.reopen', $task))->assertRedirect();
        expect($task->fresh()->status)->toBe('todo');
    } else {
        $response->assertForbidden();
        $this->actingAs($user)->put(route('tasks.reopen', $task))->assertForbidden();
        expect($task->fresh()->status)->toBe('todo');
    }
})->with([
    'creator' => ['creator', true],
    'current assignee' => ['assignee', true],
    'stranger' => ['stranger', false],
    // Q3: holding every permission grants nothing over someone else's personal task.
    'operator' => ['operator', false],
]);

it('refuses ticket-kind and dual-linked rows on the routes with 403, even to their viewers', function (string $route) {
    $owner = makeUser();
    $ticketTask = Task::factory()->standalone()->create([
        'ticket_id' => Ticket::factory()->create(['user_id' => $owner->id])->id, 'created_by' => $owner->id, 'assignee_id' => $owner->id,
    ]);
    $dual = makeTask($this->todo, ['ticket_id' => Ticket::factory()->create()->id, 'assignee_id' => $this->manager->id]);

    $this->actingAs($owner)->put(route($route, $ticketTask))->assertForbidden();
    $this->actingAs(makeUser('operator'))->put(route($route, $ticketTask))->assertForbidden();
    $this->actingAs($this->manager)->put(route($route, $dual))->assertForbidden();
})->with(['tasks.complete', 'tasks.reopen']);

it('answers an unknown task id with 404 and a guest with the login redirect', function () {
    $this->put(route('tasks.complete', makeTask($this->todo)))->assertRedirect('/login');

    $this->actingAs($this->manager)->put(route('tasks.complete', 999999))->assertNotFound();
});
