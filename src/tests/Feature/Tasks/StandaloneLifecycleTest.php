<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 §10, §7.3, Q4 (WP2): the standalone lifecycle and the narrow assign endpoint.
 *
 * - Edit (PUT /tasks/{task}): title, description, priority, due date, status; assignee when sent.
 *   Standalone only: a board task is 404 (its edits stay on projects.tasks.update). project_id and
 *   ticket_id are never accepted (INV-9).
 * - Assignee (PUT /tasks/{task}/assignee): standalone → the actor or null, board → a current
 *   project member or null (ProjectTaskAssignee, the rule projects.tasks.update runs). An unchanged
 *   value is not a new assignment and is kept (the I8 precedent), never a cross-person hand-over.
 * - Delete (DELETE /tasks/{task}): standalone only, through the shared recorded-time guard; any
 *   time entry refuses it with the existing `delete`-key message. Redirects to tasks.index.
 * - Authorization is TaskPolicy: creator or current assignee (Q4); 403 before any kind 404.
 */

const RECORDED_TIME = 'This task has recorded time and cannot be deleted.';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->creator = makeUser();
    $this->assignee = makeUser();
    $this->task = Task::factory()->standalone()->create([
        'created_by' => $this->creator->id, 'assignee_id' => $this->assignee->id,
        'title' => 'Original', 'priority' => 'low', 'status' => 'todo', 'due_date' => null,
    ]);
});

function standaloneEdit(array $overrides = []): array
{
    return ['title' => 'Edited', 'description' => 'Notes', 'priority' => 'high', 'due_date' => '2030-05-01', 'status' => 'in_progress', ...$overrides];
}

// ── Create moves into TaskService, unchanged ─────────────────────────────────

it('still creates a standalone task exactly as before, now through TaskService', function () {
    $user = makeUser();

    $this->actingAs($user)->from('/tasks')->post(route('tasks.store'), [
        'title' => 'Fresh', 'priority' => 'medium', 'status' => 'in_progress', 'assignee_id' => $user->id,
        'project_id' => makeProject()->id, 'ticket_id' => Ticket::factory()->create()->id, 'column_id' => 1, 'created_by' => makeUser()->id,
    ])->assertRedirect('/tasks')->assertSessionHas('success', 'Task created.');

    expect(Task::where('title', 'Fresh')->firstOrFail()->only(['created_by', 'assignee_id', 'project_id', 'ticket_id', 'column_id', 'status', 'priority']))
        ->toBe(['created_by' => $user->id, 'assignee_id' => $user->id, 'project_id' => null, 'ticket_id' => null, 'column_id' => null, 'status' => 'in_progress', 'priority' => 'medium']);
});

// ── Edit ─────────────────────────────────────────────────────────────────────

it('lets the creator and the current assignee edit the standalone fields', function (string $who) {
    $user = $who === 'creator' ? $this->creator : $this->assignee;

    $this->actingAs($user)->from('/tasks')->put(route('tasks.update', $this->task), standaloneEdit())
        ->assertRedirect('/tasks')->assertSessionHasNoErrors()->assertSessionHas('success', 'Task updated.');

    expect($this->task->fresh()->only(['title', 'description', 'priority', 'status', 'assignee_id']))->toBe([
        'title' => 'Edited', 'description' => 'Notes', 'priority' => 'high', 'status' => 'in_progress',
        'assignee_id' => $this->assignee->id,   // not sent: unchanged
    ])->and($this->task->fresh()->due_date->toDateString())->toBe('2030-05-01');
})->with(['creator', 'assignee']);

it('denies editing to a stranger, an operator and a project manager, changing nothing', function (string $who) {
    $user = match ($who) {
        'stranger' => makeUser(),
        'operator' => makeUser('operator'),
        'project_manager' => projectActor('project_manager', makeProject()),
    };
    $before = $this->task->fresh()->toArray();

    $this->actingAs($user)->put(route('tasks.update', $this->task), standaloneEdit())->assertForbidden();

    expect($this->task->fresh()->toArray())->toBe($before);
})->with(['stranger', 'operator', 'project_manager']);

