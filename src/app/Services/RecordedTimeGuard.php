<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * The one authority for hard-deleting a task or a project while time may reference it
 * (EPIC-011E D4; EPIC-014 §12.1, INV-11/INV-12). Extracted from `ProjectService` unchanged.
 *
 * A delete is refused while any time entry references the row — billed, invoiced, stopped and
 * running alike — and recorded time is never nulled or otherwise touched. The `restrictOnDelete`
 * foreign keys on `time_entries.project_id`/`task_id` are the backstop for the race between the
 * check and the DELETE (a timer started in between) and for any cascade that bypasses it; that
 * violation is reported with the same message. Any other database error propagates unchanged.
 *
 * Callers own their transaction and locking: this class neither opens a transaction nor locks,
 * so board deletes keep `ProjectService`'s column-lock protocol exactly as before.
 *
 * @throws ValidationException on the "delete" key
 */
class RecordedTimeGuard
{
    /** MariaDB/MySQL error 1451: a parent row is referenced by a RESTRICT foreign key. */
    private const FOREIGN_KEY_DELETE_VIOLATION = 1451;

    public function deleteTask(Task $task): void
    {
        if (TimeEntry::where('task_id', $task->id)->exists()) {
            throw $this->recordedTime('task');
        }

        $this->deleteRestricted($task, 'task');
    }

    /** Refused when time references the project directly or through any of its tasks. */
    public function deleteProject(Project $project): void
    {
        $hasTime = TimeEntry::where('project_id', $project->id)
            ->orWhereIn('task_id', Task::where('project_id', $project->id)->select('id'))
            ->exists();

        if ($hasTime) {
            throw $this->recordedTime('project');
        }

        $this->deleteRestricted($project, 'project');
    }

    private function deleteRestricted(Task|Project $model, string $what): void
    {
        try {
            $model->delete();
        } catch (QueryException $e) {
            throw $this->isRestrictViolation($e) ? $this->recordedTime($what) : $e;
        }
    }

    private function recordedTime(string $what): ValidationException
    {
        return ValidationException::withMessages([
            'delete' => "This {$what} has recorded time and cannot be deleted.",
        ]);
    }

    private function isRestrictViolation(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === self::FOREIGN_KEY_DELETE_VIOLATION;
    }
}
