import { Link } from '@inertiajs/react';
import { memo, useMemo } from 'react';
import type { ReactNode } from 'react';

import { MoveTaskMenu, type MoveTargetColumn } from '@/components/projects/move-task-menu';
import { TaskCompleteControl } from '@/components/tasks/task-complete-control';
import { TaskPriorityMark } from '@/components/tasks/task-priority';
import { Avatar } from '@/components/ui/avatar';
import { formatDate } from '@/lib/dates';
import { show as taskShowRoute } from '@/routes/projects/tasks';
import type { BoardTask } from '@/types/projects';

export type TaskCardProps = {
    task: BoardTask;
    projectId: number;
    columnId: number;
    /** 0-based position of this task within its column, for Move up/down and the announcement. */
    columnIndex: number;
    columnSize: number;
    /** Every column, for "Move to <column>"; a stable reference (see board.tsx) so memoized
     * cards are not forced to re-render just because some other column's task list changed. */
    columns: MoveTargetColumn[];
    canManage: boolean;
    /** True while any move is in flight (single-flight, EPIC-011E §8): disables every Move menu. */
    boardBusy: boolean;
    /** True only for the card whose own move or completion is in flight (`boardBusy` is board-wide). */
    taskBusy?: boolean;
    onMove: (taskId: number, toColumnId: number, toIndex: number, taskTitle: string) => void;
    /**
     * The pointer/touch drag affordance (EPIC-011E §9, WP6), already fully wired by
     * `SortableTaskCard` (`board-dnd.tsx`) — this component never imports dnd-kit itself, or
     * even knows it exists; it only ever renders whatever node it is handed, or nothing at all.
     * `undefined` for a read-only card (no `SortableTaskCard` wrapper is ever mounted for one)
     * and for every card before WP6, so this slot changes nothing about the existing render.
     */
    dragHandle?: ReactNode;
    /**
     * EPIC-015 WP5 S1 — Complete/Reopen from the card, through the Board's single-flight request
     * (`board.tsx`). A stable reference, like `onMove`. Absent: the card offers no control.
     */
    onToggleComplete?: (taskId: number, done: boolean, taskTitle: string) => void;
};

function TaskCardImpl({
    task,
    projectId,
    columnId,
    columnIndex,
    columnSize,
    columns,
    canManage,
    boardBusy,
    taskBusy = false,
    onMove,
    dragHandle,
    onToggleComplete,
}: TaskCardProps) {
    const titleId = `task-${task.id}-title`;

    // The ring is the Tasks list's own control (Direction D §10.2), fed this card's server state:
    // done is the column's answer, the abilities TaskPolicy's. It renders only when the one ability
    // that applies is true, so a viewer is never offered an action the route would refuse.
    const completable = useMemo(
        () => ({ title: task.title, status: { done: task.done }, abilities: task.abilities }),
        [task.title, task.done, task.abilities],
    );
    const canToggle =
        onToggleComplete !== undefined &&
        (task.done ? task.abilities.reopen : task.abilities.complete);

    return (
        <article
            aria-labelledby={titleId}
            data-task-id={task.id}
            className="space-y-2.5 rounded-lg border border-border bg-card p-3 text-card-foreground shadow-xs"
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex items-center gap-1.5">
                    {dragHandle}
                    <TaskPriorityMark priority={task.priority} />
                </div>
                {task.milestone ? (
                    <span
                        className="truncate text-xs text-muted-foreground"
                        title={task.milestone.name}
                    >
                        {task.milestone.name}
                    </span>
                ) : null}
            </div>

            <div className="flex items-start gap-1">
                {canToggle ? (
                    // Pulled into the card's padding so the 36px target (44px on coarse pointers)
                    // costs the title no height. A sibling of the link, never inside it, and the
                    // drag listeners live on the handle alone, so pressing it neither opens the
                    // task nor starts a drag.
                    <span className="-my-2 -ml-2 shrink-0">
                        <TaskCompleteControl
                            task={completable}
                            // The whole board is single-flight: no completion while a move or
                            // another completion is in flight (aria-disabled, focus kept), but only
                            // the card actually being mutated announces itself as busy.
                            pending={taskBusy}
                            locked={boardBusy}
                            onToggle={() => onToggleComplete(task.id, task.done, task.title)}
                        />
                    </span>
                ) : null}
                {/* Task detail is a React page as of WP7 (EPIC-011E §21): an Inertia Link,
                    prefetched since it is the board's most common next destination. */}
                <Link
                    id={titleId}
                    href={taskShowRoute.url({ project: projectId, task: task.id })}
                    prefetch
                    className="block min-w-0 text-sm leading-snug font-medium break-words hover:underline"
                >
                    {task.title}
                </Link>
            </div>

            <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <div className="flex items-center gap-2">
                    {task.checklist.total > 0 ? (
                        <span>
                            {task.checklist.done}/{task.checklist.total}
                        </span>
                    ) : null}
                    {task.dueDate ? (
                        <span
                            className={
                                task.overdue ? 'font-medium text-[var(--text-danger)]' : undefined
                            }
                        >
                            {formatDate(task.dueDate)}
                        </span>
                    ) : null}
                </div>
                {task.assignee ? (
                    <Avatar size="sm" name={task.assignee.name} title={task.assignee.name} />
                ) : null}
            </div>

            {canManage ? (
                <div className="pt-0.5">
                    <MoveTaskMenu
                        taskId={task.id}
                        taskTitle={task.title}
                        columns={columns}
                        currentColumnId={columnId}
                        currentIndex={columnIndex}
                        columnSize={columnSize}
                        disabled={boardBusy}
                        onMove={(toColumnId, toIndex) =>
                            onMove(task.id, toColumnId, toIndex, task.title)
                        }
                    />
                </div>
            ) : null}
        </article>
    );
}