it('validates the standalone fields as create does', function () {
    $this->actingAs($this->creator)->put(route('tasks.update', $this->task), [
        'title' => '', 'priority' => 'urgent', 'status' => 'blocked', 'due_date' => 'not a date',
    ])->assertSessionHasErrors(['title', 'priority', 'status', 'due_date']);

    expect($this->task->fresh()->title)->toBe('Original');
});

it('never accepts project_id, ticket_id or a column: a standalone task cannot change kind (INV-9)', function () {
    $project = makeProject();

    $this->actingAs($this->creator)->put(route('tasks.update', $this->task), standaloneEdit([
        'project_id' => $project->id, 'ticket_id' => Ticket::factory()->create()->id,
        'column_id' => $project->columns[0]->id, 'position' => 3, 'created_by' => makeUser()->id,
    ]))->assertSessionHasNoErrors();

    expect($this->task->fresh()->only(['project_id', 'ticket_id', 'column_id', 'position', 'created_by']))
        ->toBe(['project_id' => null, 'ticket_id' => null, 'column_id' => null, 'position' => $this->task->position, 'created_by' => $this->creator->id])
        ->and($this->task->fresh()->kind())->toBe(Task::KIND_STANDALONE);
});

it('answers a board task on tasks.update with 404 for its manager and 403 for anyone else', function () {
    $project = makeProject();
    $board = makeTask($project->columns[1], ['title' => 'Board']);
    $manager = projectActor('project_manager', $project);

    $this->actingAs($manager)->put(route('tasks.update', $board), standaloneEdit())->assertNotFound();
    $this->actingAs(makeUser())->put(route('tasks.update', $board), standaloneEdit())->assertForbidden();

    expect($board->fresh()->title)->toBe('Board');
});

it('refuses a ticket-kind task on tasks.update with 403, even to its owner', function () {
    $owner = makeUser();
    $ticketTask = Task::factory()->standalone()->create([
        'ticket_id' => Ticket::factory()->create(['user_id' => $owner->id])->id, 'created_by' => $owner->id, 'title' => 'Ticket task',
    ]);

    $this->actingAs($owner)->put(route('tasks.update', $ticketTask), standaloneEdit())->assertForbidden();

    expect($ticketTask->fresh()->title)->toBe('Ticket task');
});

// ── Assignment (§7.3): through edit and through the narrow endpoint ─────────

it('lets the creator take the task and release it again, through either route', function (string $route) {
    $task = Task::factory()->standalone()->create(['created_by' => $this->creator->id, 'assignee_id' => null]);
    $send = fn (?int $id) => $route === 'tasks.update'
        ? $this->actingAs($this->creator)->put(route('tasks.update', $task), standaloneEdit(['assignee_id' => $id]))
        : $this->actingAs($this->creator)->put(route('tasks.assignee.update', $task), ['assignee_id' => $id]);

    $send($this->creator->id)->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBe($this->creator->id);

    $send(null)->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBeNull();
})->with(['tasks.update', 'tasks.assignee.update']);

it('lets the current assignee release the task back to its creator', function () {
    $this->actingAs($this->assignee)->put(route('tasks.assignee.update', $this->task), ['assignee_id' => null])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Assignee updated.');

    expect($this->task->fresh()->assignee_id)->toBeNull();
    // Released: the former assignee no longer owns it (Q4), the creator still does.
    $this->actingAs($this->assignee)->put(route('tasks.complete', $this->task))->assertForbidden();
    $this->actingAs($this->creator)->put(route('tasks.complete', $this->task))->assertRedirect();
});

