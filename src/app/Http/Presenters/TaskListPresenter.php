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
     * @return array{id: int, title: string, kind: 'board'|'standalone', projectId: int|null, priority: string, status: array{label: string, done: bool, source: string}, dueDate: string|null, overdue: bool, assignee: array{id: int, name: string}|null, context: array{kind: 'project'|'standalone', label: string, url: string|null}, url: string|null, abilities: array{complete: bool, reopen: bool, assign: bool}}
     */
    public static function row(Task $task, TaskRowAbilities $abilities): array
    {
        $board = $task->project_id !== null;
        $openable = $board && $abilities->canViewProject($task->project_id);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'kind' => $board ? Task::KIND_BOARD : Task::KIND_STANDALONE,
            // The key into the page's `assigneeOptions.projects` (R6). A board row is only ever on
            // a page for a project the actor may view (§9.1.1), and its URLs already carry this id.
            'projectId' => $task->project_id,
            'priority' => $task->priority,
            'status' => TaskStatusPresenter::forTask($task),
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->isOverdue(),
            'assignee' => ProjectTaskPresenter::userRef($task->assignee),
            'context' => $board
                ? ['kind' => 'project', 'label' => $task->project->name, 'url' => $openable ? route('projects.board', $task->project_id) : null]
                : ['kind' => 'standalone', 'label' => 'Standalone', 'url' => null],
            // A board task's page is projects.tasks.show (P6: tasks.show would only redirect there).
            // A standalone row is viewable by construction, since TaskQuery admits only the creator
            // or current assignee (§9.1.1), so its own page, tasks.show, is always a safe link.
            'url' => $board
                ? ($openable ? route('projects.tasks.show', [$task->project_id, $task->id]) : null)
                : route('tasks.show', $task->id),
            'abilities' => $abilities->of($task),
        ];
    }
}
