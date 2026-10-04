import { Head, Link, router } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { useMemo, useRef } from 'react';
import type { ReactElement } from 'react';

import { EntityHeader } from '@/components/entity-header';
import { PageFrame } from '@/components/page-frame';
import { Pagination } from '@/components/pagination';
import { ProjectStatusBadge } from '@/components/projects/project-status-badge';
import { ProjectWorkspaceNav } from '@/components/projects/project-workspace-nav';
import { AppShell } from '@/components/shell/app-shell';
import { ProjectTaskFilterBar } from '@/components/tasks/project-task-filter-bar';
import {
    TaskActionAlerts,
    TaskBulkBar,
    useTaskListActions,
} from '@/components/tasks/task-list-actions';
import {
    projectTaskListClearedQuery,
    projectTaskListQuery,
    type ProjectTaskListPatch,
    type TaskListQuery,
} from '@/components/tasks/task-list-query';
import { TaskTable } from '@/components/tasks/task-table';
import type { DataTableHandle } from '@/components/ui/data-table';
import { Button, buttonVariants } from '@/components/ui/button';
import { focusRing } from '@/components/ui/control-metrics';
import { EmptyState } from '@/components/ui/empty-state';
import { layoutPageProps } from '@/lib/inertia-layout';
import { cn } from '@/lib/utils';
import { board, edit, show } from '@/routes/projects';
import { index } from '@/routes/projects/tasks';
import type { Paginated } from '@/types/pagination';
import type { ProjectStatus, TaskPriority } from '@/types/projects';
import type {
    ProjectTaskFilterOptions,
    ProjectTaskFilters,
    ProjectTaskRow,
    ProjectTaskSort,
    TaskAssigneeOptions,
} from '@/types/tasks';

/**
 * EPIC-015 WP3 — a project's Tasks tab (`projects.tasks.index`, §11.1, §13).
 *
 * The Tasks workspace adapted to one project, not a second task product: the same `FilterBar`
 * grammar, `TaskTable` (`DataTable`), row actions, focus repair, `BulkBar` and server paginator, on
 * `TaskQuery::forProject`. The project's identity, lifecycle, Settings action and tabs come first, as on
 * the Overview, so no row repeats the project: the milestone takes the Context column's place.
 *
 * The server is the only authority. `filters`, `sort` and every option are what it resolved; a control
 * change is a visit to this page and the canonical state returns as props. Complete, Reopen, assignment
 * and bulk go through `useTaskListActions` (the Tasks workspace's own), whose endpoints redirect back
 * here. Creating a task is the Board's quick-add (P8): this page offers no create action, and its
 * truly-empty state points at the board instead.
 */
export type ProjectTasksIndexProps = {
    project: { id: number; name: string; status: ProjectStatus };
    tasks: Paginated<ProjectTaskRow>;
    filters: ProjectTaskFilters;
    filterOptions: ProjectTaskFilterOptions;
    sort: ProjectTaskSort;
    /** Whether the project has any task at all, so "nothing yet" and "nothing matches" stay apart. */
    projectHasTasks: boolean;
    /** Who a row may be assigned to (EPIC-014 R6): the server's candidate pool, never derived here. */
    assigneeOptions: TaskAssigneeOptions;
    /** `openSettings` only with effective Settings access (A9); absent otherwise (PHP sends `[]`). */
    abilities: Partial<{ openSettings: true }>;
};

function isNarrowed(filters: ProjectTaskFilters) {
    return (
        filters.completion !== 'open' ||
        filters.priority.length > 0 ||
        filters.due !== null ||
        filters.milestone !== null ||
        filters.assignee !== null ||
        filters.q !== ''
    );
}

export function ProjectTasksIndexPage({
    project,
    tasks,
    filters,
    filterOptions,
    sort,
    projectHasTasks,
    assigneeOptions,
    abilities,
}: ProjectTasksIndexProps) {
    const tableRef = useRef<DataTableHandle<number>>(null);
    const emptyRegion = useRef<HTMLDivElement>(null);
    const actions = useTaskListActions(tasks.data, { tableRef, emptyRegion });

    const state = { filters, sort };
    const priorityLabels = useMemo(
        () =>
            Object.fromEntries(
                filterOptions.priorities.map((option) => [option.value, option.label]),
            ) as Record<TaskPriority, string>,
        [filterOptions.priorities],
    );

    function visit(query: TaskListQuery) {
        actions.clearActionError();
        router.get(index.url(project.id), query, { preserveState: true, preserveScroll: true });
    }

    const change = (patch: ProjectTaskListPatch) => visit(projectTaskListQuery(state, patch));
    const clearFilters = () => visit(projectTaskListClearedQuery(state));

    const header = (
        <EntityHeader
            overline="Project"
            title={project.name}
            status={<ProjectStatusBadge status={project.status} />}
            actions={
                // The server's answer (ProjectSettingsAccess, A9), never a role check here.
                abilities.openSettings ? (
                    <Link
                        href={edit.url(project.id)}
                        className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                    >
                        Settings
                    </Link>
                ) : undefined
            }
            navigation={<ProjectWorkspaceNav projectId={project.id} current="tasks" />}
        />
    );

    let empty: ReactElement;
    if (!projectHasTasks) {
        empty = (
            <EmptyState
                icon={ListChecks}
                title="No tasks in this project yet"
                description="Tasks are added and moved on the project board."
                action={
                    <Link
                        href={board.url(project.id)}
                        className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                    >
                        Open board
                    </Link>
                }
            />
        );
    } else if (isNarrowed(filters)) {
        empty = (
            <EmptyState
                icon={ListChecks}
                title="No tasks match these filters."
                description="Change or clear a filter, or search for something else."
                action={
                    <Button type="button" variant="secondary" onClick={clearFilters}>
                        Clear filters
                    </Button>
                }
            />
        );
    } else {
        empty = (
            <EmptyState
                icon={ListChecks}
                title="No open tasks"
                description="Every task in this project is done."
                action={
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => change({ completion: 'any' })}
                    >
                        Show all tasks
                    </Button>
                }
            />
        );
    }

    return (
        <>
            <Head title={`${project.name} — Tasks`} />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                {header}

                <h2 className="sr-only">Tasks</h2>

                <ProjectTaskFilterBar
                    filters={filters}
                    filterOptions={filterOptions}
                    sort={sort}
                    onChange={change}
                    onClear={clearFilters}
                />

                <TaskActionAlerts actions={actions} />

                {tasks.data.length === 0 ? (
                    <div ref={emptyRegion} tabIndex={-1} className={cn('outline-none', focusRing)}>
                        {empty}
                    </div>
                ) : (
                    <TaskTable
                        ref={tableRef}
                        scope="project"
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
        </>
    );
}

/**
 * The shell's one breadcrumb: `Projects › All projects › {project} › Tasks`, the project segment
 * opening its Overview (§11.3). No page-owned breadcrumb.
 */
ProjectTasksIndexPage.layout = (page: ReactElement) => {
    const props = layoutPageProps<ProjectTasksIndexProps>(page);

    return (
        <AppShell
            trail={
                props && [
                    { label: props.project.name, href: show.url(props.project.id) },
                    { label: 'Tasks' },
                ]
            }
        >
            {page}
        </AppShell>
    );
};

export default ProjectTasksIndexPage;