it('never hands a standalone task to another person (no cross-person assignment)', function (string $route) {
    $third = makeUser();
    $send = fn (User $actor) => $route === 'tasks.update'
        ? $this->actingAs($actor)->put(route('tasks.update', $this->task), standaloneEdit(['assignee_id' => $third->id]))
        : $this->actingAs($actor)->put(route('tasks.assignee.update', $this->task), ['assignee_id' => $third->id]);

    $send($this->creator)->assertSessionHasErrors('assignee_id');
    $send($this->assignee)->assertSessionHasErrors('assignee_id');
    // The creator cannot take it from the current assignee through edit either, except by
    // naming themselves, which is taking, not handing over.
    expect($this->task->fresh()->assignee_id)->toBe($this->assignee->id);
})->with(['tasks.update', 'tasks.assignee.update']);

it('keeps an unchanged assignee when the creator edits a task someone else holds', function () {
    $this->actingAs($this->creator)->put(route('tasks.update', $this->task), standaloneEdit(['assignee_id' => $this->assignee->id]))
        ->assertSessionHasNoErrors();

    expect($this->task->fresh()->only(['title', 'assignee_id']))->toBe(['title' => 'Edited', 'assignee_id' => $this->assignee->id]);
});

// The "unchanged assignee" allowance is judged twice: the request rule (fast feedback, from the
// route-bound snapshot) and TaskService, on the LOCKED row. Only the second can be trusted.

/**
 * Release the task just after route-model binding has hydrated the (now stale) snapshot the
 * request rule will judge, and before TaskService's locked write. Binding is the one plain
 * `where `id` = ?` read of the task (the service's own reads are `tasks`.`id` or `for update`).
 */
function releaseBeforeLockedWrite(int $taskId): void
{
    DB::listen(function ($query) use ($taskId) {
        if ($query->sql === 'select * from `tasks` where `id` = ? limit 1') {
            DB::table('tasks')->where('id', $taskId)->update(['assignee_id' => null]);
        }
    });
}

it('does not let a stale "unchanged" assignee restore someone who released the task (edit)', function () {
    releaseBeforeLockedWrite($this->task->id);

    $this->actingAs($this->creator)->from('/tasks')->put(route('tasks.update', $this->task), standaloneEdit(['assignee_id' => $this->assignee->id]))
        ->assertRedirect('/tasks')
        ->assertSessionHasErrors(['assignee_id' => 'The selected assignee is invalid.']);

    expect($this->task->fresh()->only(['assignee_id', 'title']))->toBe(['assignee_id' => null, 'title' => 'Original']);
});

it('does not let a stale "unchanged" assignee restore someone who released the task (narrow endpoint)', function () {
    releaseBeforeLockedWrite($this->task->id);

    $this->actingAs($this->creator)->from('/tasks')->put(route('tasks.assignee.update', $this->task), ['assignee_id' => $this->assignee->id])
        ->assertRedirect('/tasks')
        ->assertSessionHasErrors(['assignee_id' => 'The selected assignee is invalid.']);

    expect($this->task->fresh()->assignee_id)->toBeNull();
});

it('checks the locked row in TaskService itself: self, release and the locked assignee pass; anyone else or a stale assignee does not', function () {
    $service = app(TaskService::class);
    $third = makeUser();
    $stale = Task::find($this->task->id);   // assignee: $this->assignee

    // The locked row now says the task is held by the creator.
    Task::whereKey($this->task->id)->update(['assignee_id' => $this->creator->id]);

    expect(fn () => $service->updateStandalone($stale, standaloneEdit(['assignee_id' => $this->assignee->id]), $this->creator))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->assign($stale, $third->id, $this->creator))->toThrow(ValidationException::class);
    expect($this->task->fresh()->assignee_id)->toBe($this->creator->id);

    $service->assign($stale, $this->creator->id, $this->creator);                       // the locked assignee, unchanged
    $service->updateStandalone($stale, standaloneEdit(['assignee_id' => $this->creator->id]), $this->assignee);   // the locked assignee, whoever acts
    $service->assign($stale, $this->assignee->id, $this->assignee);                     // self
    expect($this->task->fresh()->assignee_id)->toBe($this->assignee->id);

    $service->assign($stale, null, $this->creator);                                     // release
    expect($this->task->fresh()->assignee_id)->toBeNull();
});

