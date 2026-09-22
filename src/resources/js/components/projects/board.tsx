import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { moveFailureMessage, moveSuccessMessage } from '@/components/projects/board-announcements';
import { BoardColumn } from '@/components/projects/board-column';
import { applyMove, isNoopMove } from '@/components/projects/board-moves';
import { move as moveTaskRoute } from '@/routes/projects/tasks';
import type { BoardColumn as BoardColumnData } from '@/types/projects';

type BoardProps = {
    projectId: number;
    columns: BoardColumnData[];
    abilities: { manage: boolean };
};

/**
 * The interactive kanban board (EPIC-011E §7, §8). The `columns` prop — the server's Inertia
 * page prop — is the only durable board state; this component keeps no copy of it. Everything
 * else here is a short-lived interaction descriptor: which task is mid-move, which column's
 * quick-add form is open, and the two live-region strings.
 */
export function Board({ projectId, columns, abilities }: BoardProps) {
    const [pendingTaskId, setPendingTaskId] = useState<number | null>(null);
    const [quickAddColumnId, setQuickAddColumnId] = useState<number | null>(null);
    const [politeMessage, setPoliteMessage] = useState('');
    const [alertMessage, setAlertMessage] = useState('');

    // A guard read synchronously by requestMove, so a second call in the same tick (before a
    // setState from the first has flushed) is still refused. Single flight is mandatory, not
    // defensive (EPIC-011E §8, WP0 gate item 4): sending overlapping moves lets the server
    // apply them out of order.
    const pendingRef = useRef<number | null>(null);

    // "Latest value" refs for the stable requestMove callback below: updated in an effect after
    // every commit, never included in a dependency array. This is how requestMove reads current
    // props without becoming a new function on every render, which would defeat TaskCard's
    // structural memo (its comparator treats `onMove` as a stable identity, exactly like
    // `columnSummaries` below). requestMove only ever runs from a later user interaction, after
    // the effect has run, so there is no meaningful staleness window.
    const columnsRef = useRef(columns);
    const abilitiesRef = useRef(abilities);
    const projectIdRef = useRef(projectId);
    useEffect(() => {
        columnsRef.current = columns;
        abilitiesRef.current = abilities;
        projectIdRef.current = projectId;
    });

    // The column list every card's Move menu offers. Recomputed only when a column is actually
    // added, removed, or renamed — not on every move — so it stays a stable reference across
    // moves and does not defeat TaskCard's structural memo (EPIC-011E §25).
    const columnsSignature = columns
        .map((column) => `${column.id}:${column.name}:${column.isDone}`)
        .join('|');
    const columnSummaries = useMemo(
        () =>
            columns.map((column) => ({ id: column.id, name: column.name, isDone: column.isDone })),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- content-keyed on purpose; `columns` itself is not a dependency
        [columnsSignature],
    );

    // Stable across every render (empty dependency array): TaskCard's memo comparator compares
    // this prop by identity, so it must never be a fresh closure. Transient values it needs
    // (whether a move is already pending) are read from the ref above, not from a dependency.
    const requestMove = useCallback(
        (taskId: number, toColumnId: number, toIndex: number, taskTitle: string) => {
            if (pendingRef.current !== null || !abilitiesRef.current.manage) return;

            const move = { taskId, toColumnId, toIndex };
            if (isNoopMove(columnsRef.current, move)) return;

            pendingRef.current = taskId;
            setPendingTaskId(taskId);
            setAlertMessage('');

            const reconcileAfterFailure = () => router.reload({ only: ['columns'] });

            router
                .optimistic((props: { columns: BoardColumnData[] }) => ({
                    columns: applyMove(props.columns, move),
                }))
                .put(
                    moveTaskRoute.url({ project: projectIdRef.current, task: taskId }),
                    { column_id: toColumnId, position: toIndex },
                    {
                        only: ['columns', 'flash'],
                        preserveScroll: true,
                        preserveState: true,
                        onSuccess: (page) => {
                            const resultColumns = (page.props.columns ??
                                columnsRef.current) as BoardColumnData[];
                            setPoliteMessage(
                                moveSuccessMessage(resultColumns, taskId, taskTitle) ??
                                    `Moved "${taskTitle}".`,
                            );
                        },
                        // A validation failure (422): the field error is the useful message;
                        // reload because the client's guessed index may itself now be stale.
                        onError: (errors) => {
                            setAlertMessage(
                                moveFailureMessage(errors.column_id ?? errors.position),
                            );
                            reconcileAfterFailure();
                        },
                        // 403/404/419/401/5xx and any other non-Inertia error response. Returning
                        // false suppresses Inertia's default error modal so the inline alert (this
                        // assertive live region) is what the user sees (EPIC-011E §8).
                        onHttpException: (response: { status: number }) => {
                            if (response.status === 401 || response.status === 419) {
                                // Same session-expiry handling as EPIC-011D: the rollback this
                                // component would otherwise perform can itself be stale once the
                                // session is gone, so a full reload is the only correct recovery.
                                window.location.reload();

                                return false;
                            }

                            setAlertMessage(
                                moveFailureMessage(
                                    response.status === 404
                                        ? 'This task no longer exists.'
                                        : undefined,
                                ),
                            );
                            reconcileAfterFailure();

                            return false;
                        },
                        onNetworkError: () => {
                            setAlertMessage(moveFailureMessage());
                            reconcileAfterFailure();

                            return false;
                        },
                        onFinish: () => {
                            pendingRef.current = null;
                            setPendingTaskId(null);

                            // The rollback (if any) has already been applied by Inertia's own
                            // onFinish wrapper by the time this callback runs (it replays
                            // baselines before calling back out), and the success path's props
                            // update happens before onSuccess above. Either way, the task's
                            // canonical location — not the pre-move one — is what focus follows
                            // (EPIC-011E §8, §10): a stable id lookup, never DOM position.
                            document
                                .querySelector<HTMLElement>(`[data-move-button="${taskId}"]`)
                                ?.focus();
                        },
                    },
                );
        },
        [],
    );

    function toggleQuickAdd(columnId: number) {
        setQuickAddColumnId((current) => (current === columnId ? null : columnId));
    }

    return (
        <div>
            <div
                role="region"
                aria-label="Kanban board"
                aria-busy={pendingTaskId !== null}
                tabIndex={0}
                className="flex gap-4 overflow-x-auto pb-2"
            >
                {columns.map((column) => (
                    <BoardColumn
                        key={column.id}
                        column={column}
                        projectId={projectId}
                        columns={columnSummaries}
                        canManage={abilities.manage}
                        boardBusy={pendingTaskId !== null}
                        quickAddOpen={quickAddColumnId === column.id}
                        onToggleQuickAdd={toggleQuickAdd}
                        onCloseQuickAdd={() => setQuickAddColumnId(null)}
                        onMove={requestMove}
                    />
                ))}
            </div>

            {alertMessage ? (
                <p
                    role="alert"
                    className="mt-4 rounded-md border border-[var(--border-danger)] bg-[var(--surface-danger)] px-4 py-3 text-sm text-[var(--text-danger)]"
                >
                    {alertMessage}
                </p>
            ) : null}

            {/* One board-level live region, shared by the menu path here and the drag path in
                WP6, rather than one per card (EPIC-011E §8, §10). */}
            <p aria-live="polite" className="sr-only">
                {politeMessage}
            </p>
        </div>
    );
}
