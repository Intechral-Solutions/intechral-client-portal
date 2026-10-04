import { Head, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { AppShell } from '@/components/shell/app-shell';
import { CreateTaskDialog } from '@/components/tasks/create-task-dialog';
import { TaskFilterBar } from '@/components/tasks/task-filter-bar';
import {
    TaskActionAlerts,
    TaskBulkBar,
    useTaskListActions,
} from '@/components/tasks/task-list-actions';
import {
    taskListClearedQuery,
    taskListQuery,
    type TaskListPatch,
    type TaskListQuery,
} from '@/components/tasks/task-list-query';
import { TaskTable } from '@/components/tasks/task-table';
import type { DataTableHandle } from '@/components/ui/data-table';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { index } from '@/routes/tasks';
import { cn } from '@/lib/utils';
import { focusRing } from '@/components/ui/control-metrics';
import type { Paginated } from '@/types/pagination';
import type { TaskPriority } from '@/types/projects';
import type {
    TaskAssigneeOptions,
    TaskCreateOptions,
    TaskFilterOptions,
    TaskListFilters,
    TaskListSort,
    TaskRow,
    TaskView,
} from '@/types/tasks';

/**
 * The Tasks list (EPIC-014 §14.1, WP4): canvas `PageFrame`, `PageHeader`, `FilterBar`, `DataTable`,
 * `BulkBar`, `EmptyState` and the server paginator, over the WP3 contract.
 *
 * The server is the only authority. `view`, `filters`, `sort` and every option are what it resolved;
 * a control change is a `/tasks` visit and the canonical state comes back as new props, so the page
 * keeps no filter truth of its own. Nothing here fetches options, derives permission from a role or
 * invents a task's state before the server answers: Complete, Reopen, assignment and bulk go through
 * `useTaskListActions` (shared with the project Tasks tab, EPIC-015 WP3) and the rows are whatever the
 * redirect returns. My tasks / All tasks are shell drawer views, so the page has no view tabs of its
 * own.
 *
 * Client state is limited to what is genuinely the browser's: the open dialog, plus the selection,
 * in-flight requests and last action error the shared hook keeps.
 */
export type TasksIndexProps = {
    tasks: Paginated<TaskRow>;
    view: TaskView;
    filters: TaskListFilters;
    filterOptions: TaskFilterOptions;
    sort: TaskListSort;
    canViewAll: boolean;
    createOptions: TaskCreateOptions;
    /** Who a row may be assigned to (EPIC-014 R6): the server's candidate pool, never derived here. */
    assigneeOptions: TaskAssigneeOptions;
};

const viewTitles: Record<TaskView, string> = { mine: 'My tasks', all: 'All tasks' };

const viewDescriptions: Record<TaskView, string> = {
    mine: 'Tasks assigned to you, and unassigned tasks you created.',
    all: 'Every task you can see: tasks in your projects, and your own tasks.',
};

const emptyDescriptions: Record<TaskView, string> = {
    mine: 'Tasks assigned to you, and unassigned tasks you create, will appear here.',
    all: 'Tasks in your projects and your own tasks will appear here.',
};

function isNarrowed(filters: TaskListFilters) {
    return (
        filters.completion !== 'open' ||
        filters.priority.length > 0 ||
        filters.due !== null ||
        filters.kind !== null ||
        filters.project !== null ||
        filters.milestone !== null ||
        filters.assignee !== null ||
        filters.organization !== null ||
        filters.q !== ''
    );
}

export function TasksIndexPage({
    tasks,
    view,
    filters,
    filterOptions,
    sort,
    createOptions,
    assigneeOptions,
}: TasksIndexProps) {
    const tableRef = useRef<DataTableHandle<number>>(null);
    const emptyRegion = useRef<HTMLDivElement>(null);
    const actions = useTaskListActions(tasks.data, { tableRef, emptyRegion });
    const [creating, setCreating] = useState(false);

    const state = { view, filters, sort };
    const narrowed = isNarrowed(filters);
    const priorityLabels = useMemo(
        () =>
            Object.fromEntries(
                filterOptions.priorities.map((option) => [option.value, option.label]),
            ) as Record<TaskPriority, string>,
        [filterOptions.priorities],
    );

    function visit(query: TaskListQuery) {
        actions.clearActionError();
        router.get(index.url(), query, { preserveState: true, preserveScroll: true });
    }

    const change = (patch: TaskListPatch) => visit(taskListQuery(state, patch));
    const clearFilters = () => visit(taskListClearedQuery(state));

    return (
        <>
            <Head title={viewTitles[view]} />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <PageHeader
                    title={viewTitles[view]}
                    description={viewDescriptions[view]}
                    actions={
                        <Button type="button" onClick={() => setCreating(true)}>
                            New task
                        </Button>
                    }
                />

                <TaskFilterBar
                    view={view}
                    filters={filters}
                    filterOptions={filterOptions}
                    sort={sort}
                    onChange={change}
                    onClear={clearFilters}
                />

                <TaskActionAlerts actions={actions} />

                {tasks.data.length === 0 ? (
                    <div ref={emptyRegion} tabIndex={-1} className={cn('outline-none', focusRing)}>
                        {narrowed ? (
                            <EmptyState
                                icon={ListChecks}
                                title="No tasks match these filters."
                                description="Change or clear a filter, or search for something else."
                                action={
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={clearFilters}
                                    >
                                        Clear filters
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={ListChecks}
                                title="No open tasks"
                                description={emptyDescriptions[view]}
                                action={
                                    <Button type="button" onClick={() => setCreating(true)}>
                                        New task
                                    </Button>
                                }
                            />
                        )}
                    </div>
                ) : (
                    <TaskTable
                        ref={tableRef}
                        tasks={tasks.data}
                        priorityLabels={priorityLabels}
                        runningTaskIds={actions.runningTaskIds}
                        pendingIds={actions.pending}
                        onToggle={actions.toggle}
                        onOpen={(task) => task.url && router.visit(task.url)}
                        assigneeOptions={assigneeOptions}
                        assigningIds={actions.assigning}
                        onAssign={actions.assign}
                        selection={{ selected: actions.selected, onChange: actions.setSelected }}
                    />
                )}

                {tasks.last_page > 1 ? <Pagination paginator={tasks} /> : null}
            </PageFrame>

            <TaskBulkBar actions={actions} />

            <CreateTaskDialog open={creating} onOpenChange={setCreating} options={createOptions} />
        </>
    );
}

TasksIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default TasksIndexPage;