it('requires assignee_id on the narrow endpoint and denies strangers', function () {
    $this->actingAs($this->creator)->put(route('tasks.assignee.update', $this->task), [])->assertSessionHasErrors('assignee_id');
    $this->actingAs(makeUser())->put(route('tasks.assignee.update', $this->task), ['assignee_id' => null])->assertForbidden();

    expect($this->task->fresh()->assignee_id)->toBe($this->assignee->id);
});

it('assigns a board task through the endpoint for a manager, with the shared member rule (INV-6, INV-7)', function () {
    $project = makeProject();
    $manager = projectActor('project_manager', $project);
    $member = projectActor('member', $project);
    $departed = projectActor('member', $project);
    $task = makeTask($project->columns[1], ['assignee_id' => $departed->id]);
    $project->members()->detach($departed->id);

    // Unchanged departed assignee: kept (I8).
    $this->actingAs($manager)->put(route('tasks.assignee.update', $task), ['assignee_id' => $departed->id])->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBe($departed->id);

    // A non-member: refused with the rule's message, nothing changes.
    $this->actingAs($manager)->put(route('tasks.assignee.update', $task), ['assignee_id' => makeUser()->id])
        ->assertSessionHasErrors(['assignee_id' => 'The selected assignee is invalid.']);
    expect($task->fresh()->assignee_id)->toBe($departed->id);

    // A current member, then nobody.
    $this->actingAs($manager)->put(route('tasks.assignee.update', $task), ['assignee_id' => $member->id])->assertSessionHasNoErrors();
    expect($task->fresh()->assignee_id)->toBe($member->id);
    $this->actingAs($manager)->put(route('tasks.assignee.update', $task), ['assignee_id' => null])->assertSessionHasNoErrors();
    expect($task->fresh()->only(['assignee_id', 'column_id', 'position']))
        ->toBe(['assignee_id' => null, 'column_id' => $project->columns[1]->id, 'position' => $task->position]);
});

it('denies board assignment to anyone without manage, the member-assignee included', function (string $actor) {
    $project = makeProject();
    $member = projectActor('member', $project);
    $task = makeTask($project->columns[1], ['assignee_id' => $member->id]);
    $user = match ($actor) {
        'member_assignee' => $member,
        'plain_member' => projectActor('member', $project),
        'outsider' => makeUser(),
    };

    $this->actingAs($user)->put(route('tasks.assignee.update', $task), ['assignee_id' => null])->assertForbidden();

    expect($task->fresh()->assignee_id)->toBe($member->id);
})->with(['member_assignee', 'plain_member', 'outsider']);

it('refuses assignment on ticket-kind and dual-linked rows with 403', function () {
    $owner = makeUser();
    $ticketTask = Task::factory()->standalone()->create([
        'ticket_id' => Ticket::factory()->create(['user_id' => $owner->id])->id, 'created_by' => $owner->id, 'assignee_id' => null,
    ]);
    $project = makeProject();
    $manager = projectActor('project_manager', $project);
    $dual = makeTask($project->columns[1], ['ticket_id' => Ticket::factory()->create()->id]);

    $this->actingAs($owner)->put(route('tasks.assignee.update', $ticketTask), ['assignee_id' => $owner->id])->assertForbidden();
    $this->actingAs($manager)->put(route('tasks.assignee.update', $dual), ['assignee_id' => $manager->id])->assertForbidden();

    expect($ticketTask->fresh()->assignee_id)->toBeNull()->and($dual->fresh()->assignee_id)->toBeNull();
});

// ── Delete (§12.1) ───────────────────────────────────────────────────────────

