import { Clock } from 'lucide-react';
import type { Ref } from 'react';

import { assigneeChoices } from '@/components/tasks/task-assignee-choices';
import { TaskAssigneeMenu } from '@/components/tasks/task-assignee-menu';
import { TaskCompleteControl } from '@/components/tasks/task-complete-control';
import { TaskContextLink } from '@/components/tasks/task-context-link';
import { TaskPriorityMark } from '@/components/tasks/task-priority';
import { TaskTitleCell } from '@/components/tasks/task-title-cell';
import { DataTable, type DataTableColumn, type DataTableHandle } from '@/components/ui/data-table';
import { Status } from '@/components/ui/status';
import { formatDate } from '@/lib/dates';
import type { TaskPriority } from '@/types/projects';
import type { TaskAssigneeOptions, TaskRow } from '@/types/tasks';

/**
 * EPIC-014 WP4 — the Tasks list's `DataTable` (§14.1): Complete ring · Task (title + source tag) ·
 * Status · Priority · Context · Assignee · Due, with a selection column for the bulk bar. It renders
 * the `TaskRow` DTO and nothing the DTO withholds, and decides nothing: abilities, order, paging and
 * visibility all come from the server.
 *
 * At S (D9) the selection, ring and one-line title share the first band and the status, priority,
 * context, assignee and due date the second (a strict two bands at 390px). A row the server lets the
 * viewer assign (`abilities.assign`) draws the assignee as a compact control, the mark alone at S, so the
 * band does not grow (EPIC-014 R6); any other row keeps the assignee for assistive technology only
 * (`detail`), as before.
 */
function DueCell({ task }: { task: TaskRow }) {
    if (!task.dueDate) {
        return (
            <span data-cell="due" className="text-text-muted">
                —
            </span>
        );
    }

    // The canonical formatted date, never a relative word: "Today" would be the browser's calendar,
    // which can disagree with the application's. Overdue is the server's call (`task.overdue`). The
    // leading "Due" is for assistive technology; the column header says it on a desktop row.
    return (
        <span
            data-cell="due"
            className={
                task.overdue
                    ? 'inline-flex items-center gap-1 font-medium whitespace-nowrap text-danger'
                    : 'inline-flex items-center gap-1 whitespace-nowrap'
            }
        >
            {task.overdue ? (
                <>
                    <Clock className="size-3.5 shrink-0" aria-hidden="true" />
                    <span className="sr-only">Overdue: </span>
                </>
            ) : null}
            <span className="sr-only">Due </span>
            <time dateTime={task.dueDate}>{formatDate(task.dueDate)}</time>
        </span>
    );
}