function sameRef(
    a: { id: number; name: string } | null,
    b: { id: number; name: string } | null,
): boolean {
    if (a === b) return true;
    if (a === null || b === null) return false;

    return a.id === b.id && a.name === b.name;
}

function tasksEqual(a: BoardTask, b: BoardTask): boolean {
    if (a === b) return true;

    return (
        a.id === b.id &&
        a.title === b.title &&
        a.priority === b.priority &&
        a.dueDate === b.dueDate &&
        a.overdue === b.overdue &&
        a.done === b.done &&
        a.abilities.complete === b.abilities.complete &&
        a.abilities.reopen === b.abilities.reopen &&
        a.checklist.done === b.checklist.done &&
        a.checklist.total === b.checklist.total &&
        sameRef(a.assignee, b.assignee) &&
        sameRef(a.milestone, b.milestone)
    );
}

/**
 * Inertia hands the optimistic callback a deep clone of every prop, so every task object is a
 * new reference during the optimistic phase; a reference-equality `memo` re-renders every card
 * on every move (WP0 measured this). Comparing the rendered fields instead re-renders only the
 * card that actually changed. `columns`, `onMove`, and `dragHandle` are expected to be stable
 * references from the parent (`board.tsx` for the first two; `SortableTaskCard` for the third,
 * EPIC-011E §25 WP6) and are compared by identity, not deep-compared: `SortableTaskCard` only
 * hands this component a new `dragHandle` element when dnd-kit's own `listeners` reference
 * actually changes (verified stable across ordinary re-renders, including every pointer-move
 * frame during a drag, against the installed dnd-kit source — see `board-dnd.tsx`), so an
 * identity comparison here is exactly as cheap and correct as the existing two.
 */
/** Exported so a test can verify the comparator directly (see task-card.test.tsx). */
export function taskCardPropsAreEqual(prev: TaskCardProps, next: TaskCardProps): boolean {
    return (
        prev.projectId === next.projectId &&
        prev.columnId === next.columnId &&
        prev.columnIndex === next.columnIndex &&
        prev.columnSize === next.columnSize &&
        prev.canManage === next.canManage &&
        prev.boardBusy === next.boardBusy &&
        prev.taskBusy === next.taskBusy &&
        prev.columns === next.columns &&
        prev.onMove === next.onMove &&
        prev.dragHandle === next.dragHandle &&
        prev.onToggleComplete === next.onToggleComplete &&
        tasksEqual(prev.task, next.task)
    );
}

export const TaskCard = memo(TaskCardImpl, taskCardPropsAreEqual);
