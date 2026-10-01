<?php

namespace App\Http\Presenters;

use App\Models\Task;
use App\Queries\TaskRowAbilities;

/**
 * Row DTOs for the Tasks workspace list (EPIC-011E §5, §15; EPIC-014 §13.3). The list surfaces
 * exactly two kinds, board and standalone (EPIC-014 Q6: `TaskQuery` never returns a ticket-kind
 * or dual-linked row), so the DTO models only those two. Shared fields (id, title, priority,
 * status, due date, assignee) stay common because they are genuinely common; `context`/`url` are
 * per kind rather than faked. No email, description, or unrelated id is ever included.
 *
 * The caller hands in the page already eager loaded (`TaskQuery::paginate`) and one
 * `TaskRowAbilities` for the whole page, so building rows never queries per row.
 */
final class TaskListPresenter
{
    /**
     * @return array{id: int, title: string, kind: 'board'|'standalone', priority: string, status: array{label: string, done: bool, source: string}, dueDate: string|null, overdue: bool, assignee: array{id: int, name: string}|null, context: array{kind: 'project'|'standalone', label: string, url: string|null}, url: string|null, abilities: array{complete: bool, reopen: bool, assign: bool}}
     */
    public static function row(Task $task, TaskRowAbilities $abilities): array
    {
        $board = $task->project_id !== null;
        $openable = $board && $abilities->canViewProject($task->project_id);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'kind' => $board ? Task::KIND_BOARD : Task::KIND_STANDALONE,
            'priority' => $task->priority,
            'status' => TaskStatusPresenter::forTask($task),
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->isOverdue(),
            'assignee' => ProjectTaskPresenter::userRef($task->assignee),
            'context' => $board
                ? ['kind' => 'project', 'label' => $task->project->name, 'url' => $openable ? route('projects.board', $task->project_id) : null]
                : ['kind' => 'standalone', 'label' => 'Standalone', 'url' => null],
            // A board task's page is projects.tasks.show. A standalone task gains its own page
            // (tasks.show) with EPIC-014 WP5; until that route exists the row has no destination.
            'url' => $openable ? route('projects.tasks.show', [$task->project_id, $task->id]) : null,
            'abilities' => $abilities->of($task),
        ];
    }
}
