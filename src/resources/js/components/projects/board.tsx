import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { moveFailureMessage, moveSuccessMessage } from '@/components/projects/board-announcements';
import { BoardColumn } from '@/components/projects/board-column';
import { BoardDndContext } from '@/components/projects/board-dnd';
import { applyMove, isNoopMove } from '@/components/projects/board-moves';
import { move as moveTaskRoute } from '@/routes/projects/tasks';
import { complete as completeTaskRoute, reopen as reopenTaskRoute } from '@/routes/tasks';
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
    // apply them out of order. On a failure this guard is held not just for the PUT itself but
    // through its authoritative reconciliation reload too (EPIC-011E Amendment 6, Fix 1):
    // releasing it as soon as the PUT settles would let a second move start while a stale
    // reconciliation read for the first move was still in flight, and a late-arriving stale read
    // could then overwrite the second move's fresher canonical state. Holding the guard through
    // the reload makes that overlap structurally impossible instead of racing it.
    const pendingRef = useRef<number | null>(null);

    // Defense in depth alongside the guard above: a monotonic token identifying which move is
    // currently allowed to publish UI side effects (live-region text, focus restoration).
    // Because the guard above already prevents two moves from ever being in flight at once, this
    // should never actually matter in practice — but it makes "a stale callback cannot publish
    // after a newer move supersedes it" an explicit, testable invariant rather than an
    // accident of the guard's current shape, and it stays correct even if a future caller (WP6)
    // reaches these callbacks by a different path.
    const moveGenerationRef = useRef(0);

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

            const generation = ++moveGenerationRef.current;
            const isCurrentMove = () => moveGenerationRef.current === generation;

            pendingRef.current = taskId;
            setPendingTaskId(taskId);
            setAlertMessage('');

            // Set only on a failure path, before this PUT's own onFinish runs (Inertia calls
            // onError/onHttpException/onNetworkError before onFinish for the same visit): tells
            // onFinish below to leave the single-flight guard held for the reconciliation
            // reload to release instead of releasing it itself (Fix 1).
            let awaitingReconciliation = false;

            const settleMove = () => {
                pendingRef.current = null;
                setPendingTaskId(null);
            };

            // The task's canonical location — not the pre-move one — is what focus follows
            // (EPIC-011E §8, §10): a stable id lookup, never DOM position.
            const restoreFocus = () => {
                document.querySelector<HTMLElement>(`[data-move-button="${taskId}"]`)?.focus();
            };

            // The authoritative re-read after a failure. The single-flight guard stays held
            // (pendingRef is released by this reload's own onFinish, not the PUT's) so a second
            // move cannot start while this read for the FIRST move is still outstanding — the
            // exact overlap that previously let a stale reconciliation overwrite a newer move's
            // canonical result (EPIC-011E Amendment 6, Fix 1). This trades a slightly longer busy
            // window after a failure (one extra GET, not the whole UI) for making the race
            // structurally impossible instead of racing it with a version check.
            const reconcileAfterFailure = () => {
                awaitingReconciliation = true;

                router.reload({
                    only: ['columns'],
                    onFinish: () => {
                        settleMove();
                        if (isCurrentMove()) restoreFocus();
                    },
                });
            };

            try {
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
                                if (!isCurrentMove()) return;

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
                                if (isCurrentMove()) {
                                    setAlertMessage(
                                        moveFailureMessage(errors.column_id ?? errors.position),
                                    );
                                }
                                reconcileAfterFailure();
                            },
                            // 403/404/419/401/5xx and any other non-Inertia error response.
                            // Returning false suppresses Inertia's default error modal so the
                            // inline alert (this assertive live region) is what the user sees
                            // (EPIC-011E §8).
                            onHttpException: (response: { status: number }) => {
                                if (response.status === 401 || response.status === 419) {
                                    // Same session-expiry handling as EPIC-011D: the rollback
                                    // this component would otherwise perform can itself be stale
                                    // once the session is gone, so a full reload is the only
                                    // correct recovery. The page is about to unload, so settling
                                    // the guard here (rather than deferring to a reconciliation
                                    // reload that will never get the chance to run) is enough.
                                    window.location.reload();

                                    return false;
                                }

                                if (isCurrentMove()) {
                                    setAlertMessage(
                                        moveFailureMessage(
                                            response.status === 404
                                                ? 'This task no longer exists.'
                                                : undefined,
                                        ),
                                    );
                                }
                                reconcileAfterFailure();

                                return false;
                            },
                            onNetworkError: () => {
                                if (isCurrentMove()) setAlertMessage(moveFailureMessage());
                                reconcileAfterFailure();

                                return false;
                            },
                            onFinish: () => {
                                // Success and the 401/419 short-circuit above both settle
                                // immediately here. Every other failure already set
                                // awaitingReconciliation and defers settling to
                                // reconcileAfterFailure's own reload above, so the guard stays
                                // held for the whole reload rather than being released early.
                                if (!awaitingReconciliation) {
                                    settleMove();
                                    if (isCurrentMove()) restoreFocus();
                                }
                            },
                        },
                    );
            } catch {
                // A synchronous throw before Inertia ever started the visit (building the move
                // URL, or router.optimistic/.put itself): there is no visit left for onFinish to
                // settle, so this is the only chance to release the guard (Fix 3). Handled as an
                // ordinary failed move — the inline alert, not a rethrow — consistent with every
                // other failure path here resolving locally rather than surfacing a second,
                // uncontrolled error path. A throw from inside the optimistic transform itself
                // (applyMove, which is pure and exhaustively tested) happens later, inside
                // Inertia's own async visit handling, and is out of this catch's reach.
                settleMove();
                setAlertMessage(moveFailureMessage());
                if (isCurrentMove()) restoreFocus();
            }
        },
        [],
    );

    /**
     * EPIC-015 WP5 S1 — Complete/Reopen from a card. It calls the same `tasks.complete` /
     * `tasks.reopen` endpoints as the Tasks list and task detail, so there is no board-specific
     * completion rule: the server moves the task into the Done column (Reopen: to the tail of the
     * first open column, EPIC-014 §8) and the redirect's `columns` are rendered as they arrive.
     * Nothing is presented optimistically (Direction D §15.5: completion with side effects stays
     * pending until confirmed), and React never moves a card itself.
     *
     * It shares the move path's single-flight guard and generation token, so a completion can never
     * overlap a move or another completion, and a second press in the same tick is refused before
     * any state flushes. A refusal is shown in the board's own alert, then the board is re-read
     * (holding the guard through that read, as a failed move does) so it is canonical again.
     */
    const requestCompletion = useCallback((taskId: number, done: boolean, taskTitle: string) => {
        if (pendingRef.current !== null) return;

        const generation = ++moveGenerationRef.current;
        const isCurrent = () => moveGenerationRef.current === generation;

        pendingRef.current = taskId;
        setPendingTaskId(taskId);
        setAlertMessage('');

        let awaitingReconciliation = false;

        const settle = () => {
            pendingRef.current = null;
            setPendingTaskId(null);
        };

        // The card remounts in the column the server put it in, so focus follows the task by id to
        // its control there (now named for the opposite action). Repairs focus only if it was the
        // moved card's to lose: it was inside that card when the press happened, and the remount has
        // left it nowhere. A pointer press that left focus on the page body (Safari does not focus a
        // clicked button) or on something else never had meaningful focus here, so none is manufactured
        // (the Tasks list's philosophy). Checked now and again after the next frame, once the new
        // columns have committed.
        const focusWasOnCard = !!document.activeElement?.closest(`[data-task-id="${taskId}"]`);
        const focusIfLost = () => {
            if (!focusWasOnCard) return;

            const active = document.activeElement;

            if (active && active !== document.body && active.isConnected) return;

            document
                .querySelector<HTMLElement>(`[data-task-id="${taskId}"] [data-task-complete]`)
                ?.focus();
        };
        const restoreFocus = () => {
            focusIfLost();
            requestAnimationFrame(focusIfLost);
        };

        const fail = (message?: string) => {
            if (!isCurrent()) return;

            setAlertMessage(
                message ?? `"${taskTitle}" could not be ${done ? 'reopened' : 'completed'}.`,
            );
        };

        const reconcileAfterFailure = () => {
            awaitingReconciliation = true;

            router.reload({
                only: ['columns'],
                onFinish: () => {
                    settle();
                    if (isCurrent()) restoreFocus();
                },
            });
        };

        try {
            router.put(
                done ? reopenTaskRoute.url(taskId) : completeTaskRoute.url(taskId),
                {},
                {
                    only: ['columns', 'flash'],
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        if (isCurrent()) {
                            setPoliteMessage(`${done ? 'Reopened' : 'Completed'} "${taskTitle}".`);
                        }
                    },
                    // A refusal on the operation's key, e.g. a board without exactly one Done
                    // column (EPIC-014 INV-8): the server's own words.
                    onError: (errors) => {
                        fail(errors.complete ?? errors.reopen ?? Object.values(errors)[0]);
                        reconcileAfterFailure();
                    },
                    onHttpException: (response: { status: number }) => {
                        if (response.status === 401 || response.status === 419) {
                            window.location.reload();

                            return false;
                        }

                        fail(response.status === 404 ? 'This task no longer exists.' : undefined);
                        reconcileAfterFailure();

                        return false;
                    },
                    onNetworkError: () => {
                        fail();
                        reconcileAfterFailure();

                        return false;
                    },
                    onFinish: () => {
                        if (!awaitingReconciliation) {
                            settle();
                            if (isCurrent()) restoreFocus();
                        }
                    },
                },
            );
        } catch {
            settle();
            fail();
        }
    }, []);

    function toggleQuickAdd(columnId: number) {
        setQuickAddColumnId((current) => (current === columnId ? null : columnId));
    }

    const boardBusy = pendingTaskId !== null;

    return (
        <div>
            <BoardDndContext
                enabled={abilities.manage}
                columns={columns}
                busy={boardBusy}
                onMove={requestMove}
            >
                {(displayColumns) => (
                    <div
                        role="region"
                        aria-label="Kanban board"
                        aria-busy={boardBusy}
                        tabIndex={0}
                        className="flex gap-4 overflow-x-auto pb-2"
                    >
                        {displayColumns.map((column) => (
                            <BoardColumn
                                key={column.id}
                                column={column}
                                projectId={projectId}
                                columns={columnSummaries}
                                canManage={abilities.manage}
                                boardBusy={boardBusy}
                                pendingTaskId={pendingTaskId}
                                quickAddOpen={quickAddColumnId === column.id}
                                onToggleQuickAdd={toggleQuickAdd}
                                onCloseQuickAdd={() => setQuickAddColumnId(null)}
                                onMove={requestMove}
                                onToggleComplete={requestCompletion}
                            />
                        ))}
                    </div>
                )}
            </BoardDndContext>

            {alertMessage ? (
                <p
                    role="alert"
                    className="mt-4 rounded-md border border-[var(--border-danger)] bg-[var(--surface-danger)] px-4 py-3 text-sm text-[var(--text-danger)]"
                >
                    {alertMessage}
                </p>
            ) : null}

            {/* One board-level live region, shared by the Move menu and the pointer-drag path
                (EPIC-011E §8, §9, §10): dnd-kit's own announcements are silenced in
                board-dnd.tsx specifically so this stays the only surface. */}
            <p aria-live="polite" className="sr-only">
                {politeMessage}
            </p>
        </div>
    );
}
