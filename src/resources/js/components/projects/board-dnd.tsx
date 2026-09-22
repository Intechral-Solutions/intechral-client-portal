/**
 * The one file (with `board-card-handle.tsx`) allowed to import `@dnd-kit/*` (EPIC-011E §9, WP6;
 * enforced by an ESLint `no-restricted-imports` rule everywhere else). This adapter exposes only
 * library-agnostic pieces to the rest of the board: `BoardDndContext` (a render-prop component
 * taking `columns`/`onMove` — the exact `requestMove` the Move menu already calls), `SortableColumnBody`
 * (wraps a column's task list so an empty column, or the space below its last card, is a valid
 * drop target), and `SortableTaskCard` (wraps the existing, still fully memoized `TaskCard` with
 * dnd-kit's per-item wiring). No dnd-kit type — `UniqueIdentifier`, `DragEndEvent`, or similar —
 * crosses back out of this file into a domain component; `TaskCard` only ever receives a rendered
 * `dragHandle` node, never dnd-kit's `attributes`/`listeners`/`setActivatorNodeRef` themselves.
 *
 * Pointer/touch only: no `KeyboardSensor` is configured anywhere in this file (EPIC-011E §9,
 * §10 — a hard, locked design decision). The Move menu remains the sole keyboard and assistive-
 * technology path; every operation drag can perform must also be reachable through it.
 *
 * This file keeps no durable board state, and — deliberately, see "Why no drag-time board
 * projection" below — no transient projected-board state either. `columns` is read straight from
 * props; the only local state is `activeId` (which task, if any, is being dragged — drives the
 * `DragOverlay` ghost only).
 *
 * ### Why no drag-time board projection (deviation from the original §9/§8 plan, EPIC-011E
 * Amendment 7)
 *
 * §8/§9 originally called for an `onDragOver`-updated `{taskId,toColumnId,toIndex}` preview,
 * rendered live through `applyMove` so the underlying board visibly reordered *during* the drag
 * (the same idea the Move menu's optimistic phase already uses for a *settled* selection). Built
 * and tested in isolation (Vitest, dnd-kit mocked) it worked; in a real browser it reproducibly
 * crashed the board with React error #185 ("Maximum update depth exceeded") on any drag that
 * ended over a valid target. Root cause, traced through a Playwright trace's network/console
 * capture: `applyMove`'s projection reorders the `columns` array during the drag, which React
 * reconciles into real DOM node moves (reparenting a card from one column's subtree to
 * another's); dnd-kit's own internal active-node-rect tracking (`useRect` in `@dnd-kit/core`)
 * watches `document.body` with a `MutationObserver({childList: true, subtree: true})` specifically
 * so it can remeasure when the DOM changes under it, and remeasuring triggers a state update that
 * can trigger another render, which can move the node again, which fires the observer again —
 * live-reordering the DOM *is* the kind of body-wide childList mutation that mechanism is watching
 * for. WP0's throwaway spike measured this pattern as passing, but with much simpler card markup
 * and no live region/Move menu/badges alongside it; this board's real DOM is apparently enough to
 * turn that same interaction into a genuine, reproducible loop rather than a self-terminating one.
 *
 * The fix removes the live projection rather than trying to out-guess dnd-kit's internal
 * measurement cache: the `DragOverlay` ghost (a `createPortal`, mounted once per drag, not
 * repeatedly) already gives the user a "you are carrying this card" visual, and once the drop
 * resolves, `onMove` (`requestMove`) drives its own already-proven optimistic transform exactly
 * as the Move menu does — so the underlying board still reorders smoothly on drop, just not
 * continuously during the drag. This is a smaller surface with fewer moving parts, not a
 * workaround: it deletes an entire local-state category (the preview descriptor and its
 * clear-on-settle effect) rather than adding one.
 */
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    closestCorners,
    defaultDropAnimationSideEffects,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type {
    Announcements,
    DragEndEvent,
    DragStartEvent,
    DropAnimation,
    ScreenReaderInstructions,
} from '@dnd-kit/core';
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useEffect, useMemo, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';

import { TaskDragHandle } from '@/components/projects/board-card-handle';
import {
    columnDroppableId,
    findTask,
    isNoopMove,
    resolveDragTarget,
} from '@/components/projects/board-moves';
import { PriorityBadge } from '@/components/projects/priority-badge';
import { TaskCard, type TaskCardProps } from '@/components/projects/task-card';
import { cn } from '@/lib/utils';
import type { BoardColumn, BoardTask } from '@/types/projects';

