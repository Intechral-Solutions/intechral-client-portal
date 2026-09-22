import { Plus } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { SortableColumnBody, SortableTaskCard } from '@/components/projects/board-dnd';
import type { MoveTargetColumn } from '@/components/projects/move-task-menu';
import { QuickAddTask } from '@/components/projects/quick-add-task';
import { TaskCard } from '@/components/projects/task-card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { BoardColumn as BoardColumnData } from '@/types/projects';

type BoardColumnProps = {
    column: BoardColumnData;
    projectId: number;
    /** Stable summaries of every column, passed through to each card's Move menu. */
    columns: MoveTargetColumn[];
    canManage: boolean;
    boardBusy: boolean;
    quickAddOpen: boolean;
    onToggleQuickAdd: (columnId: number) => void;
    onCloseQuickAdd: () => void;
    onMove: (taskId: number, toColumnId: number, toIndex: number, taskTitle: string) => void;
};

export function BoardColumn({
    column,
    projectId,
    columns,
    canManage,
    boardBusy,
    quickAddOpen,
    onToggleQuickAdd,
    onCloseQuickAdd,
    onMove,
}: BoardColumnProps) {
    const addButtonRef = useRef<HTMLButtonElement>(null);
    const wasOpen = useRef(quickAddOpen);

    // Quick-add closes and returns focus to its own toggle (§11): the form itself unmounts on
    // close, so the toggle button — not the form — is the one stable place to send focus back to.
    useEffect(() => {
        if (wasOpen.current && !quickAddOpen) {
            addButtonRef.current?.focus();
        }
        wasOpen.current = quickAddOpen;
    }, [quickAddOpen]);

    return (
        <div
            className="flex w-72 shrink-0 flex-col rounded-xl border border-border bg-muted"
            data-column-id={column.id}
        >
            <div className="flex items-center justify-between border-b border-border px-3 py-2.5">
                <div className="flex min-w-0 items-center gap-2">
                    <span
                        aria-hidden="true"
                        className={cn(
                            'inline-block h-2 w-2 shrink-0 rounded-full',
                            column.isDone ? 'bg-[var(--accent-success,#22c55e)]' : 'bg-border',
                        )}
                    />
                    <h2 className="truncate text-sm font-medium">{column.name}</h2>
                    <span className="shrink-0 text-xs text-muted-foreground">
                        {column.tasks.length}
                    </span>
                </div>
                {canManage ? (
                    <Button
                        ref={addButtonRef}
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label={`Add task to ${column.name}`}
                        aria-expanded={quickAddOpen}
                        onClick={() => onToggleQuickAdd(column.id)}
                    >
                        <Plus aria-hidden="true" className="h-4 w-4" />
                    </Button>
                ) : null}
            </div>

            {canManage ? (
                <SortableColumnBody
                    columnId={column.id}
                    taskIds={column.tasks.map((task) => task.id)}
                    className="flex-1 space-y-2 overflow-y-auto p-2"
                >
                    {column.tasks.length === 0 ? (
                        <p className="px-1 py-4 text-center text-xs text-muted-foreground">
                            No tasks.
                        </p>
                    ) : null}
                    {column.tasks.map((task, index) => (
                        <SortableTaskCard
                            key={task.id}
                            task={task}
                            projectId={projectId}
                            columnId={column.id}
                            columnIndex={index}
                            columnSize={column.tasks.length}
                            columns={columns}
                            canManage
                            boardBusy={boardBusy}
                            disabled={boardBusy}
                            onMove={onMove}
                        />
                    ))}
                </SortableColumnBody>
            ) : (
                <div className="flex-1 space-y-2 overflow-y-auto p-2">
                    {column.tasks.length === 0 ? (
                        <p className="px-1 py-4 text-center text-xs text-muted-foreground">
                            No tasks.
                        </p>
                    ) : null}
                    {column.tasks.map((task, index) => (
                        <TaskCard
                            key={task.id}
                            task={task}
                            projectId={projectId}
                            columnId={column.id}
                            columnIndex={index}
                            columnSize={column.tasks.length}
                            columns={columns}
                            canManage={false}
                            boardBusy={boardBusy}
                            onMove={onMove}
                        />
                    ))}
                </div>
            )}

            {canManage ? (
                <QuickAddTask
                    projectId={projectId}
                    columnId={column.id}
                    open={quickAddOpen}
                    onClose={onCloseQuickAdd}
                />
            ) : null}
        </div>
    );
}
