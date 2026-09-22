<?php

namespace App\Http\Presenters;

use App\Models\Task;

/**
 * The shared status DTO (EPIC-011E §5, §15): `label` and `done` mirror
 * `Task::effectiveStatus()`/`Task::isDone()` exactly, and `source` records which one is
 * authoritative for this task's kind, so a consumer never re-derives the column-first rule
 * itself and React never reads or compares a raw `status` string.
 */
final class TaskStatusPresenter
{
    /**
     * @return array{label: string, done: bool, source: 'column'|'status'}
     */
    public static function forTask(Task $task): array
    {
        return [
            'label' => $task->effectiveStatus(),
            'done' => $task->isDone(),
            'source' => $task->column_id !== null ? 'column' : 'status',
        ];
    }
}
