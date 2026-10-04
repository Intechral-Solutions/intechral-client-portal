import { router, usePage } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { RefObject } from 'react';

import { useOptionalTimers } from '@/components/time/timer-provider';
import { Alert } from '@/components/ui/alert';
import { BulkBar } from '@/components/ui/bulk-bar';
import { Button } from '@/components/ui/button';
import type { DataTableHandle } from '@/components/ui/data-table';
import { focusIsLost } from '@/lib/focus';
import { bulk as bulkRoute, complete, reopen } from '@/routes/tasks';
import { update as assigneeUpdate } from '@/routes/tasks/assignee';
import type { SharedPageProps } from '@/types';
import type { TaskBulkResult, TaskRow } from '@/types/tasks';

/**
 * EPIC-014 WP4/WP5 — what a Tasks list does to its rows, shared by the Tasks workspace and the
 * project Tasks tab (EPIC-015 §13.3), so both lists act, repair focus and select the same way.
 *
 * The server is the only authority: Complete, Reopen, assignment and bulk call the `tasks.*`
 * endpoints, which redirect back, and the rows are whatever the redirect returns. Client state is
 * limited to what is genuinely the browser's: the row selection, which requests are in flight and the
 * last action error. A running timer is read from the shell's `TimerProvider`, never fetched.
 */

