<?php

namespace App\Http\Presenters;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Queries\TaskRowAbilities;

/**
 * Page DTOs for the project board (EPIC-011E §5, §7, WP5). Every method returns plain scalars
 * and arrays in camelCase; no model, relation, or attribute the board does not render is ever
 * serialized (no description, comments, emails, or checklist item text). The caller is
 * responsible for eager loading (`ProjectBoardController::show`) so building these DTOs never
 * queries per row.
 */
final class ProjectBoardPresenter
{
    /**
     * @return array{id: int, name: string, status: string}
     */
    public static function project(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
        ];
    }

    /**
     * `$column->tasks` must already be loaded (ordered by position, id), with each task's
     * `assignee`, `milestone`, `column`, and checklist aggregate counts eager loaded. `$abilities`
     * is the board's batched TaskPolicy projection (`TaskRowAbilities::for` over every card).
     *
     * @return array{id: int, name: string, isDone: bool, tasks: array<int, array<string, mixed>>}
     */
    public static function column(ProjectColumn $column, TaskRowAbilities $abilities): array
    {
        return [
            'id' => $column->id,
            'name' => $column->name,
            'isDone' => (bool) $column->is_done_column,
            'tasks' => $column->tasks->map(fn (Task $task) => self::task($task, $abilities))->values()->all(),
        ];
    }

    /**
     * @return array{id: int, title: string, priority: string, dueDate: string|null, overdue: bool, assignee: array{id: int, name: string}|null, milestone: array{id: int, name: string}|null, checklist: array{done: int, total: int}, done: bool, abilities: array{complete: bool, reopen: bool}}
     */
    public static function task(Task $task, TaskRowAbilities $abilities): array
    {
        $can = $abilities->of($task);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'dueDate' => $task->due_date?->toDateString(),
            // Column-first, exactly Task::isOverdue()/isDone() (EPIC-011E §15): the board never
            // re-implements the status/overdue rule.
            'overdue' => $task->isOverdue(),
            'assignee' => $task->assignee === null ? null : [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ],
            'milestone' => $task->milestone === null ? null : [
                'id' => $task->milestone->id,
                'name' => $task->milestone->name,
            ],
            'checklist' => [
                'done' => (int) $task->done_checklist_items_count,
                'total' => (int) $task->checklist_items_count,
            ],
            // EPIC-015 WP5 S1: the card's Complete/Reopen control. `done` is the column-authoritative
            // rule (Task::isDone, INV-P1), never `tasks.status`; the abilities are TaskPolicy's
            // complete/reopen answers, so a card offers only what the tasks.* routes will accept.
            'done' => $task->isDone(),
            'abilities' => ['complete' => $can['complete'], 'reopen' => $can['reopen']],
        ];
    }
}
