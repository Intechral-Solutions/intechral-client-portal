<?php

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 owner decision (c), implemented in WP2 (Amendment 1 §A1.3, Amendment 2).
 *
 * Current eligibility (AccessibleTimeContext) controls NEW or CHANGED task attribution. An
 * existing, unbilled entry keeps its UNCHANGED task attribution through unrelated edits, even
 * after its owner stops being eligible for that task. Nothing here widens who may log new time:
 * manual entries, timers and a change of task still need current eligibility, and the standalone
 * creator is still not eligible on its own (P5).
 *
 * Request semantics of PUT /time/{entry} (the context is one of project_id / task_id / ticket_id):
 *   - none of the three keys sent   → the context is unchanged (the service already treated an
 *                                     absent key as "keep"; the controller no longer turns absence
 *                                     into null);
 *   - any of the three keys sent    → the submitted triple replaces the context, a key left out of
 *                                     it being null, exactly as before. The time page always sends
 *                                     all three, with explicit nulls for the unused two;
 *   - task_id: null (explicit)      → clears the task, as before.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->worker = makeUser();   // the `user` role holds time.log
    $this->today = today()->toDateString();
});

/** A standalone task the worker is eligible for (its assignee), with one unbilled entry on it. */
function attributedEntry(User $worker, array $entry = []): array
{
    $task = Task::factory()->standalone()->create(['created_by' => $worker->id, 'assignee_id' => $worker->id]);
    $entry = TimeEntry::factory()->create([
        'user_id' => $worker->id, 'task_id' => $task->id, 'project_id' => null, 'ticket_id' => null,
        'duration_minutes' => 60, 'date' => today()->toDateString(), 'description' => 'Before', 'billable' => true,
        ...$entry,
    ]);

    return [$task, $entry];
}

/** Release the task: the worker is no longer its assignee, so no longer currently eligible. */
function release(Task $task): void
{
    $task->update(['assignee_id' => null]);
}

/** The time page's payload: every context key, with explicit nulls for the unused two. */
function timePagePayload(array $overrides = []): array
{
    return [
        'date' => today()->toDateString(), 'hours' => 2, 'description' => 'After', 'billable' => true,
        'project_id' => null, 'task_id' => null, 'ticket_id' => null,
        ...$overrides,
    ];
}

// ── The matrix ───────────────────────────────────────────────────────────────

it('A: an eligible actor edits unrelated fields keeping the task', function () {
    [$task, $entry] = attributedEntry($this->worker);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes', 'description']))
        ->toBe(['task_id' => $task->id, 'duration_minutes' => 120, 'description' => 'After']);
});

it('B: an actor who lost eligibility edits unrelated fields keeping the unchanged task, and the attribution survives', function () {
    [$task, $entry] = attributedEntry($this->worker);
    release($task);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes', 'description']))
        ->toBe(['task_id' => $task->id, 'duration_minutes' => 120, 'description' => 'After'])
        ->and($entry->fresh()->date->toDateString())->toBe($this->today);
});

it('B: also for a board task whose assignment was removed', function () {
    $project = makeProject();
    $task = makeTask($project->columns[1], ['assignee_id' => $this->worker->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $task->id, 'date' => $this->today]);
    $task->update(['assignee_id' => null]);   // not a member either: no remaining eligibility

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id, 'hours' => 3]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes']))->toBe(['task_id' => $task->id, 'duration_minutes' => 180]);
});

it('C: omitting every context key leaves the existing task attribution unchanged', function () {
    [$task, $entry] = attributedEntry($this->worker);
    release($task);

    $this->actingAs($this->worker)->put(route('time.update', $entry), [
        'date' => $this->today, 'hours' => 2, 'description' => 'Context not sent',
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'project_id', 'ticket_id', 'duration_minutes', 'description']))->toBe([
        'task_id' => $task->id, 'project_id' => null, 'ticket_id' => null,
        'duration_minutes' => 120, 'description' => 'Context not sent',
    ]);
});

it('C: omission keeps a project context too; only an explicit context changes it', function () {
    $project = makeProject(null, 'Ctx');
    $project->members()->attach($this->worker->id, ['role' => 'member']);
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $project->id, 'task_id' => null, 'date' => $this->today]);

    $this->actingAs($this->worker)->put(route('time.update', $entry), ['date' => $this->today, 'hours' => 1])
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->project_id)->toBe($project->id);
});

it('D: an explicit clear removes the task, whatever the current eligibility', function () {
    [$task, $entry] = attributedEntry($this->worker);
    release($task);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload())
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'project_id', 'ticket_id']))
        ->toBe(['task_id' => null, 'project_id' => null, 'ticket_id' => null]);
});

it('D: sending only task_id: null is an explicit clear as well', function () {
    [$task, $entry] = attributedEntry($this->worker);

    $this->actingAs($this->worker)->put(route('time.update', $entry), [
        'date' => $this->today, 'hours' => 1, 'task_id' => null,
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->task_id)->toBeNull();
});

it('E: an actor who lost eligibility may switch the entry to a different task they are eligible for', function () {
    [$old, $entry] = attributedEntry($this->worker);
    release($old);
    $new = Task::factory()->standalone()->create(['created_by' => $this->worker->id, 'assignee_id' => $this->worker->id]);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $new->id]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->task_id)->toBe($new->id);
});

