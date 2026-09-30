<?php

namespace App\Services;

use App\Exceptions\DoneColumnConfigurationException;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProjectService
{
    /** Default columns created with every new project */
    private const DEFAULT_COLUMNS = [
        ['name' => 'Backlog',     'position' => 0, 'is_done_column' => false],
        ['name' => 'To Do',       'position' => 1, 'is_done_column' => false],
        ['name' => 'In Progress', 'position' => 2, 'is_done_column' => false],
        ['name' => 'In Review',   'position' => 3, 'is_done_column' => false],
        ['name' => 'Done',        'position' => 4, 'is_done_column' => true],
    ];

    /** How often a transaction is retried when the database picks it as a deadlock victim. */
    private const LOCK_ATTEMPTS = 3;

    /** How often a move/delete starts over because the task changed column while it waited. */
    private const RESTART_ATTEMPTS = 5;

    public function __construct(private RecordedTimeGuard $recordedTime) {}

    public function create(User $creator, array $data): Project
    {
        $project = Project::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $creator->id,
            'start_date' => $data['start_date'] ?? null,
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? 'active',
            'budget' => $data['budget'] ?? null,
        ]);

        // Seed default columns
        foreach (self::DEFAULT_COLUMNS as $col) {
            $project->columns()->create($col);
        }

        // Add creator as manager
        $project->members()->attach($creator->id, ['role' => 'manager']);

        return $project;
    }

    public function syncMembers(Project $project, array $memberData): void
    {
        // $memberData = [['user_id' => X, 'role' => 'member|manager'], ...]
        $sync = collect($memberData)->mapWithKeys(fn ($m) => [
            $m['user_id'] => ['role' => $m['role'] ?? 'member'],
        ])->all();

        $project->members()->sync($sync);

        // Ensure creator always stays as manager
        $project->members()->syncWithoutDetaching([
            $project->created_by => ['role' => 'manager'],
        ]);
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    /**
     * Append a task to the end of a column. The column row is the mutex for everything that
     * changes a column's order, so the new position is the true tail even when legacy rows
     * left gaps or duplicates (those are normalised while the lock is held).
     */
    public function createTask(Project $project, User $creator, array $data): Task
    {
        return DB::transaction(function () use ($project, $creator, $data) {
            $column = ProjectColumn::query()
                ->whereKey($data['column_id'])
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if ($column === null) {
                throw ValidationException::withMessages(['column_id' => 'The selected column is invalid.']);
            }

            $positions = $this->columnPositions($column->id);
            $this->writeOrder($column->id, array_keys($positions), $positions);

            return $project->tasks()->create([
                ...array_intersect_key($data, array_flip([
                    'column_id', 'title', 'description', 'assignee_id', 'milestone_id', 'priority', 'due_date',
                ])),
                'project_id' => $project->id,
                'created_by' => $creator->id,
                'position' => count($positions),
                'status' => 'todo',
            ]);
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * Move a task to a column and index (0-based, clamped to the target's length). One
     * transaction: the source and target column rows are locked in ascending id order, both
     * lists are read in (position, id) order, spliced, and rewritten densely as 0..n-1. That
     * also repairs whatever gaps or duplicates older code left behind.
     *
     * Locks are only ever taken in one order: column rows (ascending id), then the task row.
     * The task's current column has to be read before that, so it can be stale; it is re-read
     * under the lock and the whole attempt starts over when it changed, rather than locking
     * another column out of order (which would invite a deadlock).
     *
     * @throws ModelNotFoundException when the task or the target column no longer exists
     * @throws ValidationException when the column belongs to another project
     */
    public function moveTask(Task $task, int $targetColumnId, int $position): void
    {
        $this->untilStable(function () use ($task, $targetColumnId, $position) {
            $sourceId = $this->currentColumnId($task);

            return DB::transaction(function () use ($task, $sourceId, $targetColumnId, $position) {
                $locked = $this->lockColumns(array_filter([$sourceId, $targetColumnId]));
                $target = $locked[$targetColumnId] ?? throw (new ModelNotFoundException)->setModel(ProjectColumn::class, [$targetColumnId]);

                if ($target->project_id !== $task->project_id) {
                    throw ValidationException::withMessages(['column_id' => 'The selected column is invalid.']);
                }

                $current = Task::whereKey($task->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

                if ($current->column_id !== $sourceId) {
                    return false; // moved by someone else while this one waited: start over
                }

                // Everything below runs after the locks, so these reads see the latest committed
                // state (the snapshot only starts with the first plain read, which is this one).
                $targetPositions = $this->columnPositions($targetColumnId);

                if ($sourceId === $targetColumnId) {
                    $ids = array_values(array_diff(array_keys($targetPositions), [$task->id]));
                    array_splice($ids, max(0, min($position, count($ids))), 0, [$task->id]);
                    $this->writeOrder($targetColumnId, $ids, $targetPositions, $task->id, $sourceId);
                } else {
                    if ($sourceId !== null) {
                        $sourcePositions = $this->columnPositions($sourceId);
                        unset($sourcePositions[$task->id]);
                        $this->writeOrder($sourceId, array_keys($sourcePositions), $sourcePositions);
                    }

                    $ids = array_keys($targetPositions);
                    array_splice($ids, max(0, min($position, count($ids))), 0, [$task->id]);
                    $this->writeOrder($targetColumnId, $ids, $targetPositions, $task->id, $sourceId);
                }

                return true;
            }, self::LOCK_ATTEMPTS);
        });

        $task->refresh();
    }

    /**
     * Delete a task and close the gap it leaves. Refused while any time entry references it
     * (D4); the foreign key is the backstop when a timer starts between the check and the
     * DELETE, and that violation is reported with the same message. Both rules live in
     * `RecordedTimeGuard`, which runs here under the column and task locks.
     *
     * @throws ValidationException on the "delete" key
     */
    public function deleteTask(Task $task): void
    {
        $this->untilStable(function () use ($task) {
            $columnId = $this->currentColumnId($task);

            return DB::transaction(function () use ($task, $columnId) {
                $this->lockColumns(array_filter([$columnId]));

                $current = Task::whereKey($task->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

                if ($current->column_id !== $columnId) {
                    return false;
                }

                $this->recordedTime->deleteTask($current);

                if ($columnId !== null) {
                    $positions = $this->columnPositions($columnId);
                    $this->writeOrder($columnId, array_keys($positions), $positions);
                }

                return true;
            }, self::LOCK_ATTEMPTS);
        });
    }

    /**
     * Delete a project. Refused while any time entry references the project or one of its
     * tasks (D4), for billed, invoiced, stopped and running entries alike (`RecordedTimeGuard`).
     *
     * @throws ValidationException on the "delete" key
     */
    public function deleteProject(Project $project): void
    {
        DB::transaction(fn () => $this->recordedTime->deleteProject($project), self::LOCK_ATTEMPTS);
    }

    /**
     * The project's single designated Done column (EPIC-014 Q2, INV-8): the destination of an
     * explicit Complete. Exactly one `is_done_column` is required; zero or several is a board
     * configuration error, reported rather than resolved by guessing (never the last column,
     * never a name match, never `tasks.status`). Read-only.
     *
     * @throws DoneColumnConfigurationException
     */
    public function doneColumn(Project $project): ProjectColumn
    {
        $columns = ProjectColumn::query()
            ->where('project_id', $project->id)
            ->where('is_done_column', true)
            ->get();

        if ($columns->count() !== 1) {
            throw new DoneColumnConfigurationException($project->id, $columns->count());
        }

        return $columns->first();
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Run an attempt until it reports success. An attempt returns false when it noticed, under
     * its locks, that the task had moved since it looked; it then starts over from a fresh read.
     *
     * @param  callable(): bool  $attempt
     */
    private function untilStable(callable $attempt): void
    {
        for ($tries = 0; $tries < self::RESTART_ATTEMPTS; $tries++) {
            if ($attempt()) {
                return;
            }
        }

        throw new ConflictHttpException('The task kept moving while this request was processed. Please try again.');
    }

    /**
     * The task's column right now, read without a lock and outside any transaction of its own
     * (a plain read inside the locked section would pin an older snapshot). May be stale by the
     * time the locks are held; the caller re-checks.
     *
     * @throws ModelNotFoundException when the task no longer exists
     */
    private function currentColumnId(Task $task): ?int
    {
        $row = Task::whereKey($task->id)->first(['id', 'column_id'])
            ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

        return $row->column_id;
    }

    /**
     * Lock column rows in ascending id order, the one order every writer uses.
     *
     * @param  array<int, int>  $ids
     * @return array<int, ProjectColumn> keyed by id
     */
    private function lockColumns(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return ProjectColumn::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** @return array<int, int> task id => stored position, in board order (position, id) */
    private function columnPositions(int $columnId): array
    {
        return Task::where('column_id', $columnId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('position', 'id')
            ->map(fn ($position) => (int) $position)
            ->all();
    }

    /**
     * Make a column read exactly $orderedIds as positions 0..n-1, writing only the rows whose
     * position differs. The moved task, if any, also takes the column and a fresh updated_at.
     *
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $current  task id => stored position (before the change)
     * @param  int|null  $movedFromColumnId  the column the moved task was in (under the lock)
     */
    private function writeOrder(int $columnId, array $orderedIds, array $current, ?int $movedId = null, ?int $movedFromColumnId = null): void
    {
        $changes = [];
        foreach ($orderedIds as $index => $id) {
            if ($id !== $movedId && ($current[$id] ?? null) !== $index) {
                $changes[$id] = $index;
            }
        }

        foreach (array_chunk($changes, 200, true) as $chunk) {
            $cases = str_repeat(' WHEN ? THEN ?', count($chunk));
            $bindings = [];
            foreach ($chunk as $id => $index) {
                array_push($bindings, $id, $index);
            }
            DB::update(
                "UPDATE `tasks` SET `position` = CASE `id`{$cases} END WHERE `id` IN (".implode(',', array_fill(0, count($chunk), '?')).')',
                [...$bindings, ...array_keys($chunk)],
            );
        }

        if ($movedId !== null) {
            $index = array_search($movedId, $orderedIds, true);

            if (($current[$movedId] ?? null) !== $index || $movedFromColumnId !== $columnId) {
                Task::whereKey($movedId)->update([
                    'column_id' => $columnId,
                    'position' => $index,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
