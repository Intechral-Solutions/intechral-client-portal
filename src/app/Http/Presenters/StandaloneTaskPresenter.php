<?php

namespace App\Http\Presenters;

use App\Models\Task;

/**
 * The standalone task detail DTO (EPIC-014 §13.3, WP5). A standalone task has a title, notes,
 * priority, due date, status and an assignee, and nothing else: no project, milestone, column,
 * checklist or comments, so none is modelled, rather than sent empty for the page to hide. No
 * email, creator id or raw attribute is ever included (INV-17). `$task->assignee` must be loaded.
 */
final class StandaloneTaskPresenter
{
    /**
     * @return array{id: int, title: string, description: string|null, priority: string, dueDate: string|null, overdue: bool, status: array{label: string, done: bool, source: string}, statusValue: string, assignee: array{id: int, name: string}|null}
     */
    public static function detail(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->isOverdue(),
            'status' => TaskStatusPresenter::forTask($task),
            // The editable value, for the edit form's status field only. A standalone task's status
            // is its own field (WP2 permits editing it); the display state is `status` above.
            'statusValue' => $task->status,
            'assignee' => ProjectTaskPresenter::userRef($task->assignee),
        ];
    }
}