// ── Reduced motion (EPIC-011E §9, WP0 finding) ───────────────────────────────

// `window.matchMedia` is absent in some test/SSR-adjacent environments (jsdom does not
// implement it); treated the same as "no preference" there, matching every real browser this
// board ships to.
function supportsMatchMedia(): boolean {
    return typeof window !== 'undefined' && typeof window.matchMedia === 'function';
}

function prefersReducedMotion(): boolean {
    return supportsMatchMedia() && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function useReducedMotion(): boolean {
    const [reduced, setReduced] = useState(prefersReducedMotion);

    useEffect(() => {
        if (!supportsMatchMedia()) return;

        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const listener = () => setReduced(query.matches);

        query.addEventListener('change', listener);

        return () => query.removeEventListener('change', listener);
    }, []);

    return reduced;
}

// ── Announcements (EPIC-011E §9) ─────────────────────────────────────────────
//
// dnd-kit's own built-in announcer would otherwise speak generic, non-project-vocabulary text
// synchronously at each drag phase — before the server has confirmed anything, since a drop is
// only ever provisional until requestMove's own response lands. The board's one live region
// (board.tsx), which announces only genuinely confirmed outcomes and is shared by both paths
// already, remains the canonical surface; dnd-kit's phase-by-phase announcements are silenced
// here rather than left to compete with it.
const silentAnnouncements: Announcements = {
    onDragStart: () => '',
    onDragOver: () => '',
    onDragEnd: () => '',
    onDragCancel: () => '',
};

// No keyboard instruction text is ever emitted (EPIC-011E §9, §10, hard requirement): the Move
// menu is the sole keyboard/assistive-technology path, and nothing dnd-kit-authored may imply
// otherwise, even to a screen reader that happens to land on the board region.
const silentScreenReaderInstructions: ScreenReaderInstructions = { draggable: '' };

// ── BoardDndContext ───────────────────────────────────────────────────────────

type BoardDndContextProps = {
    /** No `DndContext`, sensors, or dnd-kit machinery is mounted at all unless true (D1,
     * EPIC-011E §9: "no DndContext sensors unless abilities.manage"). */
    enabled: boolean;
    columns: BoardColumn[];
    /** True while a move is in flight (single-flight, EPIC-011E §8): drag activation and drop
     * are both refused while true (belt and suspenders — `onMove` already refuses on its own). */
    busy: boolean;
    /** The exact `requestMove` the Move menu already calls (EPIC-011E §8): drag produces a move
     * intent and hands it to this function, and nothing else. There is no second mutation path. */
    onMove: (taskId: number, toColumnId: number, toIndex: number, taskTitle: string) => void;
    /** Renders the board. Always the real `columns` prop — see the file header ("Why no drag-time
     * board projection") for why this render-prop shape (kept from the original design) no longer
     * ever substitutes a projected array. */
    children: (displayColumns: BoardColumn[]) => ReactNode;
};

export function BoardDndContext({
    enabled,
    columns,
    busy,
    onMove,
    children,
}: BoardDndContextProps) {
    const [activeId, setActiveId] = useState<number | null>(null);
    const reducedMotion = useReducedMotion();

    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        // No KeyboardSensor: see the file header. This is not an omission to revisit later.
    );

    const dropAnimation: DropAnimation | null = reducedMotion
        ? null
        : {
              sideEffects: defaultDropAnimationSideEffects({
                  styles: { active: { opacity: '0.4' } },
              }),
          };

    function handleDragStart(event: DragStartEvent) {
        setActiveId(event.active.id as number);
    }

    function handleDragEnd(event: DragEndEvent) {
        const activeTaskId = event.active.id as number;
        const target = resolveDragTarget(columns, activeTaskId, event.over?.id ?? null);

        setActiveId(null);

        // Busy, unresolvable, or a drop back at the effective original position: no request, and
        // no fake announcement of a move that never happened (EPIC-011E §9, WP6).
        if (busy || !target || isNoopMove(columns, target)) return;

        const task = findTask(columns, activeTaskId);
        if (!task) return;

        // A real move: `onMove` (requestMove) owns everything from here — its own optimistic
        // transform, single-flight guard, failure reconciliation, focus restoration, and
        // live-region announcement. This is the only call to it in this file.
        onMove(activeTaskId, target.toColumnId, target.toIndex, task.title);
    }

    function handleDragCancel() {
        setActiveId(null);
    }

    if (!enabled) {
        return <>{children(columns)}</>;
    }

    const activeTask = activeId !== null ? findTask(columns, activeId) : null;

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCorners}
            accessibility={{
                announcements: silentAnnouncements,
                screenReaderInstructions: silentScreenReaderInstructions,
            }}
            onDragStart={handleDragStart}
            onDragEnd={handleDragEnd}
            onDragCancel={handleDragCancel}
        >
            {children(columns)}
            <DragOverlay dropAnimation={dropAnimation}>
                {activeTask ? <TaskCardOverlayPreview task={activeTask} /> : null}
            </DragOverlay>
        </DndContext>
    );
}

