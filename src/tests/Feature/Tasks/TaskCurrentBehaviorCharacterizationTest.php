<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 WP1 characterization (§17 WP1, §16.2). Written against the code as it stood at
 * `7fa5584`, before any EPIC-014 seam existed, so later packages change behaviour on purpose
 * rather than by accident. Each test is one of three kinds, and says which in its name:
 *
 *   PRESERVED   — behaviour EPIC-014 keeps (an invariant from §6).
 *   KNOWN DEFECT — current behaviour EPIC-014 deliberately changes in a named later package. The
 *                 assertion records today's behaviour so the change is visible when it lands; it
 *                 is not a statement that the behaviour is desired.
 *   OBSERVED    — a fact a later package depends on, pinned so a decision can be made about it.
 *
 * The §12.2 time-entry tests carry a fourth flavour, KNOWN DEFECT / TRANSITION: the owner chose
 * option (c) (an unchanged task attribution on an existing unbilled entry stays valid on unrelated
 * edits, even after the actor loses eligibility), which WP2 implements. They pin today's rejection
 * so that change lands visibly; new task-attributed time, new timers and a change to a different
 * task keep requiring current eligibility.
 *
 * The TaskPolicy/TaskService/audit seams have their own suites; nothing here uses them.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->columns = $this->project->columns()->orderBy('position')->get();
    $this->done = $this->columns->firstWhere('is_done_column', true);
    $this->open = $this->columns->firstWhere('is_done_column', false);
});

function ticketTask(array $attributes = []): Task
{
    $ticket = Ticket::factory()->create();

    return Task::factory()->standalone()->create(['ticket_id' => $ticket->id, ...$attributes]);
}

// ── Kinds and the done rule ──────────────────────────────────────────────────

it('PRESERVED (INV-1): derives kind and done per kind — the column decides for a board task, status otherwise', function () {
    $boardOpen = makeTask($this->open, ['status' => 'done']);
    $boardDone = makeTask($this->done, ['status' => 'todo']);
    $standalone = Task::factory()->standalone()->create(['status' => 'done']);
    $ticket = ticketTask(['status' => 'in_progress']);

    expect($boardOpen->kind())->toBe(Task::KIND_BOARD)
        ->and($boardOpen->isDone())->toBeFalse()   // the raw status is ignored while a column exists
        ->and($boardDone->isDone())->toBeTrue()
        ->and($boardDone->effectiveStatus())->toBe('Done')
        ->and($standalone->kind())->toBe(Task::KIND_STANDALONE)
        ->and($standalone->isDone())->toBeTrue()
        ->and($ticket->kind())->toBe(Task::KIND_TICKET)
        ->and($ticket->isDone())->toBeFalse()
        ->and($ticket->effectiveStatus())->toBe('In Progress');

    expect(Task::done()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$boardDone->id, $standalone->id])->sort()->values()->all());
});

it('OBSERVED (F9, INV-13): a dual-linked row is classified as a board task and follows its column', function () {
    // Nothing in the application writes this shape (see the INV-13 creation tests below); only a
    // direct write can. Today the model has no notion of "malformed": kind() prefers the project.
    $ticket = Ticket::factory()->create();
    $dual = makeTask($this->done, ['ticket_id' => $ticket->id, 'status' => 'todo']);

    expect($dual->kind())->toBe(Task::KIND_BOARD)
        ->and($dual->isDone())->toBeTrue();
});

