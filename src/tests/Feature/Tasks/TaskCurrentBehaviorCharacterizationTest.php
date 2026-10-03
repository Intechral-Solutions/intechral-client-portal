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
 * WP3 flipped the two list KNOWN DEFECTs (F1 and the dual-linked row) in place; they are labelled
 * FLIPPED IN WP3 and say what the list did before.
 *
 * The §12.2 time-entry tests carried a fourth flavour, KNOWN DEFECT / TRANSITION: the owner chose
 * option (c) (an unchanged task attribution on an existing unbilled entry stays valid on unrelated
 * edits, even after the actor loses eligibility). WP2 implemented it and flipped those assertions
 * in place; they are now labelled FLIPPED IN WP2 and keep the history of what changed. New
 * task-attributed time, new timers and a change to a different task still require current
 * eligibility (the full matrix is Time/TimeEntryTaskAttributionTest).
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

it('FLIPPED IN WP3 (INV-13, §9.1.1): a dual-linked row assigned to the viewer is no longer listed on /tasks, and is left untouched', function () {
    $user = projectActor('member', $this->project);
    $user->givePermissionTo('tasks.view_all');
    $ticket = Ticket::factory()->create();
    $dual = makeTask($this->open, ['ticket_id' => $ticket->id, 'assignee_id' => $user->id, 'title' => 'Dual linked']);

    // Until WP3 (at 55d2139) this row was listed as a project row (`context.kind` "project"),
    // because the list classified it through Task::kind(). The workspace query now admits only
    // `ticket_id IS NULL`, so it appears in neither view, for anyone.
    foreach (['mine', 'all'] as $view) {
        $this->actingAs($user)->get(route('tasks.index', ['view' => $view, 'completion' => 'any']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.total', 0));
    }

    expect($dual->fresh()->only(['project_id', 'ticket_id', 'assignee_id']))
        ->toBe(['project_id' => $this->project->id, 'ticket_id' => $ticket->id, 'assignee_id' => $user->id]);
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

it('FLIPPED IN WP3 (F1, Q4): an unassigned standalone task is listed in its creator\'s My Tasks, and to nobody else', function () {
    $creator = makeUser();
    $other = makeUser('operator');

    $this->actingAs($creator)->post(route('tasks.store'), [
        'title' => 'Orphaned', 'priority' => 'low', 'status' => 'todo', 'assignee_id' => null,
    ])->assertSessionHasNoErrors();

    $task = Task::where('title', 'Orphaned')->firstOrFail();
    expect($task->only(['created_by', 'assignee_id', 'project_id', 'ticket_id']))
        ->toBe(['created_by' => $creator->id, 'assignee_id' => null, 'project_id' => null, 'ticket_id' => null]);

    // Until WP3 (at 55d2139) neither view reached it ("mine" was `assignee_id = me` only), so the
    // creator could never see, finish or log time on it again. The retired `view=org` now clamps
    // to mine, so it lists the task too.
    foreach (['mine', 'org'] as $view) {
        $this->actingAs($creator)->get(route('tasks.index', ['view' => $view]))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('view', 'mine')
                ->where('tasks.total', 1)
                ->where('tasks.data.0.id', $task->id));
    }

    // An operator holding tasks.view_all still never sees another user's personal task (Q3).
    foreach (['mine', 'all'] as $view) {
        $this->actingAs($other)->get(route('tasks.index', ['view' => $view]))->assertOk()
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

    // At 7fa5584 no application delete path existed for either kind (EPIC-011E D3). WP2 adds one
    // for standalone tasks, through RecordedTimeGuard; a raw delete still meets the RESTRICT key,
    // the final protection for both kinds.
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

it('PRESERVED: an unreferenced standalone task is deletable at the model level (WP2 adds DELETE /tasks/{task} over the shared guard)', function () {
    $standalone = Task::factory()->standalone()->create();

    $standalone->delete();

    expect(Task::find($standalone->id))->toBeNull();
});

// ── §12.2: releasing a task and existing unbilled time ───────────────────────

it('FLIPPED IN WP2 (§12.2, owner decision (c)): once the actor loses eligibility for a standalone task, an unrelated edit of their unbilled entry that keeps the task is accepted and the attribution survives', function () {
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

    // Release it. The creator is no longer the assignee, and AccessibleTimeContext admits only an
    // assignee or a project/ticket viewer, so they are no longer eligible for NEW time on it.
    $task->update(['assignee_id' => null]);

    // Before WP2 this edit was rejected on task_id (KNOWN DEFECT / TRANSITION at 7fa5584). Under
    // owner decision (c) the unchanged attribution is preserved, not re-granted: the edit passes.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'task_id' => $task->id, 'description' => 'Edit after release',
    ])->assertSessionHasNoErrors();
    expect($entry->fresh()->only(['task_id', 'duration_minutes', 'description']))
        ->toBe(['task_id' => $task->id, 'duration_minutes' => 120, 'description' => 'Edit after release']);
});

it('FLIPPED IN WP2 (§12.2): omitting every context key from an entry update no longer clears its task attribution', function () {
    $worker = makeUser();
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create([
        'user_id' => $worker->id, 'task_id' => $task->id, 'duration_minutes' => 60,
        'date' => today()->toDateString(), 'description' => 'Before',
    ]);

    // At 7fa5584 (OBSERVED, flagged for WP2) the controller wrote the context from the request, so
    // leaving it out dropped the task. Omission was never a request to clear, and TimeEntryService
    // already read an absent key as "keep"; WP2 aligns the controller with it. An explicit
    // `task_id: null` still clears (TimeEntryTaskAttributionTest D).
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'description' => 'Context not sent',
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes']))->toBe(['task_id' => $task->id, 'duration_minutes' => 120]);
});

it('OBSERVED (§12.2): an existing entry on a board task stays editable after its assignee leaves the project; FLIPPED IN WP2: it stays editable after unassigning too; RATIONALE CHANGED IN EPIC-015 WP1: the unchanged attribution survives under decision (c), no longer because a stale assignment grants eligibility (it does not, Q8)', function () {
    $worker = projectActor('member', $this->project);
    $task = makeTask($this->open, ['assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $worker->id, 'task_id' => $task->id, 'date' => today()->toDateString()]);

    $this->project->members()->detach($worker->id);

    // Still the stored assignee (I8 keeps it). Until EPIC-015 WP1 the edit passed because
    // AccessibleTimeContext admitted the stale assignee; it now passes because the task is UNCHANGED
    // (decision (c), keepsTask skips the eligibility rule) — Q8 would refuse this actor a new entry.
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasNoErrors();

    // Before WP2, unassigning made this edit fail on task_id. Under owner decision (c) the
    // unchanged attribution survives the unrelated edit.
    $task->update(['assignee_id' => null]);
    $this->actingAs($worker)->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasNoErrors();
    expect($entry->fresh()->task_id)->toBe($task->id);
});

it('FLIPPED IN EPIC-015 WP1 (Q8): a board assignee who left the project can no longer start NEW time on the task they are still stored against', function () {
    // Until EPIC-015 WP1 (at 58c58f1) this was pinned OBSERVED / DEFERRED TIME-DOMAIN FOLLOW-UP:
    // TaskPolicy (§7.2) denied this actor sight of the task, yet AccessibleTimeContext admitted the
    // stored assignee for new time, so the departed member could still start a timer and log time.
    // Owner decision Q8 closed it: a board task's new-time eligibility is the actor's CURRENT
    // ProjectPolicy::view. The assignee is still stored (INV-7); it just grants nothing alone.
    // The full matrix, including what is preserved, is Time/StaleAssigneeTimeEligibilityTest.
    $worker = projectActor('member', $this->project);
    $task = makeTask($this->open, ['assignee_id' => $worker->id]);

    $this->project->members()->detach($worker->id);
    $task = $task->fresh();

    expect($task->assignee_id)->toBe($worker->id)
        ->and(Gate::forUser($worker)->allows('view', $task->project))->toBeFalse();

    $this->actingAs($worker)->postJson(route('time.timer.start'), ['task_id' => $task->id])->assertUnprocessable();
    expect(TimeEntry::where('user_id', $worker->id)->where('task_id', $task->id)->count())->toBe(0);

    $this->actingAs($worker)->post(route('time.store'), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasErrors('task_id');
    expect(TimeEntry::where('user_id', $worker->id)->where('task_id', $task->id)->count())->toBe(0);
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
