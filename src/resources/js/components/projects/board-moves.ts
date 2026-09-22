/**
 * Pure board projection (EPIC-011E §8). No React, no library imports, no Wayfinder: this module
 * only reshapes the `columns` array the server sent. It is the UI projection layer only — it
 * never reproduces the server's locking or position-assignment rules, and it never mutates its
 * input. It backs the optimistic overlay (`router.optimistic`) in WP5 and the drag preview in
 * WP6, so both paths compute the "what would the board look like" question the same way.
 */
import type { BoardColumn } from '@/types/projects';

export type MoveDescriptor = {
    taskId: number;
    toColumnId: number;
    /** 0-based; clamped to `0..column.tasks.length` (post-removal length for same-column moves). */
    toIndex: number;
};

/**
 * "Move to <column>" always appends (T1): rather than trust a possibly-stale task count read
 * from a memoized card's props, send an index past any real column length and let the same
 * clamp that handles every other out-of-range index (here, and server-side under the column
 * lock) land it at the true tail.
 */
export const APPEND_TO_END = Number.MAX_SAFE_INTEGER;

export type TaskPlacement = {
    columnId: number;
    columnName: string;
    columnIsDone: boolean;
    /** 0-based position within the column. */
    index: number;
    /** How many tasks are in the column (including this one). */
    columnSize: number;
};

/**
 * Removes the task from wherever it is and inserts it at `toIndex` (clamped) in `toColumnId`.
 * Returns new arrays; `columns` and every column/task object inside it are left untouched.
 * An unknown task id or an unknown target column id is a no-op that returns `columns` itself.
 */
export function applyMove(columns: BoardColumn[], move: MoveDescriptor): BoardColumn[] {
    let movedTask: BoardColumn['tasks'][number] | null = null;

    const withoutTask = columns.map((column) => {
        const index = column.tasks.findIndex((task) => task.id === move.taskId);
        if (index === -1) return column;

        movedTask = column.tasks[index] ?? null;

        return {
            ...column,
            tasks: [...column.tasks.slice(0, index), ...column.tasks.slice(index + 1)],
        };
    });

    if (movedTask === null) return columns;
    if (!withoutTask.some((column) => column.id === move.toColumnId)) return columns;

    return withoutTask.map((column) => {
        if (column.id !== move.toColumnId) return column;

        const clamped = Math.max(0, Math.min(move.toIndex, column.tasks.length));
        const tasks = [...column.tasks];
        tasks.splice(clamped, 0, movedTask as BoardColumn['tasks'][number]);

        return { ...column, tasks };
    });
}

/** Where a task currently sits, or `null` when no column holds it. */
export function locateTask(columns: BoardColumn[], taskId: number): TaskPlacement | null {
    for (const column of columns) {
        const index = column.tasks.findIndex((task) => task.id === taskId);

        if (index !== -1) {
            return {
                columnId: column.id,
                columnName: column.name,
                columnIsDone: column.isDone,
                index,
                columnSize: column.tasks.length,
            };
        }
    }

    return null;
}

/** True when the move would not change anything: unknown task, or already at that spot. */
export function isNoopMove(columns: BoardColumn[], move: MoveDescriptor): boolean {
    const placement = locateTask(columns, move.taskId);

    if (!placement) return true;

    return placement.columnId === move.toColumnId && placement.index === move.toIndex;
}

/** The full task record, wherever it sits, or `null` if no column holds it. */
export function findTask(
    columns: BoardColumn[],
    taskId: number,
): BoardColumn['tasks'][number] | null {
    for (const column of columns) {
        const task = column.tasks.find((candidate) => candidate.id === taskId);
        if (task) return task;
    }

    return null;
}

// ── Drag-and-drop target resolution (EPIC-011E §9, WP6) ─────────────────────
//
// dnd-kit identifies a hover/drop target (`over.id`) by whichever sortable item or droppable
// region the pointer is over. A task card's own id doubles as its dnd-kit id (both are already
// globally unique numbers); a column's *body* — the droppable that keeps an empty column, or the
// area below its last card, a valid drop target — uses a distinct string id built by
// `columnDroppableId` so it can never collide with a task id. `resolveDragTarget` turns either
// shape of `over.id` into the same `MoveDescriptor` `applyMove` and `requestMove` already use,
// so the drag path computes "what would the board look like" exactly the way the Move menu does.
// This module still imports no drag library: the adapter (`board-dnd.tsx`) extracts `active.id`/
// `over.id` from dnd-kit's own event objects and passes plain `string | number` values in.

export type DragTargetId = string | number;

/** The droppable id for a column's own body (empty space and the area below the last card). */
export function columnDroppableId(columnId: number): string {
    return `column-${columnId}`;
}

function parseColumnDroppableId(id: DragTargetId): number | null {
    if (typeof id !== 'string') return null;

    const match = /^column-(\d+)$/.exec(id);

    return match ? Number(match[1]) : null;
}

/**
 * Resolves a dnd-kit hover/drop target into a `MoveDescriptor`, or `null` when it cannot be
 * resolved (an unrelated, foreign, or missing target — the caller treats that as no valid
 * destination and leaves the board unchanged).
 *
 * - `over` names a column body: append to that column (its current length with the dragged task
 *   excluded, if it happens to already be there) — this is what makes an empty column, and the
 *   area below the last card, valid drop targets.
 * - `over` names another task: target that task's own column, at that task's own original index.
 *   `applyMove` (like `@dnd-kit/sortable`'s own `arrayMove` utility, which this deliberately
 *   matches) removes the active task first and inserts into the resulting, already-shorter
 *   array, so the hovered task's own original index is already the correct post-removal
 *   position — it needs no further shift correction, and applying one (an earlier version of
 *   this function did) makes an adjacent forward drag — the most common single-step
 *   reorder — silently collapse into a no-op, since "insert immediately before the very next
 *   card" reconstructs the original order. The natural result of the uncorrected index is a
 *   clean swap for an adjacent drag in either direction, and "after the hovered card" for a
 *   forward drag of more than one slot (exactly `arrayMove`'s own behavior).
 * - `over` names the dragged task itself (its own placeholder): resolves to its current spot,
 *   which `isNoopMove` then recognizes as no change.
 */
export function resolveDragTarget(
    columns: BoardColumn[],
    activeTaskId: number,
    overId: DragTargetId | null,
): MoveDescriptor | null {
    if (overId === null) return null;

    const activePlacement = locateTask(columns, activeTaskId);
    if (!activePlacement) return null;

    const targetColumnId = parseColumnDroppableId(overId);

    if (targetColumnId !== null) {
        const column = columns.find((candidate) => candidate.id === targetColumnId);
        if (!column) return null;

        const withoutActive = column.tasks.filter((task) => task.id !== activeTaskId);

        return { taskId: activeTaskId, toColumnId: targetColumnId, toIndex: withoutActive.length };
    }

    if (overId === activeTaskId) {
        return {
            taskId: activeTaskId,
            toColumnId: activePlacement.columnId,
            toIndex: activePlacement.index,
        };
    }

    const overPlacement = locateTask(columns, overId as number);
    if (!overPlacement) return null;

    return {
        taskId: activeTaskId,
        toColumnId: overPlacement.columnId,
        toIndex: overPlacement.index,
    };
}