export function TaskTable({
    tasks,
    priorityLabels,
    runningTaskIds,
    pendingIds,
    onToggle,
    onOpen,
    assigneeOptions,
    assigningIds,
    onAssign,
    selection,
    ref,
}: {
    tasks: readonly TaskRow[];
    /** Server-named priority labels (INV-19), by value. */
    priorityLabels: Record<TaskPriority, string>;
    runningTaskIds: ReadonlySet<number>;
    pendingIds: ReadonlySet<number>;
    onToggle: (task: TaskRow) => void;
    onOpen: (task: TaskRow) => void;
    /** The server's candidate pool for single-row assignment (R6). */
    assigneeOptions: TaskAssigneeOptions;
    assigningIds: ReadonlySet<number>;
    /** A change of assignee for one row: a user id, or `null` to release it. */
    onAssign: (task: TaskRow, userId: number | null) => void;
    selection?: { selected: ReadonlySet<number>; onChange: (next: Set<number>) => void };
    ref?: Ref<DataTableHandle<number>>;
}) {
    const columns: DataTableColumn<TaskRow>[] = [
        {
            id: 'complete',
            header: 'Complete',
            hideHeader: true,
            area: 'lead',
            className: 'w-12',
            cell: (task) => (
                <TaskCompleteControl
                    task={task}
                    pending={pendingIds.has(task.id)}
                    onToggle={onToggle}
                />
            ),
        },
        {
            id: 'task',
            header: 'Task',
            area: 'title',
            className: 'min-w-0 md:w-[40%]',
            cell: (task) => <TaskTitleCell task={task} running={runningTaskIds.has(task.id)} />,
        },
        {
            id: 'status',
            header: 'Status',
            // Board column names are user-defined and unbounded: at S the label truncates so the
            // second band (D9) holds, and the full text stays in the DOM and the tooltip.
            className: 'max-md:max-w-28 max-md:min-w-0 max-md:px-0',
            cell: (task) => (
                <Status
                    tone={task.status.done ? 'success' : 'neutral'}
                    title={task.status.label}
                    className="max-w-full max-md:[&>span:last-child]:min-w-0 max-md:[&>span:last-child]:truncate"
                >
                    {task.status.label}
                </Status>
            ),
        },
        {
            id: 'priority',
            header: 'Priority',
            className: 'max-md:shrink-0 max-md:px-0',
            cell: (task) => (
                <TaskPriorityMark
                    priority={task.priority}
                    label={priorityLabels[task.priority] ?? task.priority}
                />
            ),
        },
        {
            id: 'context',
            header: 'Context',
            // At S the context is the flexible item of the second band: it takes what the status,
            // priority and due date leave and truncates, so a long project name never claims a
            // line of its own (D9: two lines).
            className:
                'max-md:min-w-0 max-md:shrink max-md:grow max-md:basis-0 max-md:truncate max-md:px-0',
            cell: (task) => (
                <TaskContextLink
                    context={task.context}
                    className="text-text-secondary hover:underline"
                />
            ),
        },
        {
            id: 'assignee',
            header: 'Assignee',
            // A control the server allows is a visible second-band item at S; plain text is not.
            area: (task) => (task.abilities.assign ? 'meta' : 'detail'),
            className: 'max-md:shrink-0 max-md:px-0',
            cell: (task) =>
                task.abilities.assign ? (
                    <TaskAssigneeMenu
                        taskTitle={task.title}
                        assignee={task.assignee}
                        choices={assigneeChoices(task, assigneeOptions)}
                        pending={assigningIds.has(task.id)}
                        onAssign={(userId) => onAssign(task, userId)}
                        compactAtSmall
                        className="max-w-48"
                    />
                ) : (
                    <span data-cell="assignee" className="whitespace-nowrap text-text-secondary">
                        {task.assignee?.name ?? '—'}
                    </span>
                ),
        },
        {
            id: 'due',
            header: 'Due',
            className: 'max-md:shrink-0 max-md:px-0',
            cell: (task) => <DueCell task={task} />,
        },
    ];

    /** The one place Complete/Reopen availability for a keyboard press is decided: the row's abilities. */
    function toggleFromKey(task: TaskRow) {
        const allowed = task.status.done ? task.abilities.reopen : task.abilities.complete;

        if (allowed && !pendingIds.has(task.id)) onToggle(task);
    }

    return (
        <DataTable<TaskRow, number>
            ref={ref}
            label="Tasks"
            columns={columns}
            rows={tasks}
            getRowKey={(task) => task.id}
            rowClassName={(task) =>
                [
                    task.status.done ? 'text-text-muted' : 'text-text',
                    runningTaskIds.has(task.id) ? 'bg-live-soft' : undefined,
                ]
                    .filter(Boolean)
                    .join(' ')
            }
            rowNavigation
            selection={
                selection
                    ? {
                          selected: selection.selected,
                          onChange: selection.onChange,
                          rowLabel: (task) => `Select ${task.title}`,
                          allLabel: 'Select all tasks',
                      }
                    : undefined
            }
            rowShortcuts={{
                Escape: () => selection?.onChange(new Set()),
                e: toggleFromKey,
                Enter: (task) => {
                    if (task.url) onOpen(task);
                },
            }}
        />
    );
}
