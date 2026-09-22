import { PriorityBadge } from '@/components/projects/priority-badge';
import { TaskStatusBadge } from '@/components/projects/task-status-badge';
import { TaskContextLink } from '@/components/tasks/task-context-link';
import { TaskTitleCell } from '@/components/tasks/task-title-cell';
import { formatDate } from '@/lib/dates';
import type { TaskRow } from '@/types/tasks';

/**
 * One row of the unified task list (EPIC-011E §5, WP8). Every cell but the title and context
 * link renders identically for all three task kinds — the shared `TaskStatusDto` already carries
 * the column-vs-status distinction (§15), so this component never re-derives it.
 */
export function TaskListRow({ task }: { task: TaskRow }) {
    return (
        <tr className="border-b border-border last:border-b-0">
            <td className="px-4 py-3 text-sm">
                <TaskTitleCell task={task} />
            </td>
            <td className="px-4 py-3">
                <TaskStatusBadge status={task.status} />
            </td>
            <td className="px-4 py-3">
                <PriorityBadge priority={task.priority} />
            </td>
            <td className="px-4 py-3 text-xs text-muted-foreground">
                <TaskContextLink context={task.context} className="hover:underline" />
            </td>
            <td className="px-4 py-3 text-xs text-muted-foreground">
                {task.assignee?.name ?? '—'}
            </td>
            <td
                className={
                    task.overdue
                        ? 'px-4 py-3 text-xs font-medium text-[var(--text-danger)]'
                        : 'px-4 py-3 text-xs text-muted-foreground'
                }
            >
                {task.dueDate ? formatDate(task.dueDate) : '—'}
            </td>
        </tr>
    );
}