it('KNOWN DEFECT (INV-13; WP3 excludes it): a dual-linked row assigned to the viewer is listed on /tasks as a project row', function () {
    $user = projectActor('member', $this->project);
    $ticket = Ticket::factory()->create();
    makeTask($this->open, ['ticket_id' => $ticket->id, 'assignee_id' => $user->id, 'title' => 'Dual linked']);

    $this->actingAs($user)->get(route('tasks.index'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tasks.data.0.title', 'Dual linked')
            ->where('tasks.data.0.context.kind', 'project'));
});

// ── INV-13: no application path creates a dual-linked row ───────────────────

it('PRESERVED (INV-13): neither create path can produce a task linked to both a project and a ticket', function () {
    $manager = projectActor('project_manager', $this->project);
    $ticket = Ticket::factory()->create();

    $this->actingAs($manager)->post(route('projects.tasks.store', $this->project), [
        'column_id' => $this->open->id, 'title' => 'Board create', 'priority' => 'low', 'ticket_id' => $ticket->id,
    ])->assertSessionHasNoErrors();

    $this->actingAs($manager)->post(route('tasks.store'), [
        'title' => 'Standalone create', 'priority' => 'low', 'status' => 'todo',
        'ticket_id' => $ticket->id, 'project_id' => $this->project->id,
    ])->assertSessionHasNoErrors();

    expect(Task::where('title', 'Board create')->first()->only(['project_id', 'ticket_id']))
        ->toBe(['project_id' => $this->project->id, 'ticket_id' => null])
        ->and(Task::where('title', 'Standalone create')->first()->only(['project_id', 'ticket_id']))
        ->toBe(['project_id' => null, 'ticket_id' => null]);
});

// ── INV-9: project identity and kind never change ────────────────────────────

it('PRESERVED (INV-9): a task update ignores project_id and ticket_id', function () {
    $manager = projectActor('project_manager', $this->project);
    $other = makeProject(null, 'Other');
    $ticket = Ticket::factory()->create();
    $task = makeTask($this->open);

    $this->actingAs($manager)->put(route('projects.tasks.update', [$this->project, $task]), [
        'title' => 'Renamed', 'priority' => 'high',
        'project_id' => $other->id, 'ticket_id' => $ticket->id,
    ])->assertSessionHasNoErrors();

    expect($task->fresh()->only(['title', 'project_id', 'ticket_id', 'column_id']))->toBe([
        'title' => 'Renamed', 'project_id' => $this->project->id, 'ticket_id' => null, 'column_id' => $this->open->id,
    ]);
});

// ── F1: the unassigned standalone orphan ─────────────────────────────────────

it('KNOWN DEFECT F1 (WP3 fixes it; Q4): an unassigned standalone task is listed to nobody, its creator included', function () {
    $creator = makeUser();

    $this->actingAs($creator)->post(route('tasks.store'), [
        'title' => 'Orphaned', 'priority' => 'low', 'status' => 'todo', 'assignee_id' => null,
    ])->assertSessionHasNoErrors();

    $task = Task::where('title', 'Orphaned')->firstOrFail();
    expect($task->only(['created_by', 'assignee_id', 'project_id', 'ticket_id']))
        ->toBe(['created_by' => $creator->id, 'assignee_id' => null, 'project_id' => null, 'ticket_id' => null]);

    // Neither view reaches it, so the creator can never see, finish or log time on it again.
    foreach (['mine', 'org'] as $view) {
        $this->actingAs($creator)->get(route('tasks.index', ['view' => $view]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.total', 0));
    }
});

// ── Deletion ─────────────────────────────────────────────────────────────────

it('PRESERVED (INV-12): the database refuses to delete a standalone or ticket task that recorded time', function () {
    $worker = makeUser();
    $standalone = Task::factory()->standalone()->create();
    $ticket = ticketTask();
    TimeEntry::factory()->create(['user_id' => $worker->id, 'task_id' => $standalone->id]);
    TimeEntry::factory()->create(['user_id' => $worker->id, 'task_id' => $ticket->id]);

    // There is no application delete path for either kind today (EPIC-011E D3); the RESTRICT
    // key is the only thing between a raw delete and the history.
    // Error 1451 is MariaDB/MySQL's "parent row referenced by a RESTRICT foreign key": pinning the
    // code keeps this from passing when a delete fails for some unrelated database reason.
    foreach ([$standalone, $ticket] as $task) {
        try {
            DB::table('tasks')->where('id', $task->id)->delete();
            $this->fail("Expected the RESTRICT key to refuse deleting task {$task->id}.");
        } catch (QueryException $e) {
            expect((int) $e->errorInfo[1])->toBe(1451);
        }
    }

    expect(Task::whereKey([$standalone->id, $ticket->id])->count())->toBe(2);
});

it('PRESERVED: an unreferenced standalone task is deletable at the model level (no application route exists)', function () {
    $standalone = Task::factory()->standalone()->create();

    $standalone->delete();

    expect(Task::find($standalone->id))->toBeNull();
});

// ── §12.2: releasing a task and existing unbilled time ───────────────────────

it('KNOWN DEFECT / TRANSITION (§12.2, owner decision (c); WP2 intentionally changes this): once the actor loses eligibility for a standalone task, an unrelated edit of their unbilled entry that keeps the task is rejected', function () {
    $worker = makeUser();   // the `user` role holds time.log
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create([
        'user_id' => $worker->id, 'task_id' => $task->id, 'duration_minutes' => 60,
        'date' => today()->toDateString(), 'description' => 'Before',
    ]);

    // Eligible while assigned: an edit that keeps the context succeeds.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 1.5, 'task_id' => $task->id, 'description' => 'Still mine',
    ])->assertSessionHasNoErrors();
    expect($entry->fresh()->duration_minutes)->toBe(90);

    // Release it (no route does this today; EPIC-014 WP2 adds one). The creator is no longer the
    // assignee, and AccessibleTimeContext admits only an assignee or a project/ticket viewer.
    $task->update(['assignee_id' => null]);

    // TRANSITION: today this edit, keeping the unchanged task, is rejected on task_id. Option (c)
    // makes it valid in WP2 (the attribution is preserved, not re-granted); this assertion is then
    // expected to flip. Creating new time, starting a timer or moving the entry to another task
    // stays gated on current eligibility.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'task_id' => $task->id, 'description' => 'Edit after release',
    ])->assertSessionHasErrors('task_id');
    expect($entry->fresh()->only(['task_id', 'duration_minutes', 'description']))
        ->toBe(['task_id' => $task->id, 'duration_minutes' => 90, 'description' => 'Still mine']);
});

