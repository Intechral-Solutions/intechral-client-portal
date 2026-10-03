<?php

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Ticket;
use App\Services\ProjectService;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP1: server-side integrity of task writes (A1 to A3, A7), ordering (I1, I2),
 * the status contract (I3, I6, §15) and checklist authoring (D5).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->columns = $this->project->columns;
    $this->todo = $this->columns[1];
    $this->done = $this->columns[4];
    $this->manager = projectActor('project_manager', $this->project);
    $this->foreign = makeProject(null, 'Foreign');
    $this->foreignColumn = $this->foreign->columns[0];
    $this->foreignMilestone = $this->foreign->milestones()->create(['name' => 'Foreign MS', 'due_date' => '2030-01-01']);
    $this->ownMilestone = $this->project->milestones()->create(['name' => 'Own MS', 'due_date' => '2030-01-01']);
    $this->outsider = makeUser();
});

// ── Scoped ids on create (A1, A2, A3) ────────────────────────────────────────

it('rejects a foreign column, milestone or non-member assignee on task create', function (string $case) {
    [$override, $field] = match ($case) {
        'foreign column' => [['column_id' => $this->foreignColumn->id], 'column_id'],
        'unknown column' => [['column_id' => 999999], 'column_id'],
        'foreign milestone' => [['milestone_id' => $this->foreignMilestone->id], 'milestone_id'],
        'non-member assignee' => [['assignee_id' => $this->outsider->id], 'assignee_id'],
    };

    $this->actingAs($this->manager)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->todo->id, 'title' => 'Injected', 'priority' => 'low', ...$override,
    ])->assertSessionHasErrors($field);

    expect(Task::where('title', 'Injected')->exists())->toBeFalse();
})->with(['foreign column', 'unknown column', 'foreign milestone', 'non-member assignee']);

it('accepts an own column, own milestone and a project member as assignee', function () {
    $member = projectActor('member', $this->project);

    $this->actingAs($this->manager)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->todo->id, 'title' => 'Legit', 'priority' => 'low',
        'milestone_id' => $this->ownMilestone->id, 'assignee_id' => $member->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::where('title', 'Legit')->first();
    expect($task->project_id)->toBe($this->project->id)
        ->and($task->milestone_id)->toBe($this->ownMilestone->id)
        ->and($task->assignee_id)->toBe($member->id);
});

// ── Scoped ids on update (A2, A3, I8) ────────────────────────────────────────

it('rejects a foreign milestone or non-member assignee on task update', function (string $case) {
    $task = makeTask($this->todo, ['title' => 'Original']);
    [$override, $field] = match ($case) {
        'foreign milestone' => [['milestone_id' => $this->foreignMilestone->id], 'milestone_id'],
        'non-member assignee' => [['assignee_id' => $this->outsider->id], 'assignee_id'],
    };

    $this->actingAs($this->manager)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'Changed', 'priority' => 'high', ...$override,
    ])->assertSessionHasErrors($field);

    expect($task->fresh()->title)->toBe('Original');
})->with(['foreign milestone', 'non-member assignee']);

it('keeps an assignee who has since left the project when the task is saved unchanged (I8)', function () {
    $former = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $former->id]);
    $this->project->members()->detach($former->id);

    // Re-submitting the same assignee is not a new assignment.
    $this->actingAs($this->manager)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'Still assigned', 'priority' => 'low', 'assignee_id' => $former->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBe($former->id);

    // Handing it to a different non-member is.
    $this->actingAs($this->manager)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'Reassigned', 'priority' => 'low', 'assignee_id' => $this->outsider->id,
    ])->assertSessionHasErrors('assignee_id');
});

it('lets a manager clear the assignee and set an own milestone on update', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['assignee_id' => $member->id]);

    $this->actingAs($this->manager)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'Cleared', 'priority' => 'low', 'assignee_id' => null, 'milestone_id' => $this->ownMilestone->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($task->fresh()->assignee_id)->toBeNull()
        ->and($task->fresh()->milestone_id)->toBe($this->ownMilestone->id);
});

// ── Move validation (A1) ─────────────────────────────────────────────────────

