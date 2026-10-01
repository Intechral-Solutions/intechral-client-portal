import { Head, router, usePage } from '@inertiajs/react';
import { ListChecks, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactElement } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { AppShell } from '@/components/shell/app-shell';
import { CreateTaskDialog } from '@/components/tasks/create-task-dialog';
import { TaskFilterBar } from '@/components/tasks/task-filter-bar';
import {
    taskListClearedQuery,
    taskListQuery,
    type TaskListPatch,
    type TaskListQuery,
} from '@/components/tasks/task-list-query';
import { TaskTable } from '@/components/tasks/task-table';
import { useOptionalTimers } from '@/components/time/timer-provider';
import { Alert } from '@/components/ui/alert';
import { BulkBar } from '@/components/ui/bulk-bar';
import { Button } from '@/components/ui/button';
import type { DataTableHandle } from '@/components/ui/data-table';
import { EmptyState } from '@/components/ui/empty-state';
import { focusIsLost } from '@/lib/focus';
import { bulk as bulkRoute, complete, index, reopen } from '@/routes/tasks';
import { cn } from '@/lib/utils';
import { focusRing } from '@/components/ui/control-metrics';
import type { SharedPageProps } from '@/types';
import type { Paginated } from '@/types/pagination';
import type { TaskPriority } from '@/types/projects';
import type {
    TaskBulkResult,
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
 * invents a task's state before the server answers: Complete, Reopen and bulk call the WP2 endpoints
 * and the rows are whatever the redirect returns. My tasks / All tasks are shell drawer views, so the
 * page has no view tabs of its own.
 *
 * Client state is limited to what is genuinely the browser's: the open dialog, the row selection, which
 * requests are in flight and the last action error.
 */
export type TasksIndexProps = {
    tasks: Paginated<TaskRow>;
    view: TaskView;
    filters: TaskListFilters;
    filterOptions: TaskFilterOptions;
    sort: TaskListSort;
    canViewAll: boolean;
    createOptions: TaskCreateOptions;
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

/** The bulk outcome as counts, only when something was not changed. The success toast is the shell's. */
function bulkSummary(result: TaskBulkResult | null | undefined) {
    if (!result) return null;

    const refused = [
        result.notPermitted.length > 0 && `${result.notPermitted.length} not permitted`,
        result.configurationError.length > 0 &&
            `${result.configurationError.length} blocked by the project board setup`,
        result.failed.length > 0 && `${result.failed.length} failed, try again`,
    ].filter(Boolean);

    if (refused.length === 0) return null;

    const verb = result.action === 'complete' ? 'completed' : 'reopened';

    return `${result.succeeded.length} ${verb}. Not changed: ${refused.join(', ')}.`;
}

export function TasksIndexPage({
    tasks,
    view,
    filters,
    filterOptions,
    sort,
    createOptions,
}: TasksIndexProps) {
    const { flash } = usePage<SharedPageProps>().props;
    const timers = useOptionalTimers();
    const tableRef = useRef<DataTableHandle<number>>(null);

    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState<ReadonlySet<number>>(new Set());
    const [pending, setPending] = useState<ReadonlySet<number>>(new Set());
    const [bulkBusy, setBulkBusy] = useState(false);
    const [actionError, setActionError] = useState<{ title: string; message: string } | null>(null);
    const inFlight = useRef(new Set<number>());
    const bulkInFlight = useRef(false);
    // Where focus goes once the list the server returns has rendered (Direction D §14.2): the row
    // that takes the acted-on row's place, or `'empty'` when it was the only one. `wasDone` is the
    // row's state when the request left; if the response shows it unchanged, the action did not take
    // effect (a refusal), the focus has no reason to move and the entry is dropped.
    const focusAfter = useRef<{ task: number; wasDone: boolean; target: number | 'empty' } | null>(
        null,
    );
    const emptyRegion = useRef<HTMLDivElement>(null);
    // The row that last held table focus while a selection existed, and the selection size before.
    const lastRow = useRef<number | null>(null);
    const previousSelected = useRef(0);

    // Adopt a selection that still matches the page during render (the documented alternative to an
    // effect): a row that left the page, by a filter, a page change or a completed action, is no
    // longer selected, so a bulk request never names a task the user cannot see.
    const [seenRows, setSeenRows] = useState(tasks.data);
    if (seenRows !== tasks.data) {
        setSeenRows(tasks.data);
        const present = new Set(tasks.data.map((task) => task.id));
        setSelected((current) => new Set([...current].filter((id) => present.has(id))));
    }

    // Once the rows the server returned have rendered, put focus where Direction D §14.2 asks.
    useEffect(() => {
        const pendingFocus = focusAfter.current;
        if (pendingFocus === null) return;

        const row = tasks.data.find((candidate) => candidate.id === pendingFocus.task);
        // Still there and unchanged: the server refused it. Nothing moved, so focus stays put.
        if (row && row.status.done === pendingFocus.wasDone) {
            focusAfter.current = null;

            return;
        }

        focusAfter.current = null;
        if (pendingFocus.target !== 'empty') {
            tableRef.current?.focusRow(pendingFocus.target);
        } else if (tasks.data.length === 0) {
            emptyRegion.current?.focus();
        } else {
            tableRef.current?.focusFirstRow();
        }
    }, [tasks.data]);

    const state = { view, filters, sort };
    const narrowed = isNarrowed(filters);
    const selectedRows = tasks.data.filter((task) => selected.has(task.id));
    const selectedIds = selectedRows.map((task) => task.id);
    // Offered only when the server says at least one selected row allows it (WP2 still decides each).
    const canComplete = selectedRows.some((task) => task.abilities.complete);
    const canReopen = selectedRows.some((task) => task.abilities.reopen);

    // The BulkBar unmounts with its last selected row; if that took focus with it, hand it back to the
    // row that last held it, else the first row (or the empty state), rather than leaving it on the document.
    useEffect(() => {
        const before = previousSelected.current;
        previousSelected.current = selectedIds.length;

        if (selectedIds.length > 0) {
            lastRow.current = tableRef.current?.focusedRowKey() ?? lastRow.current;

            return;
        }

        if (before > 0 && focusIsLost()) {
            const last = lastRow.current;
            if (tasks.data.length === 0) emptyRegion.current?.focus();
            else if (last === null || !tableRef.current?.focusRow(last))
                tableRef.current?.focusFirstRow();
        }
    }, [selectedIds.length, tasks.data.length]);

    const runningTaskIds = useMemo(
        () =>
            new Set(
                (timers?.timers ?? []).flatMap((timer) =>
                    timer.context?.type === 'Task' ? [timer.context.id] : [],
                ),
            ),
        [timers],
    );
    const priorityLabels = useMemo(
        () =>
            Object.fromEntries(
                filterOptions.priorities.map((option) => [option.value, option.label]),
            ) as Record<TaskPriority, string>,
        [filterOptions.priorities],
    );

    function visit(query: TaskListQuery) {
        setActionError(null);
        router.get(index.url(), query, { preserveState: true, preserveScroll: true });
    }

    const change = (patch: TaskListPatch) => visit(taskListQuery(state, patch));
    const clearFilters = () => visit(taskListClearedQuery(state));

    function toggle(task: TaskRow) {
        if (inFlight.current.has(task.id)) return;

        inFlight.current.add(task.id);
        setPending(new Set(inFlight.current));
        setActionError(null);

        // Focus follows a Complete only when the row (or its ring) had it: the next row, or the
        // previous one at the end of the page. A pointer user elsewhere keeps their place.
        const position = tasks.data.findIndex((candidate) => candidate.id === task.id);
        const neighbour = (tasks.data[position + 1] ?? tasks.data[position - 1])?.id;
        focusAfter.current =
            tableRef.current?.focusedRowKey() === task.id
                ? { task: task.id, wasDone: task.status.done, target: neighbour ?? 'empty' }
                : null;

        const url = task.status.done ? reopen.url(task.id) : complete.url(task.id);

        router.put(
            url,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setActionError(null),
                onError: (errors) => {
                    focusAfter.current = null;
                    setActionError({
                        title: task.title,
                        message:
                            errors.complete ??
                            errors.reopen ??
                            Object.values(errors)[0] ??
                            'The task could not be changed.',
                    });
                },
                onFinish: () => {
                    inFlight.current.delete(task.id);
                    setPending(new Set(inFlight.current));
                },
            },
        );
    }

    function bulk(action: 'complete' | 'reopen') {
        if (bulkInFlight.current || selectedIds.length === 0) return;

        bulkInFlight.current = true;
        setBulkBusy(true);
        setActionError(null);

        router.post(
            bulkRoute.url(),
            { action, ids: selectedIds },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelected(new Set()),
                onFinish: () => {
                    bulkInFlight.current = false;
                    setBulkBusy(false);
                },
            },
        );
    }

    const summary = bulkSummary(flash.bulk);

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

                {actionError ? (
                    <Alert
                        variant="danger"
                        action={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="-my-2 -mr-2"
                                aria-label="Dismiss message"
                                onClick={() => setActionError(null)}
                            >
                                <X className="h-4 w-4" aria-hidden="true" />
                            </Button>
                        }
                    >
                        Could not change “{actionError.title}”: {actionError.message}
                    </Alert>
                ) : null}

                {summary ? (
                    <Alert variant="warning" data-testid="bulk-summary">
                        {summary}
                    </Alert>
                ) : null}

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
                        runningTaskIds={runningTaskIds}
                        pendingIds={pending}
                        onToggle={toggle}
                        onOpen={(task) => task.url && router.visit(task.url)}
                        selection={{ selected, onChange: setSelected }}
                    />
                )}

                {tasks.last_page > 1 ? <Pagination paginator={tasks} /> : null}
            </PageFrame>

            <BulkBar
                count={selectedIds.length}
                noun="task"
                label="Bulk actions"
                onClear={() => setSelected(new Set())}
            >
                <Button
                    type="button"
                    size="sm"
                    disabled={bulkBusy || !canComplete}
                    onClick={() => bulk('complete')}
                >
                    Complete
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    disabled={bulkBusy || !canReopen}
                    onClick={() => bulk('reopen')}
                >
                    Reopen
                </Button>
            </BulkBar>

            <CreateTaskDialog open={creating} onOpenChange={setCreating} options={createOptions} />
        </>
    );
}

TasksIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default TasksIndexPage;
