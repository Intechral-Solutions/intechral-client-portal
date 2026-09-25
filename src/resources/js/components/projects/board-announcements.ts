/**
 * Board live-region copy (EPIC-011E §8, §10). Pure text-building, no React: shared by the menu
 * path (WP5) and, later, the drag path (WP6), so both paths announce moves identically. Built
 * from the derived target (canonical column and position), never from a raw drag/drop event.
 */
import { locateTask } from '@/components/projects/board-moves';
import type { BoardColumn } from '@/types/projects';

/** "Moved "Fix login" to In Progress, position 2 of 5" — or null if the task cannot be found. */
export function moveSuccessMessage(
    columns: BoardColumn[],
    taskId: number,
    taskTitle: string,
): string | null {
    const placement = locateTask(columns, taskId);
    if (!placement) return null;

    const doneSuffix = placement.columnIsDone ? ' (done)' : '';

    return `Moved "${taskTitle}" to ${placement.columnName}${doneSuffix}, position ${placement.index + 1} of ${placement.columnSize}.`;
}

export const DEFAULT_MOVE_FAILURE_MESSAGE = "Couldn't save the move. Try again.";

export function moveFailureMessage(reason?: string | null): string {
    return reason && reason.trim() !== '' ? reason : DEFAULT_MOVE_FAILURE_MESSAGE;
}