it('rejects moving a task into a foreign or unknown column and shifts nothing', function (string $case) {
    $a = makeTask($this->todo, ['position' => 0]);
    $b = makeTask($this->todo, ['position' => 1]);
    $foreignTask = makeTask($this->foreignColumn, ['position' => 0]);
    $column = $case === 'foreign column' ? $this->foreignColumn->id : 999999;

    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $a]), [
        'column_id' => $column, 'position' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('column_id');

    expect($a->fresh()->column_id)->toBe($this->todo->id)
        ->and($foreignTask->fresh()->position)->toBe(0)
        ->and(columnOrder($this->todo))->toBe([$a->id, $b->id]);
})->with(['foreign column', 'unknown column']);

it('rejects a task and column from different projects at the service boundary', function () {
    $task = makeTask($this->todo);

    expect(fn () => app(ProjectService::class)->moveTask($task, $this->foreignColumn->id, 0))
        ->toThrow(ValidationException::class);
    expect($task->fresh()->column_id)->toBe($this->todo->id);
});

it('returns 404 for a task that belongs to another project or no longer exists', function () {
    $foreignTask = makeTask($this->foreignColumn);
    $gone = makeTask($this->todo);
    $goneId = $gone->id;
    $gone->delete();

    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $foreignTask]), [
        'column_id' => $this->done->id, 'position' => 0,
    ])->assertNotFound();
    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $goneId]), [
        'column_id' => $this->done->id, 'position' => 0,
    ])->assertNotFound();
});

// ── Ordering (I1, I2) ────────────────────────────────────────────────────────

it('appends created tasks densely from position 0', function () {
    foreach (['A', 'B', 'C'] as $title) {
        $this->actingAs($this->manager)->post(route('projects.tasks.store', $this->project), [
            'column_id' => $this->todo->id, 'title' => $title, 'priority' => 'low',
        ])->assertRedirect();
    }

    expect(array_values(columnPositions($this->todo)))->toBe([0, 1, 2])
        ->and(Task::whereIn('id', columnOrder($this->todo))->orderBy('position')->pluck('title')->all())->toBe(['A', 'B', 'C']);
});

it('creates at the true tail even when legacy positions have gaps or duplicates', function () {
    $a = makeTask($this->todo, ['position' => 5]);
    $b = makeTask($this->todo, ['position' => 5]);
    $c = makeTask($this->todo, ['position' => 9]);

    $this->actingAs($this->manager)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->todo->id, 'title' => 'New', 'priority' => 'low',
    ])->assertRedirect();

    $new = Task::where('title', 'New')->first();
    expect(columnOrder($this->todo))->toBe([$a->id, $b->id, $c->id, $new->id])
        ->and(columnIsDense($this->todo))->toBeTrue();
});

it('moves across columns and within a column with dense positions', function (string $scenario) {
    $service = app(ProjectService::class);
    $t = collect(range(0, 3))->map(fn ($i) => makeTask($this->todo, ['position' => $i, 'title' => "T{$i}"]));
    $d = collect(range(0, 1))->map(fn ($i) => makeTask($this->done, ['position' => $i, 'title' => "D{$i}"]));

    match ($scenario) {
        'cross to top' => $service->moveTask($t[1], $this->done->id, 0),
        'cross to middle' => $service->moveTask($t[1], $this->done->id, 1),
        'cross to end' => $service->moveTask($t[1], $this->done->id, 2),
        'cross clamped past the tail' => $service->moveTask($t[1], $this->done->id, 99),
        'within down' => $service->moveTask($t[0], $this->todo->id, 2),
        'within up' => $service->moveTask($t[3], $this->todo->id, 0),
        'within clamped' => $service->moveTask($t[0], $this->todo->id, 99),
    };

    expect(columnIsDense($this->todo))->toBeTrue()
        ->and(columnIsDense($this->done))->toBeTrue();

    $expected = match ($scenario) {
        'cross to top' => [[$t[0]->id, $t[2]->id, $t[3]->id], [$t[1]->id, $d[0]->id, $d[1]->id]],
        'cross to middle' => [[$t[0]->id, $t[2]->id, $t[3]->id], [$d[0]->id, $t[1]->id, $d[1]->id]],
        'cross to end', 'cross clamped past the tail' => [[$t[0]->id, $t[2]->id, $t[3]->id], [$d[0]->id, $d[1]->id, $t[1]->id]],
        'within down' => [[$t[1]->id, $t[2]->id, $t[0]->id, $t[3]->id], [$d[0]->id, $d[1]->id]],
        'within up' => [[$t[3]->id, $t[0]->id, $t[1]->id, $t[2]->id], [$d[0]->id, $d[1]->id]],
        'within clamped' => [[$t[1]->id, $t[2]->id, $t[3]->id, $t[0]->id], [$d[0]->id, $d[1]->id]],
    };
    expect(columnOrder($this->todo))->toBe($expected[0])
        ->and(columnOrder($this->done))->toBe($expected[1]);
})->with([
    'cross to top', 'cross to middle', 'cross to end', 'cross clamped past the tail',
    'within down', 'within up', 'within clamped',
]);

