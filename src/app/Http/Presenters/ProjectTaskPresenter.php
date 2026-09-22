<?php

namespace App\Http\Presenters;

use App\Models\ProjectMilestone;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\User;

/**
 * Page DTOs for the task detail page (EPIC-011E §5, §11, WP7). Every method returns plain
 * scalars/arrays; no raw model, email, or field the page does not render is ever serialized.
 * The caller (`ProjectTaskController::show`) is responsible for eager loading so building these
 * DTOs never queries per row.
 */
final class ProjectTaskPresenter
{
    /**
     * `$task->column`, `$task->assignee`, and `$task->milestone` must already be loaded.
     * `$assigneeIsMember` is computed by the caller against the route's own project (I8): a
     * departed assignee stays visible on the task, but a presenter with no query budget of its
     * own has no project to check membership against.
     *
     * @return array{id: int, title: string, description: string|null, priority: string, dueDate: string|null, overdue: bool, status: array{label: string, done: bool, source: string}, column: array{id: int, name: string, isDone: bool}|null, assignee: array{id: int, name: string}|null, assigneeIsMember: bool, milestone: array{id: int, name: string}|null}
     */
    public static function detail(Task $task, bool $assigneeIsMember): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->isOverdue(),
            'status' => TaskStatusPresenter::forTask($task),
            'column' => $task->column === null ? null : [
                'id' => $task->column->id,
                'name' => $task->column->name,
                'isDone' => (bool) $task->column->is_done_column,
            ],
            'assignee' => self::userRef($task->assignee),
            'assigneeIsMember' => $assigneeIsMember,
            'milestone' => self::milestoneRef($task->milestone),
        ];
    }

    /**
     * @return array{id: int, title: string, completed: bool}
     */
    public static function checklistItem(TaskChecklistItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'completed' => (bool) $item->completed,
        ];
    }

    /**
     * `$comment->user` must already be loaded.
     *
     * @return array{id: int, body: string, createdAt: string, author: array{id: int, name: string}|null}
     */
    public static function comment(TaskComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'createdAt' => $comment->created_at->toISOString(),
            'author' => self::userRef($comment->user),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public static function userRef(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function milestoneRef(?ProjectMilestone $milestone): ?array
    {
        return $milestone === null ? null : ['id' => $milestone->id, 'name' => $milestone->name];
    }
}