/** The bulk outcome as counts, only when something was not changed. The success toast is the shell's. */
export function bulkSummary(result: TaskBulkResult | null | undefined) {
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

type ActionError = { title: string; message: string };

/**
 * The page owns the table handle and the empty-state region (it renders both); the hook focuses them
 * once the server's answer has rendered.
 */
export function useTaskListActions(
    rows: readonly TaskRow[],
    {
        tableRef,
        emptyRegion,
    }: {
        tableRef: RefObject<DataTableHandle<number> | null>;
        emptyRegion: RefObject<HTMLDivElement | null>;
    },
) {
    const timers = useOptionalTimers();

    const [selected, setSelected] = useState<ReadonlySet<number>>(new Set());
    const [pending, setPending] = useState<ReadonlySet<number>>(new Set());
    const [assigning, setAssigning] = useState<ReadonlySet<number>>(new Set());
    const [bulkBusy, setBulkBusy] = useState(false);
    const [actionError, setActionError] = useState<ActionError | null>(null);
    const inFlight = useRef(new Set<number>());
    const assignInFlight = useRef(new Set<number>());
    const bulkInFlight = useRef(false);
    // Where focus goes once the list the server returns has rendered (Direction D §14.2): the row
    // that takes the acted-on row's place, or `'empty'` when it was the only one. `wasDone` is the
    // row's state when the request left; if the response shows it unchanged, the action did not take
    // effect (a refusal), the focus has no reason to move and the entry is dropped. An assignment
    // (`onlyIfLost`) never completes a row, so it moves focus only when the row has left the list AND
    // focus was lost with it: a row still listed keeps its own control, and a user who has moved on
    // keeps their place.
    const focusAfter = useRef<{
        task: number;
        wasDone: boolean;
        target: number | 'empty';
        onlyIfLost?: boolean;
    } | null>(null);
    // The row that last held table focus while a selection existed, and the selection size before.
    const lastRow = useRef<number | null>(null);
    const previousSelected = useRef(0);

    // Adopt a selection that still matches the page during render (the documented alternative to an
    // effect): a row that left the page, by a filter, a page change or a completed action, is no
    // longer selected, so a bulk request never names a task the user cannot see.
    const [seenRows, setSeenRows] = useState(rows);
    if (seenRows !== rows) {
        setSeenRows(rows);
        const present = new Set(rows.map((task) => task.id));
        setSelected((current) => new Set([...current].filter((id) => present.has(id))));
    }

    // Once the rows the server returned have rendered, put focus where Direction D §14.2 asks.
    useEffect(() => {
        const pendingFocus = focusAfter.current;
        if (pendingFocus === null) return;

        const row = rows.find((candidate) => candidate.id === pendingFocus.task);
        // Still there and unchanged: the server refused it. Nothing moved, so focus stays put.
        if (row && row.status.done === pendingFocus.wasDone) {
            focusAfter.current = null;

            return;
        }

        focusAfter.current = null;
        if (pendingFocus.onlyIfLost && !focusIsLost()) return;
        if (pendingFocus.target !== 'empty') {
            tableRef.current?.focusRow(pendingFocus.target);
        } else if (rows.length === 0) {
            emptyRegion.current?.focus();
        } else {
            tableRef.current?.focusFirstRow();
        }
    }, [rows, tableRef, emptyRegion]);

    const selectedRows = rows.filter((task) => selected.has(task.id));
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
            if (rows.length === 0) emptyRegion.current?.focus();
            else if (last === null || !tableRef.current?.focusRow(last))
                tableRef.current?.focusFirstRow();
        }
    }, [selectedIds.length, rows.length, tableRef, emptyRegion]);

    const runningTaskIds = useMemo(
        () =>
            new Set(
                (timers?.timers ?? []).flatMap((timer) =>
                    timer.context?.type === 'Task' ? [timer.context.id] : [],
                ),
            ),
        [timers],
    );

    function toggle(task: TaskRow) {
        if (inFlight.current.has(task.id)) return;

        inFlight.current.add(task.id);
        setPending(new Set(inFlight.current));
        setActionError(null);

        // Focus follows a Complete only when the row (or its ring) had it: the next row, or the
        // previous one at the end of the page. A pointer user elsewhere keeps their place.
        const position = rows.findIndex((candidate) => candidate.id === task.id);
        const neighbour = (rows[position + 1] ?? rows[position - 1])?.id;
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

    /** R6: one row's assignee, through the narrow endpoint. The server authorizes and validates it again. */
    function assign(task: TaskRow, userId: number | null) {
        if (assignInFlight.current.has(task.id)) return;

        assignInFlight.current.add(task.id);
        setAssigning(new Set(assignInFlight.current));
        setActionError(null);

        // A reassignment can take the row out of the list (My Tasks, or an assignee filter):
        // remember where focus should go.
        const position = rows.findIndex((candidate) => candidate.id === task.id);
        const neighbour = (rows[position + 1] ?? rows[position - 1])?.id;
        focusAfter.current = {
            task: task.id,
            wasDone: task.status.done,
            target: neighbour ?? 'empty',
            onlyIfLost: true,
        };

        router.put(
            assigneeUpdate.url(task.id),
            { assignee_id: userId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setActionError(null),
                onError: (errors) => {
                    focusAfter.current = null;
                    setActionError({
                        title: task.title,
                        message:
                            errors.assignee_id ??
                            Object.values(errors)[0] ??
                            'The assignee could not be changed.',
                    });
                },
                onFinish: () => {
                    assignInFlight.current.delete(task.id);
                    setAssigning(new Set(assignInFlight.current));
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

    return {
        selected,
        setSelected,
        selectedCount: selectedIds.length,
        canComplete,
        canReopen,
        pending,
        assigning,
        bulkBusy,
        actionError,
        clearActionError: () => setActionError(null),
        runningTaskIds,
        toggle,
        assign,
        bulk,
    };
}

export type TaskListActions = ReturnType<typeof useTaskListActions>;

/** The last action's error and the bulk outcome, between the filters and the table. */
export function TaskActionAlerts({ actions }: { actions: TaskListActions }) {
    const { flash } = usePage<SharedPageProps>().props;
    const summary = bulkSummary(flash.bulk);

    return (
        <>
            {actions.actionError ? (
                <Alert
                    variant="danger"
                    action={
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="-my-2 -mr-2"
                            aria-label="Dismiss message"
                            onClick={actions.clearActionError}
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </Button>
                    }
                >
                    Could not change “{actions.actionError.title}”: {actions.actionError.message}
                </Alert>
            ) : null}

            {summary ? (
                <Alert variant="warning" data-testid="bulk-summary">
                    {summary}
                </Alert>
            ) : null}
        </>
    );
}

/** Bulk Complete/Reopen over the selection (EPIC-014 §15.2), offered from the rows' own abilities. */
export function TaskBulkBar({ actions }: { actions: TaskListActions }) {
    return (
        <BulkBar
            count={actions.selectedCount}
            noun="task"
            label="Bulk actions"
            onClear={() => actions.setSelected(new Set())}
        >
            <Button
                type="button"
                size="sm"
                disabled={actions.bulkBusy || !actions.canComplete}
                onClick={() => actions.bulk('complete')}
            >
                Complete
            </Button>
            <Button
                type="button"
                size="sm"
                variant="secondary"
                disabled={actions.bulkBusy || !actions.canReopen}
                onClick={() => actions.bulk('reopen')}
            >
                Reopen
            </Button>
        </BulkBar>
    );
}