it('normalizes legacy gaps and duplicates on the first move', function () {
    $a = makeTask($this->todo, ['position' => 3]);
    $b = makeTask($this->todo, ['position' => 3]);
    $c = makeTask($this->todo, ['position' => 8]);
    $mover = makeTask($this->done, ['position' => 4]);

    app(ProjectService::class)->moveTask($mover, $this->todo->id, 1);

    expect(columnOrder($this->todo))->toBe([$a->id, $mover->id, $b->id, $c->id])
        ->and(columnIsDense($this->todo))->toBeTrue()
        ->and(columnIsDense($this->done))->toBeTrue();
});

it('treats a move to the current place as a no-op', function () {
    $a = makeTask($this->todo, ['position' => 0]);
    $b = makeTask($this->todo, ['position' => 1]);
    $stamp = $b->fresh()->updated_at;

    $this->travel(5)->minutes();
    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $b]), [
        'column_id' => $this->todo->id, 'position' => 1,
    ])->assertRedirect();

    expect(columnOrder($this->todo))->toBe([$a->id, $b->id])
        ->and($b->fresh()->updated_at->equalTo($stamp))->toBeTrue();
});

it('keeps the move response contract as a redirect-back with a flash message (WP5)', function () {
    // Redirect-back, not {ok:true}: Inertia's partial reload (only: ['columns','flash']) is
    // what returns the authoritative board state (EPIC-011E §8).
    $task = makeTask($this->todo);

    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $task]), [
        'column_id' => $this->done->id, 'position' => 0,
    ])->assertRedirect();
    expect(session('success'))->toBe('Task moved.');
});

it('closes the gap when a task is deleted', function () {
    $a = makeTask($this->todo, ['position' => 0]);
    $b = makeTask($this->todo, ['position' => 1]);
    $c = makeTask($this->todo, ['position' => 2]);

    $this->actingAs($this->manager)->delete(route('projects.tasks.destroy', [$this->project, $b]))->assertRedirect();

    expect(columnOrder($this->todo))->toBe([$a->id, $c->id])
        ->and(columnIsDense($this->todo))->toBeTrue();
});

it('validates position as a non-negative integer', function (mixed $position) {
    $task = makeTask($this->todo);

    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $task]), [
        'column_id' => $this->done->id, 'position' => $position,
    ])->assertUnprocessable()->assertJsonValidationErrors('position');
})->with([-1, 'abc', 1.5, null]);

it('never touches tasks.status when moving', function () {
    $task = makeTask($this->todo, ['status' => 'todo']);

    $this->actingAs($this->manager)->putJson(route('projects.tasks.move', [$this->project, $task]), [
        'column_id' => $this->done->id, 'position' => 0,
    ])->assertRedirect();

    expect($task->fresh()->status)->toBe('todo');
});

// ── Status contract (§15) and overdue (I6) ───────────────────────────────────

