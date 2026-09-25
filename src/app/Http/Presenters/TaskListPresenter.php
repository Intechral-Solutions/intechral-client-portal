<?php

namespace App\Http\Presenters;

use App\Models\Task;

/**
 * Row DTOs for the unified `/tasks` list (EPIC-011E §5, §15, WP8). One table backs three
 * genuinely different task kinds (project-board, ticket-derived, standalone); this presenter
 * keeps the shared fields (id, title, priority, status, due date, assignee) common because they
 * are genuinely common, while `context`/`url` are computed per kind rather than faked. Standalone
 * rows never get a project or a destination; ticket rows never adopt board completion semantics
 * (status still comes from `TaskStatusPresenter::forTask`, which is column-first only for board
 * tasks). No email, description, or unrelated id is ever included.
 *
 * The caller (`TaskController::index`) is responsible for eager loading `assignee`, `project`,
 * `ticket`, and `column`, and for the batched `$openableProjects`/`$openableTickets` lookups, so
 * building a page of rows never queries per row (§25).
 */
final class TaskListPresenter
{
    /**
     * @param  array<int, bool>  $openableProjects  project id => true when ProjectPolicy::view allows it
     * @param  array<int, bool>  $openableTickets  ticket id => true when TicketPolicy::view allows it
     * @return array{id: int, title: string, priority: string, status: array{label: string, done: bool, source: string}, dueDate: string|null, overdue: bool, assignee: array{id: int, name: string}|null, context: array{kind: string, label: string, url: string|null}, url: string|null}
     */
    public static function row(Task $task, array $openableProjects, array $openableTickets): array
    {
        $context = match ($task->kind()) {
            Task::KIND_BOARD => self::projectContext($task, $openableProjects),
            Task::KIND_TICKET => self::ticketContext($task, $openableTickets),
            default => ['kind' => 'standalone', 'label' => 'Standalone', 'url' => null],
        };

        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'status' => TaskStatusPresenter::forTask($task),
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->isOverdue(),
            'assignee' => ProjectTaskPresenter::userRef($task->assignee),
            'context' => $context,
            // Only a project-board task has a task-detail destination distinct from its context
            // link (D3: standalone has none by design; a ticket task's own "page" is the ticket
            // itself, already offered as context.url, matching the Blade list this replaces).
            'url' => $task->kind() === Task::KIND_BOARD && isset($openableProjects[$task->project_id])
                ? route('projects.tasks.show', [$task->project_id, $task->id])
                : null,
        ];
    }

    /**
     * @param  array<int, bool>  $openableProjects
     * @return array{kind: 'project', label: string, url: string|null}
     */
    private static function projectContext(Task $task, array $openableProjects): array
    {
        $openable = isset($openableProjects[$task->project_id]);

        return [
            'kind' => 'project',
            'label' => $task->project->name,
            'url' => $openable ? route('projects.board', $task->project_id) : null,
        ];
    }

    /**
     * @param  array<int, bool>  $openableTickets
     * @return array{kind: 'ticket', label: string, url: string|null}
     */
    private static function ticketContext(Task $task, array $openableTickets): array
    {
        $openable = isset($openableTickets[$task->ticket_id]);

        return [
            'kind' => 'ticket',
            'label' => $task->ticket->ticket_number,
            'url' => $openable ? route('tickets.show', $task->ticket_id) : null,
        ];
    }
}