/**
 * Purely visual (EPIC-011E §9): `aria-hidden` on the whole node, no link, no button, no id that
 * could collide with the real card's (`task-${id}-title` etc. are never rendered here), and no
 * interactive control. The real, accessible card stays mounted at its `SortableTaskCard` slot for
 * the whole drag (dimmed, see below) — this is a second, decorative "ghost" that follows the
 * pointer, not a second announcement of the task.
 */
function TaskCardOverlayPreview({ task }: { task: BoardTask }) {
    return (
        <div
            aria-hidden="true"
            className="w-72 space-y-2.5 rounded-lg border border-border bg-card p-3 text-card-foreground shadow-lg"
        >
            <PriorityBadge priority={task.priority} />
            <p className="text-sm leading-snug font-medium">{task.title}</p>
        </div>
    );
}

// ── SortableColumnBody ────────────────────────────────────────────────────────

type SortableColumnBodyProps = {
    columnId: number;
    /** Every task id currently in this column, for `SortableContext`'s own sorting strategy. */
    taskIds: number[];
    className: string;
    children: ReactNode;
};

/**
 * Wraps a column's task list so it is a valid drop target even with zero cards, or when the
 * pointer is below the last one (EPIC-011E §9, WP0-proven): `useDroppable` on the column body
 * itself, under a `SortableContext` scoped to just this column's own task ids.
 */
export function SortableColumnBody({
    columnId,
    taskIds,
    className,
    children,
}: SortableColumnBodyProps) {
    const { setNodeRef } = useDroppable({ id: columnDroppableId(columnId) });

    return (
        <SortableContext items={taskIds} strategy={verticalListSortingStrategy}>
            <div ref={setNodeRef} className={className}>
                {children}
            </div>
        </SortableContext>
    );
}

// ── SortableTaskCard ──────────────────────────────────────────────────────────

type SortableTaskCardProps = Omit<TaskCardProps, 'dragHandle'> & {
    /** True while a move is in flight anywhere on the board: this card cannot start a new drag
     * (EPIC-011E §9 "prefer preventing activation while board move state is busy"). */
    disabled: boolean;
};

/**
 * The narrow wrapper dnd-kit's per-card churn is confined to (EPIC-011E §25, WP6): this
 * component itself re-renders freely (every pointer-move frame during a drag changes `transform`),
 * but it hands the memoized, presentational `TaskCard` beneath it a `dragHandle` element that
 * only changes identity when dnd-kit's own `listeners` does — verified stable across ordinary
 * re-renders against the installed 6.3.1/10.0.0 source (both are internally `useMemo`d on inputs
 * that do not change per frame) — so `TaskCard`'s structural memo is not defeated by mounting
 * this wrapper around it.
 */
export function SortableTaskCard({ disabled, ...cardProps }: SortableTaskCardProps) {
    const { task } = cardProps;
    const { listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } =
        useSortable({
            id: task.id,
            disabled,
        });

    // dnd-kit's own `attributes` are intentionally never read or spread here (EPIC-011E §9, §10):
    // they carry `role="button"`, `tabIndex={0}`, and a keyboard instruction, none of which this
    // board's pointer-only handle may expose.
    const style: CSSProperties = {
        transform: CSS.Transform.toString(transform),
        transition: transition ?? undefined,
    };

    const dragHandle = useMemo(
        () => (
            <TaskDragHandle
                taskTitle={task.title}
                setActivatorNodeRef={setActivatorNodeRef}
                listeners={listeners}
                disabled={disabled}
            />
        ),
        [setActivatorNodeRef, listeners, disabled, task.title],
    );

    return (
        <div ref={setNodeRef} style={style} className={cn(isDragging && 'opacity-40')}>
            <TaskCard {...cardProps} dragHandle={dragHandle} />
        </div>
    );
}