it('derives task kind, done and open consistently for every kind', function () {
    $board = makeTask($this->todo);
    $boardDone = makeTask($this->done);
    $standaloneOpen = Task::factory()->standalone()->create(['status' => 'in_progress']);
    $standaloneDone = Task::factory()->standalone()->create(['status' => 'done']);
    $ticketOpen = Task::factory()->standalone()->create(['ticket_id' => Ticket::factory()->create()->id, 'status' => 'todo']);
    $ticketDone = Task::factory()->standalone()->create(['ticket_id' => Ticket::factory()->create()->id, 'status' => 'done']);
    $orphan = makeTask($this->columns[2], ['status' => 'done']);
    $this->columns[2]->delete(); // null-column edge falls back to status

    expect($board->fresh()->kind())->toBe(Task::KIND_BOARD)
        ->and($standaloneOpen->kind())->toBe(Task::KIND_STANDALONE)
        ->and($ticketOpen->kind())->toBe(Task::KIND_TICKET)
        ->and($orphan->fresh()->kind())->toBe(Task::KIND_BOARD);

    $everyone = Task::all();
    $done = Task::done()->pluck('id')->all();
    $open = Task::open()->pluck('id')->all();

    foreach ($everyone as $task) {
        expect(in_array($task->id, $done, true))->toBe($task->isDone(), "done() vs isDone() for task {$task->id}")
            ->and(in_array($task->id, $open, true))->toBe(! $task->isDone(), "open() vs isDone() for task {$task->id}");
    }

    expect($done)->toContain($boardDone->id, $standaloneDone->id, $ticketDone->id, $orphan->id)
        ->and($open)->toContain($board->id, $standaloneOpen->id, $ticketOpen->id);
});

it('offers timer context by kind and never a Done-column board task', function () {
    $user = projectActor('member', $this->project);
    makeTask($this->columns[2], ['title' => 'Open board task', 'assignee_id' => $user->id]);
    makeTask($this->done, ['title' => 'Done board task', 'assignee_id' => $user->id]);
    Task::factory()->standalone()->create(['title' => 'Standalone open', 'assignee_id' => $user->id, 'status' => 'todo']);
    Task::factory()->standalone()->create(['title' => 'Standalone done', 'assignee_id' => $user->id, 'status' => 'done']);
    Task::factory()->standalone()->create([
        'title' => 'Ticket open', 'assignee_id' => $user->id, 'status' => 'in_progress',
        'ticket_id' => Ticket::factory()->create(['user_id' => $user->id])->id,
    ]);
    Task::factory()->standalone()->create([
        'title' => 'Ticket done', 'assignee_id' => $user->id, 'status' => 'done',
        'ticket_id' => Ticket::factory()->create(['user_id' => $user->id])->id,
    ]);
    $orphan = makeTask($this->columns[3], ['title' => 'Null column open', 'assignee_id' => $user->id, 'status' => 'todo']);
    $this->columns[3]->delete();

    $labels = collect($this->actingAs($user)->getJson(route('time.context.options', ['type' => 'task']))->assertOk()->json())
        ->pluck('label')->all();

    expect($labels)->toContain('Open board task', 'Standalone open', 'Ticket open', 'Null column open');

    // EPIC-013 A13.13 / EPIC-015 R2: `not->toContain(a, b, c)` passes unless ALL of a, b and c are
    // present, so it let any one done task through. The forbidden set must be EMPTY, which is
    // what a fail-closed assertion on the intersection says.
    expect(array_values(array_intersect($labels, ['Done board task', 'Standalone done', 'Ticket done'])))->toBe([]);
});

it('applies one overdue rule: due before today and not done', function () {
    $today = makeTask($this->todo, ['due_date' => today()]);
    $yesterday = makeTask($this->todo, ['due_date' => today()->subDay()]);
    $future = makeTask($this->todo, ['due_date' => today()->addDay()]);
    $doneLate = makeTask($this->done, ['due_date' => today()->subDays(3)]);
    $noDue = makeTask($this->todo);
    $standaloneLate = Task::factory()->standalone()->create(['due_date' => today()->subDay(), 'status' => 'todo']);
    $standaloneDoneLate = Task::factory()->standalone()->create(['due_date' => today()->subDay(), 'status' => 'done']);

    expect($today->isOverdue())->toBeFalse()
        ->and($yesterday->isOverdue())->toBeTrue()
        ->and($future->isOverdue())->toBeFalse()
        ->and($doneLate->isOverdue())->toBeFalse()
        ->and($noDue->isOverdue())->toBeFalse()
        ->and($standaloneLate->isOverdue())->toBeTrue()
        ->and($standaloneDoneLate->isOverdue())->toBeFalse()
        ->and($this->project->overdueTasks())->toBe(1)
        ->and(Task::overdue()->pluck('id')->all())->toEqualCanonicalizing([$yesterday->id, $standaloneLate->id]);
});

