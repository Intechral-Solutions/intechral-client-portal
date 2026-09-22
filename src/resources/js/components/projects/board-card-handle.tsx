import type { DraggableSyntheticListeners } from '@dnd-kit/core';
import { GripVertical } from 'lucide-react';
import type { CSSProperties } from 'react';

import { cn } from '@/lib/utils';

type TaskDragHandleProps = {
    taskTitle: string;
    setActivatorNodeRef: (node: HTMLElement | null) => void;
    listeners: DraggableSyntheticListeners;
    disabled: boolean;
};

// `touch-action: none` only on the handle itself (EPIC-011E §9, WP0 finding): suppressing the
// browser's own touch gestures on the whole card or board would break normal scrolling: WP0
// proved a swipe on the card body must scroll the board, not start a drag.
const handleStyle: CSSProperties = { touchAction: 'none' };

/**
 * The pointer/touch-only drag affordance (EPIC-011E §9, §10, WP6). This is a hard accessibility
 * requirement, not a preference: the handle is never a tab stop, is hidden from assistive
 * technology, is not a `<button>`, and carries no draggable/sortable role, description, or
 * keyboard instruction — nothing here may imply that Enter or Space starts a drag. The Move menu
 * (`move-task-menu.tsx`) is the only control that communicates "this task can be moved" to
 * keyboard and screen-reader users; this handle is purely a pointer/touch enhancement layered
 * on top of it, so it deliberately renders no accessible name of its own for `taskTitle` to
 * announce — the prop exists only so a future caller can build one if that ever changes.
 *
 * `listeners` are spread (the pointer/touch event bindings dnd-kit's `useSortable` produces);
 * dnd-kit's own `attributes` are never spread here or anywhere else in this adapter — that is
 * what `attributes` under the hood would have wired: `role="button"`, `tabIndex={0}`, and a
 * "press space to lift" description WP0 confirmed must not exist for this board. `taskTitle`
 * only ever reaches a plain, non-semantic `title` attribute (a mouse-hover tooltip screen
 * readers do not treat as an accessible name), never `aria-label` or similar.
 */
export function TaskDragHandle({
    taskTitle,
    setActivatorNodeRef,
    listeners,
    disabled,
}: TaskDragHandleProps) {
    return (
        <span
            ref={setActivatorNodeRef}
            data-testid="task-drag-handle"
            aria-hidden="true"
            tabIndex={-1}
            title={disabled ? undefined : `Drag to move "${taskTitle}"`}
            style={handleStyle}
            className={cn(
                'flex h-6 w-5 shrink-0 items-center justify-center rounded text-muted-foreground',
                disabled ? 'cursor-not-allowed opacity-40' : 'cursor-grab active:cursor-grabbing',
            )}
            {...(disabled ? {} : listeners)}
        >
            <GripVertical aria-hidden="true" className="h-4 w-4" />
        </span>
    );
}
