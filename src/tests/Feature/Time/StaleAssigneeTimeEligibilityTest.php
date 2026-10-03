<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Rules\AccessibleTimeContext;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR A, owner decision Q8 (§4, INV-P5, INV-P6): for a BOARD task, NEW time eligibility
 * requires the actor's CURRENT ProjectPolicy::view. A stored `assignee_id` alone no longer grants
 * new time once the user has left the project (EPIC-014 A1.3.1(1)).
 *
 * KNOWN DEFECT at `58c58f1`: AccessibleTimeContext::canUseTask admitted the stored assignee for
 * every kind before looking at the project (pinned OBSERVED in TaskCurrentBehaviorCharacterizationTest,
 * flipped there in WP1). Each test says which of these it is:
 *
 *   FLIPPED IN WP1 — red before the fix, green after.
 *   PRESERVED      — behaviour Q8 does not touch; green before and after.
 *
 * The single authority is AccessibleTimeContext; every endpoint (timer start, manual store, entry
 * update to a changed task) and the context-options picker go through it, so these tests drive the
 * real routes rather than the rule in isolation.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->project = makeProject();
    $this->open = $this->project->columns()->where('is_done_column', false)->orderBy('position')->first();
    $this->task = makeTask($this->open, ['title' => 'Board task']);
});

function staleAssignee($test): User
{
    $user = projectActor('member', $test->project);
    $test->task->update(['assignee_id' => $user->id]);
    $test->project->members()->detach($user->id);

    return $user->fresh();
}

function startTimer($test, User $user, int $taskId)
{
    return $test->actingAs($user)->postJson(route('time.timer.start'), ['task_id' => $taskId]);
}

function logManual($test, User $user, int $taskId)
{
    return $test->actingAs($user)->post(route('time.store'), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $taskId,
    ]);
}

function taskOptions($test, User $user): array
{
    return collect($test->actingAs($user)->getJson(route('time.context.options', ['type' => 'task']))->assertOk()->json())
        ->pluck('label')->all();
}

// ── Board task: the locked rule ───────────────────────────────────────────────

it('FLIPPED IN WP1 (Q8): a departed stored assignee cannot start a timer on the board task', function () {
    $user = staleAssignee($this);

    expect($this->task->fresh()->assignee_id)->toBe($user->id);
    startTimer($this, $user, $this->task->id)->assertUnprocessable()->assertJsonValidationErrors('task_id');

    expect(TimeEntry::where('user_id', $user->id)->count())->toBe(0);
});

it('FLIPPED IN WP1 (Q8): a departed stored assignee cannot log manual time on the board task', function () {
    $user = staleAssignee($this);

    logManual($this, $user, $this->task->id)->assertSessionHasErrors('task_id');

    expect(TimeEntry::where('user_id', $user->id)->count())->toBe(0);
});

it('FLIPPED IN WP1 (Q8): the task leaves a departed assignee\'s eligible time-context options', function () {
    $user = staleAssignee($this);

    expect(taskOptions($this, $user))->not->toContain('Board task');
});

it('FLIPPED IN WP1 (Q8): the stored assignee_id is untouched, and the departed user is not admitted by the rule itself', function () {
    $user = staleAssignee($this);

    expect(AccessibleTimeContext::allows($user, 'task', $this->task->id))->toBeFalse()
        ->and($this->task->fresh()->assignee_id)->toBe($user->id);
});

it('PRESERVED: re-adding the departed assignee to the project restores their eligibility', function () {
    $user = staleAssignee($this);
    $this->project->members()->attach($user->id, ['role' => 'member']);

    startTimer($this, $user, $this->task->id)->assertOk();
    expect(taskOptions($this, $user))->toContain('Board task');
});

it('PRESERVED: a departed assignee cannot open the task detail, so its logTime ability is never reached', function () {
    $user = staleAssignee($this);

    $this->actingAs($user)->get(route('projects.tasks.show', [$this->project, $this->task]))->assertForbidden();
});

// ── Board task: who stays eligible ────────────────────────────────────────────

it('PRESERVED: a current member who is the assignee can start a timer, log time and sees the task offered', function () {
    $user = projectActor('member', $this->project);
    $this->task->update(['assignee_id' => $user->id]);

    startTimer($this, $user, $this->task->id)->assertOk();
    logManual($this, $user, $this->task->id)->assertSessionHasNoErrors();
    expect(taskOptions($this, $user))->toContain('Board task');
});

it('PRESERVED: a current member who is NOT the assignee can log time on any project task (ProjectPolicy::view), though the picker lists only their assigned tasks', function () {
    $user = projectActor('member', $this->project);

    logManual($this, $user, $this->task->id)->assertSessionHasNoErrors();
    expect(taskOptions($this, $user))->not->toContain('Board task');
});

it('PRESERVED: an operator who can view the project (projects.admin) without being a member can log time on it', function () {
    $operator = makeUser('operator');

    logManual($this, $operator, $this->task->id)->assertSessionHasNoErrors();
});