it('lets the creator or the assignee delete an unreferenced standalone task, redirecting to the list', function (string $who) {
    $user = $who === 'creator' ? $this->creator : $this->assignee;

    $this->actingAs($user)->from(route('tasks.index'))->delete(route('tasks.destroy', $this->task))
        ->assertRedirect(route('tasks.index'))->assertSessionHas('success', 'Task deleted.');

    expect(Task::find($this->task->id))->toBeNull();
})->with(['creator', 'assignee']);

it('refuses to delete a standalone task with billed, invoiced, stopped or running time and touches none of it', function (string $state) {
    $factory = TimeEntry::factory()->state(['user_id' => $this->assignee->id, 'task_id' => $this->task->id]);
    $entry = $state === 'stopped' ? $factory->create() : $factory->{$state}()->create();
    $before = $entry->fresh()->toArray();

    $this->actingAs($this->creator)->from('/tasks')->delete(route('tasks.destroy', $this->task))
        ->assertRedirect('/tasks')
        ->assertSessionHasErrors(['delete' => RECORDED_TIME]);

    expect(Task::find($this->task->id))->not->toBeNull()
        ->and($entry->fresh()->toArray())->toBe($before);
})->with(['billed', 'invoiced', 'stopped', 'running']);

it('cascades only what the schema cascades: dependency links go, nothing else is cleaned up by hand', function () {
    $other = Task::factory()->standalone()->create(['created_by' => $this->creator->id]);
    DB::table('task_dependencies')->insert(['task_id' => $other->id, 'depends_on_task_id' => $this->task->id]);
    $untouched = Task::count() - 1;

    $this->actingAs($this->creator)->delete(route('tasks.destroy', $this->task))->assertRedirect(route('tasks.index'));

    expect(DB::table('task_dependencies')->count())->toBe(0)
        ->and(Task::count())->toBe($untouched)
        ->and(Task::find($other->id))->not->toBeNull();
});

it('keeps checklist and comment rows cascading with the task, as the schema says (no second cleanup system)', function () {
    // Standalone tasks have no checklist or comments in the product (§10); legacy rows, if any,
    // go with the task through the existing ON DELETE CASCADE keys.
    TaskChecklistItem::factory()->create(['task_id' => $this->task->id]);
    TaskComment::factory()->create(['task_id' => $this->task->id]);

    $this->actingAs($this->creator)->delete(route('tasks.destroy', $this->task))->assertRedirect(route('tasks.index'));

    expect(TaskChecklistItem::where('task_id', $this->task->id)->exists())->toBeFalse()
        ->and(TaskComment::where('task_id', $this->task->id)->exists())->toBeFalse();
});

it('denies deleting to a stranger and an operator', function (string $who) {
    $user = $who === 'operator' ? makeUser('operator') : makeUser();

    $this->actingAs($user)->delete(route('tasks.destroy', $this->task))->assertForbidden();

    expect(Task::find($this->task->id))->not->toBeNull();
})->with(['stranger', 'operator']);

it('answers a board task on tasks.destroy with 404 for its manager, leaving it on the board', function () {
    $project = makeProject();
    $board = makeTask($project->columns[1]);

    $this->actingAs(projectActor('project_manager', $project))->delete(route('tasks.destroy', $board))->assertNotFound();
    $this->actingAs(makeUser())->delete(route('tasks.destroy', $board))->assertForbidden();

    expect(Task::find($board->id))->not->toBeNull()
        ->and(Project::find($project->id))->not->toBeNull();
});

it('refuses deleting a ticket-kind task with 403, even to its owner', function () {
    $owner = makeUser();
    $ticketTask = Task::factory()->standalone()->create([
        'ticket_id' => Ticket::factory()->create(['user_id' => $owner->id])->id, 'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)->delete(route('tasks.destroy', $ticketTask))->assertForbidden();

    expect(Task::find($ticketTask->id))->not->toBeNull();
});
