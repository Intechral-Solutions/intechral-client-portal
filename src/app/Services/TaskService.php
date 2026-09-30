<?php

namespace App\Services;

use App\Exceptions\DoneColumnConfigurationException;
use App\Exceptions\UnsupportedTaskOperationException;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The kind-aware task mutation boundary (EPIC-014 §8, §10–§12). One place the Tasks workspace
 * calls to change a task, so the rules for each kind are decided once:
 *
 * - **Board** tasks delegate every ordering, locking and deletion mechanic to `ProjectService`.
 *   Complete/Reopen are `appendToColumn` calls whose "already there" test runs under
 *   ProjectService's own column and task locks. Nothing here re-implements dense positions or
 *   column locks, takes a task-row lock before ProjectService's column locks (that would invert
 *   INV-4's order), or writes `status` for a board task (INV-2).
 * - **Standalone** tasks have no board, so their mutations run here: one transaction, the task
 *   row locked, and the kind re-checked on that locked row before anything is written.
 * - **Ticket** tasks and malformed rows linked to both a project and a ticket (INV-13) are refused
 *   with `UnsupportedTaskOperationException` before anything is written.
 *
 * The kind is always decided from the stored row, never from the caller's model, which may be
 * stale. A board task's kind cannot change after creation (INV-9: no path writes `project_id`),
 * so its unlocked re-read is safe; a standalone task's is repeated under the row lock.
 *
 * Authorization is the caller's (TaskPolicy): the service authorizes nothing and trusts nothing
 * but the task. No time entry is ever read or written here (INV-15).
 */
class TaskService
{
    private const LOCK_ATTEMPTS = 3;

    private const STANDALONE_FIELDS = ['title', 'description', 'priority', 'due_date', 'status', 'assignee_id'];

    public function __construct(
        private ProjectService $projects,
        private RecordedTimeGuard $recordedTime,
    ) {}

