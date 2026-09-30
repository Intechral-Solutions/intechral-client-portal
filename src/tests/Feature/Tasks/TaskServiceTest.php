<?php

use App\Exceptions\UnsupportedTaskOperationException;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-014 WP1 TaskService seam (§12.1, §11, INV-11/INV-12/INV-13). WP1 gives the service one
 * operation, delete, so the shared recorded-time guard has both of its consumers: the board path
 * delegates to ProjectService (which keeps its column locks and dense positions) and the
 * standalone path runs the same guard under a task-row lock. No route calls it yet. Ticket-kind
 * and malformed rows are refused before anything is touched.
 */

const TASK_TIME = 'This task has recorded time and cannot be deleted.';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->service = app(TaskService::class);
    $this->project = makeProject();
    $this->todo = $this->project->columns()->where('position', 1)->first();
    $this->worker = makeUser();
});

function expectRecordedTimeRefusal(callable $delete): void
{
    try {
        $delete();
        test()->fail('Expected the recorded-time refusal.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['delete' => [TASK_TIME]]);
    }
}

// ── Board: delegated to ProjectService ───────────────────────────────────────

it('deletes a board task through ProjectService, closing the gap densely', function () {
    [$a, $b, $c] = [makeTask($this->todo, ['position' => 0]), makeTask($this->todo, ['position' => 1]), makeTask($this->todo, ['position' => 2])];

    $this->service->delete($b);

    expect(Task::find($b->id))->toBeNull()
        ->and(columnOrder($this->todo))->toBe([$a->id, $c->id])
        ->and(columnIsDense($this->todo))->toBeTrue();
});

it('refuses a board task with recorded time exactly as the project route does, leaving the entry untouched', function () {
    $task = makeTask($this->todo);
    $entry = TimeEntry::factory()->billed()->create(['user_id' => $this->worker->id, 'task_id' => $task->id]);
    $before = $entry->fresh()->toArray();

    expectRecordedTimeRefusal(fn () => $this->service->delete($task));

    expect(Task::find($task->id))->not->toBeNull()
        ->and($entry->fresh()->toArray())->toBe($before);
});

// ── Standalone: the same guard under a task-row lock ─────────────────────────

it('deletes an unreferenced standalone task', function () {
    $task = Task::factory()->standalone()->create();

    $this->service->delete($task);

    expect(Task::find($task->id))->toBeNull();
});

it('refuses a standalone task with billed, invoiced, unbilled or running time and touches none of it', function (string $kind) {
    $task = Task::factory()->standalone()->create();
    $factory = TimeEntry::factory()->state(['user_id' => $this->worker->id, 'task_id' => $task->id]);
    $entry = match ($kind) {
        'billed' => $factory->billed()->create(),
        'invoiced' => $factory->invoiced()->create(),
        'unbilled' => $factory->create(),
        'running' => $factory->running()->create(),
    };
    $before = $entry->fresh()->toArray();

    expectRecordedTimeRefusal(fn () => $this->service->delete($task));

    expect(Task::find($task->id))->not->toBeNull()
        ->and($entry->fresh()->toArray())->toBe($before);
})->with(['billed', 'invoiced', 'unbilled', 'running']);

it('maps a foreign-key violation from a race on a standalone task to the same message', function () {
    // A timer started between the guard's check and the DELETE (the ProjectDeletionGuardTest
    // technique): the model event fires after the check, inside the delete transaction.
    $task = Task::factory()->standalone()->create();
    Task::deleting(function (Task $deleting) {
        TimeEntry::factory()->create(['user_id' => $this->worker->id, 'task_id' => $deleting->id]);
    });

    expectRecordedTimeRefusal(fn () => $this->service->delete($task));

    expect(Task::find($task->id))->not->toBeNull();
});

it('reports a standalone task that no longer exists as not found', function () {
    $task = Task::factory()->standalone()->create();
    Task::whereKey($task->id)->delete();

    expect(fn () => $this->service->delete($task))->toThrow(ModelNotFoundException::class);
});

// ── Ticket-kind and malformed rows: refused, nothing touched ─────────────────

it('refuses to delete a ticket-kind task and changes nothing', function () {
    $ticket = Ticket::factory()->create();
    $task = Task::factory()->standalone()->create(['ticket_id' => $ticket->id]);
    $before = $task->fresh()->toArray();

    try {
        $this->service->delete($task);
        $this->fail('Expected the unsupported-kind refusal.');
    } catch (UnsupportedTaskOperationException $e) {
        expect($e->taskId)->toBe($task->id)
            ->and($e->operation)->toBe('delete')
            ->and($e->getMessage())->toContain('ticket');
    }

    expect($task->fresh()->toArray())->toBe($before);
});

it('refuses to delete a row linked to both a project and a ticket and changes nothing', function () {
    $ticket = Ticket::factory()->create();
    [$a, $dual] = [makeTask($this->todo, ['position' => 0]), makeTask($this->todo, ['position' => 1, 'ticket_id' => $ticket->id])];
    $positions = columnPositions($this->todo);

    expect(fn () => $this->service->delete($dual))->toThrow(UnsupportedTaskOperationException::class);

    expect(Task::find($dual->id))->not->toBeNull()
        ->and(columnPositions($this->todo))->toBe($positions);
});