it('F: switching to a task the actor is not eligible for is rejected and changes nothing', function () {
    [$task, $entry] = attributedEntry($this->worker);
    $foreign = Task::factory()->standalone()->create(['assignee_id' => makeUser()->id]);
    $before = $entry->fresh()->toArray();

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $foreign->id]))
        ->assertSessionHasErrors('task_id');

    expect($entry->fresh()->toArray())->toBe($before);
});

it('F: switching back to a released task is a change, so it needs current eligibility again', function () {
    [$released, $entry] = attributedEntry($this->worker);
    $other = Task::factory()->standalone()->create(['created_by' => $this->worker->id, 'assignee_id' => $this->worker->id]);
    $entry->update(['task_id' => $other->id]);
    release($released);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $released->id]))
        ->assertSessionHasErrors('task_id');

    expect($entry->fresh()->task_id)->toBe($other->id);
});

it('G: adding an eligible task to an entry without one passes', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => null, 'project_id' => null, 'date' => $this->today]);
    $task = Task::factory()->standalone()->create(['created_by' => $this->worker->id, 'assignee_id' => $this->worker->id]);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->task_id)->toBe($task->id);
});

it('H: adding an ineligible task to an entry without one is rejected', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => null, 'project_id' => null, 'date' => $this->today]);
    // P5: the creator of an unassigned standalone task is not eligible until they take it.
    $unassigned = Task::factory()->standalone()->create(['created_by' => $this->worker->id, 'assignee_id' => null]);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $unassigned->id]))
        ->assertSessionHasErrors('task_id');

    expect($entry->fresh()->task_id)->toBeNull();
});

// ── What (c) does not touch ──────────────────────────────────────────────────

it('keeps a billed entry locked whatever the task eligibility, unchanged task included', function (string $state) {
    [$task, $entry] = attributedEntry($this->worker);
    $entry = TimeEntry::factory()->{$state}()->create(['user_id' => $this->worker->id, 'task_id' => $task->id, 'date' => $this->today]);
    release($task);
    $before = $entry->fresh()->toArray();

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id]))
        ->assertSessionHasErrors('time_entry');

    expect($entry->fresh()->toArray())->toBe($before);
})->with(['billed', 'invoiced']);

it('still requires current eligibility for a new manual entry on the task', function () {
    [$task] = attributedEntry($this->worker);
    release($task);

    $this->actingAs($this->worker)->post(route('time.store'), [
        'date' => $this->today, 'hours' => 1, 'task_id' => $task->id,
    ])->assertSessionHasErrors('task_id');

    expect(TimeEntry::where('task_id', $task->id)->count())->toBe(1);
});

it('still requires current eligibility to start a timer on the task', function () {
    [$task] = attributedEntry($this->worker);
    release($task);

    $this->actingAs($this->worker)->postJson(route('time.timer.start'), ['task_id' => $task->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('task_id');

    expect(TimeEntry::where('task_id', $task->id)->running()->count())->toBe(0);
});

it('does not make the creator of a released standalone task eligible for new time (P5; option (b) rejected)', function () {
    $task = Task::factory()->standalone()->create(['created_by' => $this->worker->id, 'assignee_id' => null]);

    $this->actingAs($this->worker)->postJson(route('time.timer.start'), ['task_id' => $task->id])
        ->assertJsonValidationErrors('task_id');
    $this->actingAs($this->worker)->post(route('time.store'), ['date' => $this->today, 'hours' => 1, 'task_id' => $task->id])
        ->assertSessionHasErrors('task_id');
});

it('still lets the owner stop a running timer on a task they are no longer eligible for', function () {
    [$task] = attributedEntry($this->worker);
    $running = TimeEntry::factory()->running()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);
    release($task);

    $this->actingAs($this->worker)->postJson(route('time.timer.stop', $running))->assertOk();

    expect($running->fresh()->isRunning())->toBeFalse()
        ->and($running->fresh()->task_id)->toBe($task->id);
});

it('never lets another user edit the entry, whatever it keeps', function () {
    [$task, $entry] = attributedEntry($this->worker);

    $this->actingAs(makeUser())->put(route('time.update', $entry), timePagePayload(['task_id' => $task->id]))
        ->assertForbidden();
});

it('does not extend the carve-out to an unchanged project context the actor can no longer view', function () {
    // Owner decision (c) is about task attribution. Project and ticket contexts keep today's rule.
    $project = makeProject(null, 'Left');
    $entry = TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $project->id, 'task_id' => null, 'date' => $this->today]);

    $this->actingAs($this->worker)->put(route('time.update', $entry), timePagePayload(['project_id' => $project->id]))
        ->assertSessionHasErrors('project_id');
});