it('PRESERVED: an outsider who is not the assignee is denied', function () {
    $outsider = makeUser();

    startTimer($this, $outsider, $this->task->id)->assertUnprocessable();
    logManual($this, $outsider, $this->task->id)->assertSessionHasErrors('task_id');
});

it('PRESERVED: Q8 does not widen time.log — a member without the permission is still refused at the route', function () {
    $user = User::factory()->create();
    $this->project->members()->attach($user->id, ['role' => 'member']);
    $this->task->update(['assignee_id' => $user->id]);

    startTimer($this, $user, $this->task->id)->assertForbidden();
    logManual($this, $user, $this->task->id)->assertForbidden();
});

// ── Standalone and ticket tasks: unchanged ────────────────────────────────────

it('PRESERVED: a standalone task\'s assignee keeps new-time eligibility, and a stranger is denied', function () {
    $assignee = makeUser();
    $standalone = Task::factory()->standalone()->create(['title' => 'Solo', 'assignee_id' => $assignee->id, 'status' => 'todo']);

    startTimer($this, $assignee, $standalone->id)->assertOk();
    startTimer($this, makeUser(), $standalone->id)->assertUnprocessable();
});

it('PRESERVED: a ticket task keeps its rule — the assignee shortcut and the ticket-view fallback both still admit', function () {
    $owner = makeUser();
    $ticket = Ticket::factory()->create(['user_id' => $owner->id]);
    $assignee = makeUser();
    $ticketTask = Task::factory()->standalone()->create(['ticket_id' => $ticket->id, 'assignee_id' => $assignee->id, 'status' => 'todo']);

    expect(AccessibleTimeContext::allows($assignee, 'task', $ticketTask->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows($owner, 'task', $ticketTask->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows(makeUser(), 'task', $ticketTask->id))->toBeFalse();
});

// ── Historical entries, timer stop, billing ───────────────────────────────────

it('PRESERVED (decision (c)): an existing entry keeps its UNCHANGED board task and stays editable after the user leaves the project', function () {
    $user = projectActor('member', $this->project);
    $this->task->update(['assignee_id' => $user->id]);
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'task_id' => $this->task->id, 'date' => today()->toDateString(), 'duration_minutes' => 30]);

    $this->project->members()->detach($user->id);

    $this->actingAs($user->fresh())->put(route('time.update', $entry), [
        'date' => today()->toDateString(), 'hours' => 2, 'description' => 'Edited after leaving', 'task_id' => $this->task->id,
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['task_id', 'duration_minutes', 'description']))
        ->toBe(['task_id' => $this->task->id, 'duration_minutes' => 120, 'description' => 'Edited after leaving']);
});

it('FLIPPED IN WP1 (Q8): after leaving, a departed assignee cannot move an entry onto the board task or add it to a task-less entry', function () {
    $user = staleAssignee($this);
    $other = Task::factory()->standalone()->create(['assignee_id' => $user->id, 'status' => 'todo']);
    $onOther = TimeEntry::factory()->create(['user_id' => $user->id, 'task_id' => $other->id, 'date' => today()->toDateString()]);
    $taskless = TimeEntry::factory()->create(['user_id' => $user->id, 'task_id' => null, 'date' => today()->toDateString()]);

    $this->actingAs($user)->put(route('time.update', $onOther), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $this->task->id,
    ])->assertSessionHasErrors('task_id');
    $this->actingAs($user)->put(route('time.update', $taskless), [
        'date' => today()->toDateString(), 'hours' => 1, 'task_id' => $this->task->id,
    ])->assertSessionHasErrors('task_id');

    expect($onOther->fresh()->task_id)->toBe($other->id)->and($taskless->fresh()->task_id)->toBeNull();
});

it('PRESERVED (INV-P6): a running timer started while eligible can still be stopped after the user leaves the project', function () {
    $user = projectActor('member', $this->project);
    $this->task->update(['assignee_id' => $user->id]);
    $running = TimeEntry::factory()->running()->create(['user_id' => $user->id, 'task_id' => $this->task->id]);

    $this->project->members()->detach($user->id);

    $this->actingAs($user->fresh())->postJson(route('time.timer.stop', $running))->assertOk();
    expect($running->fresh()->isRunning())->toBeFalse()->and($running->fresh()->task_id)->toBe($this->task->id);
});

it('PRESERVED (INV-P7): a billed entry on a board task stays locked after the user leaves the project', function () {
    $user = projectActor('member', $this->project);
    $this->task->update(['assignee_id' => $user->id]);
    $billed = TimeEntry::factory()->billed()->create(['user_id' => $user->id, 'task_id' => $this->task->id, 'date' => today()->toDateString()]);
    $before = $billed->fresh()->toArray();

    $this->project->members()->detach($user->id);

    $this->actingAs($user->fresh())->put(route('time.update', $billed), [
        'date' => today()->toDateString(), 'hours' => 3, 'task_id' => $this->task->id,
    ])->assertSessionHasErrors('time_entry');

    expect($billed->fresh()->toArray())->toBe($before);
});