// ── Standalone tasks (A7, D3) ────────────────────────────────────────────────

it('accepts a standalone assignee only when it is the actor or none', function () {
    $user = makeUser();
    $other = makeUser();

    $this->actingAs($user)->post(route('tasks.store'), [
        'title' => 'To someone else', 'priority' => 'low', 'status' => 'todo', 'assignee_id' => $other->id,
    ])->assertSessionHasErrors('assignee_id');
    expect(Task::where('title', 'To someone else')->exists())->toBeFalse();

    $this->actingAs($user)->post(route('tasks.store'), [
        'title' => 'To me', 'priority' => 'low', 'status' => 'done', 'assignee_id' => $user->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('tasks.store'), [
        'title' => 'To nobody', 'priority' => 'medium', 'status' => 'in_progress', 'assignee_id' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Task::where('title', 'To me')->first())
        ->assignee_id->toBe($user->id)->status->toBe('done')->project_id->toBeNull()->column_id->toBeNull()
        ->and(Task::where('title', 'To nobody')->value('assignee_id'))->toBeNull();
});

it('keeps standalone validation unchanged', function () {
    $user = makeUser();

    $this->actingAs($user)->post(route('tasks.store'), ['title' => '', 'priority' => 'urgent', 'status' => 'blocked'])
        ->assertSessionHasErrors(['title', 'priority', 'status']);
});

// EPIC-014 §16.3: the two standalone pins above stay on create, and WP2 extends the same rule to
// the new update and assign routes (§7.3): the actor or none, never another person.
it('accepts a standalone assignee on update and assign only when it is the actor, none, or unchanged', function () {
    $user = makeUser();
    $other = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $user->id, 'assignee_id' => null, 'status' => 'todo']);
    $edit = fn (array $extra) => ['title' => 'T', 'priority' => 'low', 'status' => 'todo', ...$extra];

    $this->actingAs($user)->put(route('tasks.update', $task), $edit(['assignee_id' => $other->id]))->assertSessionHasErrors('assignee_id');
    $this->actingAs($user)->put(route('tasks.assignee.update', $task), ['assignee_id' => $other->id])->assertSessionHasErrors('assignee_id');
    expect($task->fresh()->assignee_id)->toBeNull();

    $this->actingAs($user)->put(route('tasks.assignee.update', $task), ['assignee_id' => $user->id])->assertSessionHasNoErrors();
    $this->actingAs($user)->put(route('tasks.update', $task), $edit(['assignee_id' => '']))->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBeNull();

    $this->actingAs($user)->put(route('tasks.update', $task), $edit(['title' => '', 'priority' => 'urgent', 'status' => 'blocked']))
        ->assertSessionHasErrors(['title', 'priority', 'status']);
});

// ── Checklist authoring (D5) ─────────────────────────────────────────────────

it('adds checklist items at the tail, trimmed, for managers', function () {
    $task = makeTask($this->todo);

    $this->actingAs($this->manager)->post(route('projects.tasks.checklist.store', [$this->project, $task]), ['title' => '  First step  '])
        ->assertRedirect();
    $this->actingAs($this->manager)->post(route('projects.tasks.checklist.store', [$this->project, $task]), ['title' => 'Second step'])
        ->assertRedirect();

    $items = $task->checklistItems()->get();
    expect($items->pluck('title')->all())->toBe(['First step', 'Second step'])
        ->and($items->pluck('position')->all())->toBe([0, 1])
        ->and($items->pluck('completed')->all())->toBe([false, false]);
});