    /**
     * Create a standalone task for its creator (Q4). Only the standalone fields are taken, so no
     * caller can introduce a project, ticket, column or creator (INV-9, INV-13).
     */
    public function createStandalone(User $creator, array $data): Task
    {
        return Task::create([
            ...array_intersect_key($data, array_flip(self::STANDALONE_FIELDS)),
            'project_id' => null,
            'ticket_id' => null,
            'column_id' => null,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Edit a standalone task's own fields (§10). `assignee_id` is written only when the caller
     * passes it, and then only if it passes `ensureStandaloneAssignee` on the locked row (§7.3).
     *
     * @throws UnsupportedTaskOperationException for any task that is not standalone
     * @throws ValidationException on `assignee_id` when the value is not allowed on the locked row
     */
    public function updateStandalone(Task $task, array $data, User $actor): void
    {
        $this->onLockedStandalone($task, 'update', function (Task $current) use ($data, $actor) {
            if (array_key_exists('assignee_id', $data)) {
                $this->ensureStandaloneAssignee($current, $data['assignee_id'], $actor);
            }

            $current->update(array_intersect_key($data, array_flip(self::STANDALONE_FIELDS)));
        });
    }

    /**
     * Set or clear the assignee (§7.3). Only the assignee changes: a board task keeps its column
     * and position, so this needs no column lock, only the task row.
     *
     * A standalone target is re-checked against the locked row (`ensureStandaloneAssignee`); a
     * board target's membership rule is the caller's (ProjectTaskAssignee).
     *
     * @throws UnsupportedTaskOperationException for a ticket-kind or malformed task
     * @throws ValidationException on `assignee_id` when a standalone target is not allowed
     */
    public function assign(Task $task, ?int $assigneeId, User $actor): void
    {
        $this->mutableKind($this->stored($task), 'assign');

        DB::transaction(function () use ($task, $assigneeId, $actor) {
            $current = $this->lockRow($task);

            if ($this->mutableKind($current, 'assign') === Task::KIND_STANDALONE) {
                $this->ensureStandaloneAssignee($current, $assigneeId, $actor);
            }

            if ($current->assignee_id !== $assigneeId) {
                $current->update(['assignee_id' => $assigneeId]);
            }
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * Explicit Complete (§8, Q2, P3, P4). Board: append to the tail of the single Done column
     * through ProjectService, a no-op when already there; a task that lost its column rejoins the
     * board in the Done column. Standalone: `status = done`, a no-op when already done.
     *
     * @throws DoneColumnConfigurationException when the board has zero or several Done columns
     * @throws UnsupportedTaskOperationException for a ticket-kind or malformed task
     */
    public function complete(Task $task): void
    {
        $current = $this->stored($task);

        match ($this->mutableKind($current, 'complete')) {
            Task::KIND_BOARD => $this->completeBoard($current),
            Task::KIND_STANDALONE => $this->setStandaloneStatus($current, 'complete', fn (string $status) => $status === 'done' ? null : 'done'),
        };

        $task->refresh();
    }

    /**
     * Explicit Reopen (§8, Q2, P3). Board: append to the tail of the first non-Done column by
     * position, a no-op when the task is not done. Standalone: a done task goes back to `todo`;
     * one that is not done is left as it is (Reopen never demotes `in_progress`).
     *
     * @throws DoneColumnConfigurationException when the board has zero or several Done columns,
     *                                          or no open column
     * @throws UnsupportedTaskOperationException for a ticket-kind or malformed task
     */
    public function reopen(Task $task): void
    {
        $current = $this->stored($task);

        match ($this->mutableKind($current, 'reopen')) {
            Task::KIND_BOARD => $this->reopenBoard($current),
            Task::KIND_STANDALONE => $this->setStandaloneStatus($current, 'reopen', fn (string $status) => $status === 'done' ? 'todo' : null),
        };

        $task->refresh();
    }

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
        $current = $this->stored($task);

        match ($this->mutableKind($current, 'delete')) {
            Task::KIND_BOARD => $this->projects->deleteTask($current),
            Task::KIND_STANDALONE => $this->onLockedStandalone($current, 'delete', fn (Task $locked) => $this->recordedTime->deleteTask($locked)),
        };
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @throws DoneColumnConfigurationException
     * @throws ConflictHttpException when the task kept moving (ProjectService's restart limit)
     */
    private function completeBoard(Task $task): void
    {
        $done = $this->projects->doneColumn($task->project);

        $this->projects->appendToColumn($task, $done->id, fn (Task $locked) => $locked->column_id === $done->id);
    }

    /**
     * @throws DoneColumnConfigurationException
     * @throws ConflictHttpException when the task kept moving (ProjectService's restart limit)
     */
    private function reopenBoard(Task $task): void
    {
        // Done-ness needs the single Done column even to decide a no-op (INV-8), so a misconfigured
        // board refuses Reopen as it refuses Complete.
        $done = $this->projects->doneColumn($task->project);
        $open = $this->projects->firstOpenColumn($task->project);

        $this->projects->appendToColumn($task, $open->id, fn (Task $locked) => $locked->column_id === null
            // A legacy column-less board task is done only by its status (P4 edge).
            ? $locked->status !== 'done'
            : $locked->column_id !== $done->id);
    }

    /** @param  callable(string): ?string  $next  the new status, or null to leave it */
    private function setStandaloneStatus(Task $task, string $operation, callable $next): void
    {
        $this->onLockedStandalone($task, $operation, function (Task $current) use ($next) {
            $status = $next($current->status);

            if ($status !== null) {
                $current->update(['status' => $status]);
            }
        });
    }

    /**
     * Run a standalone mutation in one transaction with the task row locked, after re-checking on
     * that locked row that it is still a standalone task.
     *
     * @param  callable(Task): void  $mutation
     *
     * @throws UnsupportedTaskOperationException when the locked row is not standalone
     */
    private function onLockedStandalone(Task $task, string $operation, callable $mutation): void
    {
        DB::transaction(function () use ($task, $operation, $mutation) {
            $current = $this->lockRow($task);

            if ($this->mutableKind($current, $operation) !== Task::KIND_STANDALONE) {
                throw new UnsupportedTaskOperationException($current->id, $operation, 'a task that is not standalone');
            }

            $mutation($current);
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * The standalone assignment invariant (§7.3, Q4), decided on the LOCKED row: nobody, the
     * acting user, or the row's current assignee (an unchanged value is not a hand-over). The
     * request rule gives fast feedback from the route-bound snapshot, which may be stale; only
     * this check stops a stale "unchanged" value from restoring an assignee who has since left.
     *
     * @throws ValidationException
     */
    private function ensureStandaloneAssignee(Task $locked, mixed $assigneeId, User $actor): void
    {
        if ($assigneeId === null || $assigneeId === '') {
            return;
        }

        if ((int) $assigneeId !== $actor->id && (int) $assigneeId !== $locked->assignee_id) {
            throw ValidationException::withMessages(['assignee_id' => 'The selected assignee is invalid.']);
        }
    }

    /** The task as stored now (unlocked), so a stale caller model never decides the kind. */
    private function stored(Task $task): Task
    {
        return Task::find($task->id) ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
    }

    private function lockRow(Task $task): Task
    {
        return Task::whereKey($task->id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
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
