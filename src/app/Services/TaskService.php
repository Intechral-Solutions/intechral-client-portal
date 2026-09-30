<?php

namespace App\Services;

use App\Exceptions\UnsupportedTaskOperationException;
use App\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The kind-aware task mutation boundary (EPIC-014 §8, §10–§12). One place later packages call to
 * change a task, so the rules for each kind are decided once:
 *
 * - **Board** tasks delegate every ordering, locking and deletion mechanic to `ProjectService`.
 *   Nothing here re-implements dense positions or column locks, and nothing writes `status` for
 *   a column-backed task.
 * - **Standalone** tasks have no board, so their mutations run here, through the same shared
 *   rules (`RecordedTimeGuard` for delete).
 * - **Ticket** tasks and malformed rows linked to both a project and a ticket (INV-13) are refused
 *   with `UnsupportedTaskOperationException` before anything is read under lock or written.
 *
 * Authorization is the caller's (TaskPolicy): the service authorizes nothing and trusts nothing
 * but the task. WP1 ships only `delete`, with no route; Complete/Reopen, update, assign and the
 * standalone create move here in WP2.
 */
class TaskService
{
    private const LOCK_ATTEMPTS = 3;

    public function __construct(
        private ProjectService $projects,
        private RecordedTimeGuard $recordedTime,
    ) {}

    /**
     * Hard-delete a task. Refused while any time entry references it (INV-11), with the
     * database RESTRICT key as the backstop (INV-12).
     *
     * @throws ValidationException on the "delete" key when time is recorded
     * @throws ModelNotFoundException when the task no longer exists
     * @throws UnsupportedTaskOperationException for a ticket-kind or malformed task
     */
    public function delete(Task $task): void
    {
        match ($this->mutableKind($task, 'delete')) {
            Task::KIND_BOARD => $this->projects->deleteTask($task),
            Task::KIND_STANDALONE => $this->deleteStandalone($task),
        };
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function deleteStandalone(Task $task): void
    {
        DB::transaction(function () use ($task) {
            $current = Task::whereKey($task->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

            $this->recordedTime->deleteTask($current);
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * The kind this service may mutate: board or standalone. Everything else is refused here,
     * so no operation can partially apply to a row it does not support.
     *
     * @return Task::KIND_BOARD|Task::KIND_STANDALONE
     */
    private function mutableKind(Task $task, string $operation): string
    {
        if ($task->project_id !== null && $task->ticket_id !== null) {
            throw new UnsupportedTaskOperationException($task->id, $operation, 'a task linked to both a project and a ticket');
        }

        $kind = $task->kind();

        if ($kind === Task::KIND_TICKET) {
            throw new UnsupportedTaskOperationException($task->id, $operation, 'a ticket task');
        }

        return $kind;
    }
}
