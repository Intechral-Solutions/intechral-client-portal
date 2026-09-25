import { ChevronDown } from 'lucide-react';

import { APPEND_TO_END } from '@/components/projects/board-moves';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** Just enough to list a destination; never a full column with its tasks (see board.tsx). */
export type MoveTargetColumn = { id: number; name: string; isDone: boolean };

type MoveTaskMenuProps = {
    taskId: number;
    taskTitle: string;
    columns: MoveTargetColumn[];
    currentColumnId: number;
    currentIndex: number;
    columnSize: number;
    /** True while a move is in flight anywhere on the board (single-flight, EPIC-011E §8). */
    disabled: boolean;
    onMove: (toColumnId: number, toIndex: number) => void;
};

/**
 * The canonical keyboard/mobile/assistive-technology move control (EPIC-011E §10). Every
 * selection calls the same `requestMove` the pointer-drag path will call in WP6. Deliberately
 * not disabled with the native `disabled` attribute while a move is pending: the button stays a
 * tab stop (so focus can be restored to it after the move settles) and is marked `aria-disabled`
 * instead; opening is blocked at the Radix trigger's own pointer/keyboard handlers.
 */
export function MoveTaskMenu({
    taskId,
    taskTitle,
    columns,
    currentColumnId,
    currentIndex,
    columnSize,
    disabled,
    onMove,
}: MoveTaskMenuProps) {
    const otherColumns = columns.filter((column) => column.id !== currentColumnId);

    function guardOpen(event: { preventDefault: () => void }) {
        if (disabled) event.preventDefault();
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild onPointerDown={guardOpen} onKeyDown={guardOpen}>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    aria-label={`Move "${taskTitle}"`}
                    aria-disabled={disabled}
                    data-move-button={taskId}
                >
                    Move
                    <ChevronDown aria-hidden="true" className="h-3.5 w-3.5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                {otherColumns.map((column) => (
                    <DropdownMenuItem
                        key={column.id}
                        onSelect={() => onMove(column.id, APPEND_TO_END)}
                    >
                        Move to {column.name}
                        {column.isDone ? ' (done)' : ''}
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    disabled={currentIndex <= 0}
                    onSelect={() => onMove(currentColumnId, currentIndex - 1)}
                >
                    Move up
                </DropdownMenuItem>
                <DropdownMenuItem
                    disabled={currentIndex >= columnSize - 1}
                    onSelect={() => onMove(currentColumnId, currentIndex + 1)}
                >
                    Move down
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