it('validates checklist item titles and the 100 item cap', function () {
    $task = makeTask($this->todo);
    $url = route('projects.tasks.checklist.store', [$this->project, $task]);

    $this->actingAs($this->manager)->post($url, ['title' => ''])->assertSessionHasErrors('title');
    $this->actingAs($this->manager)->post($url, ['title' => '   '])->assertSessionHasErrors('title');
    $this->actingAs($this->manager)->post($url, ['title' => str_repeat('a', 256)])->assertSessionHasErrors('title');
    $this->actingAs($this->manager)->post($url, ['title' => str_repeat('a', 255)])->assertSessionHasNoErrors();

    TaskChecklistItem::factory()->count(99)->create(['task_id' => $task->id]);
    expect($task->checklistItems()->count())->toBe(100);

    $this->actingAs($this->manager)->post($url, ['title' => 'One too many'])->assertSessionHasErrors('title');
    expect($task->checklistItems()->count())->toBe(100);
});

it('removes a checklist item for managers and 404s on another task', function () {
    $task = makeTask($this->todo);
    $other = makeTask($this->todo);
    $item = TaskChecklistItem::factory()->create(['task_id' => $task->id]);
    $foreignItem = TaskChecklistItem::factory()->create(['task_id' => $other->id]);

    $this->actingAs($this->manager)->delete(route('projects.tasks.checklist.destroy', [$this->project, $task, $foreignItem->id]))->assertNotFound();
    expect(TaskChecklistItem::find($foreignItem->id))->not->toBeNull();

    $this->actingAs($this->manager)->delete(route('projects.tasks.checklist.destroy', [$this->project, $task, $item->id]))->assertRedirect();
    expect(TaskChecklistItem::find($item->id))->toBeNull();
});

it('sets the checklist state idempotently when completed is supplied and still toggles otherwise', function () {
    // Redirect-back, not JSON (WP7): the React panel's optimistic toggle reconciles against the
    // authoritative `checklist` prop via a partial reload, exactly like the board's move
    // contract (EPIC-011E §8, §14).
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo);
    $other = makeTask($this->todo);
    $item = TaskChecklistItem::factory()->create(['task_id' => $task->id, 'completed' => false]);
    $url = route('projects.tasks.checklist.toggle', [$this->project, $task, $item->id]);

    $this->actingAs($member)->putJson($url, ['completed' => true])->assertRedirect();
    $this->actingAs($member)->putJson($url, ['completed' => true])->assertRedirect();
    expect($item->fresh()->completed)->toBeTrue();

    $this->actingAs($member)->putJson($url, ['completed' => false])->assertRedirect();
    $this->actingAs($member)->putJson($url, ['completed' => false])->assertRedirect();
    expect($item->fresh()->completed)->toBeFalse();

    $this->actingAs($member)->putJson($url)->assertRedirect();
    expect($item->fresh()->completed)->toBeTrue();
    $this->actingAs($member)->putJson($url)->assertRedirect();
    expect($item->fresh()->completed)->toBeFalse();

    $this->actingAs($member)->putJson($url, ['completed' => 'maybe'])->assertUnprocessable();
    $this->actingAs($member)->putJson(route('projects.tasks.checklist.toggle', [$this->project, $other, $item->id]))->assertNotFound();
});

// ── Comments (pinned) ────────────────────────────────────────────────────────

it('stores comments raw, requires a body of at most 5000 characters and lists oldest first', function () {
    $member = projectActor('member', $this->project);
    $task = makeTask($this->todo);
    $url = route('projects.tasks.comments.store', [$this->project, $task]);

    $this->actingAs($member)->post($url, ['body' => ''])->assertSessionHasErrors('body');
    $this->actingAs($member)->post($url, ['body' => str_repeat('a', 5001)])->assertSessionHasErrors('body');

    $this->actingAs($member)->post($url, ['body' => '<b>bold</b> & <script>x</script>'])->assertRedirect();
    $this->travel(1)->minutes();
    $this->actingAs($member)->post($url, ['body' => 'second'])->assertRedirect();

    expect($task->comments()->pluck('body')->all())->toBe(['<b>bold</b> & <script>x</script>', 'second']);
});
