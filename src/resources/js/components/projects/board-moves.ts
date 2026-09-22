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