it('OBSERVED (§12.2; flagged for WP2): omitting task_id from an entry update currently clears its task attribution', function () {
    $worker = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create([
        'user_id' => $worker->id, 'task_id' => $task->id, 'duration_minutes' => 60,
        'date' => today()->toDateString(), 'description' => 'Before',
    ]);

    // The update path writes the context from the request, so leaving the field out drops it.
    // This is current mechanics, NOT a desired invariant: omission is not necessarily an explicit
    // user request to clear attribution, and WP2's option (c) work must decide how an absent
    // task_id and an explicit clear are told apart.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'description' => 'Context dropped',
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes']))->toBe(['task_id' => null, 'duration_minutes' => 120]);
});

it('OBSERVED (§12.2): an existing entry on a board task stays editable after its assignee leaves the project, because the stale assignment still grants task eligibility; only unassigning removes it', function () {
    $worker = projectActor('member', $this->project);
    $task = makeTask($this->open, ['assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $worker->id, 'task_id' => $task->id, 'date' => today()->toDateString()]);

    $this->project->members()->detach($worker->id);

    // Still the stored assignee (I8 keeps it), and AccessibleTimeContext admits the assignee, so
    // a departed member is still eligible: the edit succeeds today.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasNoErrors();

    // TRANSITION (owner decision (c); WP2): once unassigned, keeping the task is rejected today.
    $task->update(['assignee_id' => null]);
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasErrors('task_id');
});

it('OBSERVED / DEFERRED TIME-DOMAIN FOLLOW-UP: a board assignee who left the project can still start NEW time on the task they are still stored against', function () {
    // Pinned so the inconsistency is visible, NOT endorsed and NOT an EPIC-014 invariant. TaskPolicy
    // (§7.2) denies this actor sight of the task, yet AccessibleTimeContext admits the stored
    // assignee for new time. A future Time-domain package may intentionally change this (for
    // example, stale assignment alone no longer granting new-time eligibility); if it does, this
    // test is expected to flip. It is deliberately unowned by any EPIC-014 work package.
    $worker = projectActor('member', $this->project);
    $task = makeTask($this->open, ['assignee_id' => $worker->id]);

    $this->project->members()->detach($worker->id);
    $task = $task->fresh();

    expect($task->assignee_id)->toBe($worker->id)
        ->and(Gate::forUser($worker)->allows('view', $task->project))->toBeFalse();

    $this->actingAs($worker)->postJson(route('time.timer.start'), ['task_id' => $task->id])->assertOk();
    expect(TimeEntry::where('user_id', $worker->id)->where('task_id', $task->id)->running()->count())->toBe(1);

    // A new manual entry is admitted the same way.
    $this->actingAs($worker)->post(route('time.store'), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasNoErrors();
    expect(TimeEntry::where('user_id', $worker->id)->where('task_id', $task->id)->count())->toBe(2);
});

it('PRESERVED (timer ownership): a running timer on a task the actor is no longer eligible for can still be stopped', function () {
    $worker = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $running = TimeEntry::factory()->running()->create(['user_id' => $worker->id, 'task_id' => $task->id]);

    $task->update(['assignee_id' => null]);

    $this->actingAs($worker)->postJson(route('time.timer.stop', $running))->assertOk();
    expect($running->fresh()->isRunning())->toBeFalse()
        ->and($running->fresh()->task_id)->toBe($task->id);
});

it('PRESERVED (INV-14): a billed entry on a task stays locked whatever the task eligibility', function () {
    $worker = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $billed = TimeEntry::factory()->billed()->create(['user_id' => $worker->id, 'task_id' => $task->id, 'date' => today()->toDateString()]);
    $before = $billed->fresh()->toArray();

    $this->actingAs($worker)->put(route('time.update', $billed), [
        'date' => today()->toDateString(), 'hours' => 3, 'task_id' => $task->id,
    ])->assertSessionHasErrors('time_entry');

    expect($billed->fresh()->toArray())->toBe($before);
});
