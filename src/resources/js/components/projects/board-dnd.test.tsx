import { act, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import type { MoveTargetColumn } from '@/components/projects/move-task-menu';
import type { BoardColumn, BoardTask } from '@/types/projects';

/**
 * dnd-kit's own drag physics (pointer geometry, collision detection, autoscroll) are not
 * simulated here — that is what Playwright's real-browser flows are for. This file mocks
 * dnd-kit at the adapter boundary instead (EPIC-011E §9, WP6 remediation instructions) and
 * drives `board-dnd.tsx`'s own configuration and lifecycle logic directly: what it configures
 * `DndContext` with, what it does with `onDragStart`/`onDragOver`/`onDragEnd`/`onDragCancel`,
 * and how `SortableTaskCard` wires a card without defeating its structural memo.
 */
const dndContextProps = { current: null as Record<string, unknown> | null };
const dragOverlayProps = { current: null as Record<string, unknown> | null };
const droppableCalls: unknown[] = [];
const sortableCalls: { id: number; disabled: boolean }[] = [];
const sortableReturn = {
    listeners: { onPointerDown: vi.fn() },
    setActivatorNodeRef: vi.fn(),
    setNodeRef: vi.fn(),
    transform: null as { x: number; y: number } | null,
    transition: undefined as string | undefined,
    isDragging: false,
};

vi.mock('@dnd-kit/core', () => ({
    DndContext: (props: Record<string, unknown>) => {
        dndContextProps.current = props;

        return props.children as ReactNode;
    },
    DragOverlay: (props: Record<string, unknown>) => {
        dragOverlayProps.current = props;

        return <div data-testid="drag-overlay">{props.children as ReactNode}</div>;
    },
    useDroppable: (options: unknown) => {
        droppableCalls.push(options);

        return { setNodeRef: vi.fn() };
    },
    useSensor: vi.fn((sensor: unknown, options: unknown) => ({ sensor, options })),
    useSensors: vi.fn((...sensors: unknown[]) => sensors),
    closestCorners: vi.fn(),
    defaultDropAnimationSideEffects: vi.fn(() => ({ styles: {} })),
    PointerSensor: class PointerSensor {},
}));

vi.mock('@dnd-kit/sortable', () => ({
    SortableContext: (props: { children?: ReactNode }) => props.children,
    verticalListSortingStrategy: vi.fn(),
    useSortable: (options: { id: number; disabled: boolean }) => {
        sortableCalls.push(options);

        return sortableReturn;
    },
}));

vi.mock('@dnd-kit/utilities', () => ({
    CSS: { Transform: { toString: () => 'translate3d(0,0,0)' } },
}));

const taskCardSpy = vi.fn();
vi.mock('@/components/projects/task-card', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/components/projects/task-card')>();

    return {
        ...actual,
        TaskCard: (props: Record<string, unknown>) => {
            taskCardSpy(props);

            return <article data-testid="task-card">{(props.task as BoardTask).title}</article>;
        },
    };
});

// Imported after the mocks above so the module under test picks them up.
const { BoardDndContext, SortableColumnBody, SortableTaskCard } =
    await import('@/components/projects/board-dnd');

beforeEach(() => {
    dndContextProps.current = null;
    dragOverlayProps.current = null;
    droppableCalls.length = 0;
    sortableCalls.length = 0;
    sortableReturn.transform = null;
    sortableReturn.transition = undefined;
    sortableReturn.isDragging = false;
    taskCardSpy.mockClear();
});

function task(id: number, overrides: Partial<BoardTask> = {}): BoardTask {
    return {
        id,
        title: `Task ${id}`,
        priority: 'medium',
        dueDate: null,
        overdue: false,
        assignee: null,
        milestone: null,
        checklist: { done: 0, total: 0 },
        ...overrides,
    };
}

function columns(): BoardColumn[] {
    return [
        { id: 1, name: 'To Do', isDone: false, tasks: [task(1), task(2)] },
        { id: 2, name: 'Doing', isDone: false, tasks: [] },
    ];
}

/** Invokes a captured `DndContext` handler inside `act`, so the resulting state update is
 * flushed and reflected in the DOM/render output before the test asserts on it. */
function dispatch(name: 'onDragStart' | 'onDragEnd' | 'onDragCancel', event?: unknown) {
    act(() => {
        (dndContextProps.current![name] as (event?: unknown) => void)(event);
    });
}

describe('BoardDndContext', () => {
    it('mounts no DndContext, no sensors, at all when disabled (D1, read-only board)', () => {
        render(
            <BoardDndContext enabled={false} columns={columns()} busy={false} onMove={vi.fn()}>
                {(displayColumns) => <div data-testid="child">{displayColumns.length}</div>}
            </BoardDndContext>,
        );

        expect(dndContextProps.current).toBeNull();
        expect(screen.getByTestId('child')).toHaveTextContent('2');
    });

    it('renders the real columns unprojected before any drag starts', () => {
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {(displayColumns) => (
                    <div data-testid="child">{displayColumns[0]!.tasks.length}</div>
                )}
            </BoardDndContext>,
        );

        expect(dndContextProps.current).not.toBeNull();
        expect(screen.getByTestId('child')).toHaveTextContent('2');
    });

    it('configures no KeyboardSensor: exactly one sensor is registered, the pointer one', () => {
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {() => <div />}
            </BoardDndContext>,
        );

        expect(dndContextProps.current!.sensors).toHaveLength(1);
    });

    it('silences dnd-kit’s own phase announcements and keyboard instructions (EPIC-011E §9)', () => {
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {() => <div />}
            </BoardDndContext>,
        );

        const accessibility = dndContextProps.current!.accessibility as {
            announcements: {
                onDragStart: () => string;
                onDragOver: () => string;
                onDragEnd: () => string;
                onDragCancel: () => string;
            };
            screenReaderInstructions: { draggable: string };
        };

        expect(accessibility.announcements.onDragStart()).toBe('');
        expect(accessibility.announcements.onDragOver()).toBe('');
        expect(accessibility.announcements.onDragEnd()).toBe('');
        expect(accessibility.announcements.onDragCancel()).toBe('');
        expect(accessibility.screenReaderInstructions.draggable).toBe('');
    });

    it('calls onMove (requestMove) with the resolved target on a real cross-column drop, and nothing else', () => {
        const onMove = vi.fn();
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={onMove}>
                {() => <div />}
            </BoardDndContext>,
        );

        dispatch('onDragEnd', { active: { id: 1 }, over: { id: 'column-2' } });

        expect(onMove).toHaveBeenCalledTimes(1);
        expect(onMove).toHaveBeenCalledWith(1, 2, 0, 'Task 1');
    });

    it('always renders the real columns prop, never a drag-time projection (EPIC-011E Amendment 7: the projection was removed after it caused a real-browser render loop — see the file header)', () => {
        const rendered: BoardColumn[][] = [];
        const original = columns();
        render(
            <BoardDndContext enabled columns={original} busy={false} onMove={vi.fn()}>
                {(displayColumns) => {
                    rendered.push(displayColumns);

                    return <div />;
                }}
            </BoardDndContext>,
        );

        dispatch('onDragStart', { active: { id: 1 } });
        dispatch('onDragEnd', { active: { id: 1 }, over: { id: 'column-2' } });

        // Every render this component ever produced handed the child the exact same `columns`
        // reference — never a copy, never a projected substitute — including the one right after
        // a real drop (whatever the board shows next is entirely up to `onMove`/Inertia, not
        // this component).
        expect(rendered.every((cols) => cols === original)).toBe(true);
    });

    it('does not call onMove for a no-op drop (dropped back on its own placeholder)', () => {
        const onMove = vi.fn();
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={onMove}>
                {() => <div />}
            </BoardDndContext>,
        );

        dispatch('onDragEnd', { active: { id: 1 }, over: { id: 1 } });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('does not call onMove for an unresolvable target (drag ended over nothing)', () => {
        const onMove = vi.fn();
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={onMove}>
                {() => <div />}
            </BoardDndContext>,
        );

        dispatch('onDragEnd', { active: { id: 1 }, over: null });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('refuses to dispatch a move while the board is busy, even for an otherwise-valid drop', () => {
        const onMove = vi.fn();
        render(
            <BoardDndContext enabled columns={columns()} busy onMove={onMove}>
                {() => <div />}
            </BoardDndContext>,
        );

        dispatch('onDragEnd', { active: { id: 1 }, over: { id: 'column-2' } });

        expect(onMove).not.toHaveBeenCalled();
    });

    it('cancel clears the active-drag overlay state and never calls onMove', () => {
        const onMove = vi.fn();
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={onMove}>
                {() => <div />}
            </BoardDndContext>,
        );

        dispatch('onDragStart', { active: { id: 1 } });
        expect(screen.getByTestId('drag-overlay')).not.toBeEmptyDOMElement();

        dispatch('onDragCancel');

        expect(onMove).not.toHaveBeenCalled();
        expect(screen.getByTestId('drag-overlay')).toBeEmptyDOMElement();
    });

    it('renders a drag overlay preview only while a task is active, and it carries no id the real card also uses', () => {
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {() => <div />}
            </BoardDndContext>,
        );

        expect(screen.getByTestId('drag-overlay')).toBeEmptyDOMElement();

        dispatch('onDragStart', { active: { id: 1 } });

        const overlay = screen.getByTestId('drag-overlay');
        expect(overlay).toHaveTextContent('Task 1');
        expect(overlay.querySelector('[id]')).toBeNull();
        expect(overlay.querySelector('a, button')).toBeNull();
    });

    it('uses the default drop animation when the viewer has no motion preference', () => {
        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {() => <div />}
            </BoardDndContext>,
        );

        expect(dragOverlayProps.current!.dropAnimation).not.toBeNull();
    });

    it('uses no drop animation (matching WP0’s reduced-motion finding) when the viewer prefers reduced motion', () => {
        const original = window.matchMedia;
        window.matchMedia = ((query: string) =>
            ({
                matches: query.includes('reduce'),
                addEventListener: () => {},
                removeEventListener: () => {},
            }) as unknown as MediaQueryList) as typeof window.matchMedia;

        render(
            <BoardDndContext enabled columns={columns()} busy={false} onMove={vi.fn()}>
                {() => <div />}
            </BoardDndContext>,
        );

        expect(dragOverlayProps.current!.dropAnimation).toBeNull();

        window.matchMedia = original;
    });
});

describe('SortableColumnBody', () => {
    it('registers the column body as a droppable under its own id, so an empty column stays a valid target', () => {
        render(
            <SortableColumnBody columnId={2} taskIds={[]} className="test-class">
                <span>empty</span>
            </SortableColumnBody>,
        );

        expect(droppableCalls).toEqual([{ id: 'column-2' }]);
        expect(screen.getByText('empty').parentElement).toHaveClass('test-class');
    });
});

describe('SortableTaskCard', () => {
    const columnsProp: MoveTargetColumn[] = [{ id: 1, name: 'To Do', isDone: false }];

    function renderCard(overrides: { disabled?: boolean; task?: BoardTask } = {}) {
        return render(
            <SortableTaskCard
                task={overrides.task ?? task(1)}
                projectId={7}
                columnId={1}
                columnIndex={0}
                columnSize={1}
                columns={columnsProp}
                canManage
                boardBusy={false}
                disabled={overrides.disabled ?? false}
                onMove={vi.fn()}
            />,
        );
    }

    it('wires useSortable with the task id and the disabled flag', () => {
        renderCard({ disabled: true });

        expect(sortableCalls).toEqual([{ id: 1, disabled: true }]);
    });

    it('hands TaskCard a dragHandle, never a raw dnd-kit object', () => {
        renderCard();

        const props = taskCardSpy.mock.calls.at(-1)![0] as Record<string, unknown>;
        expect(props.dragHandle).toBeTruthy();
        expect(props).not.toHaveProperty('listeners');
        expect(props).not.toHaveProperty('attributes');
        expect(props).not.toHaveProperty('setActivatorNodeRef');
    });

    it('hands TaskCard the identical dragHandle element across a re-render that does not change the drag wiring (structural-memo safety)', () => {
        const { rerender } = renderCard();
        const first = taskCardSpy.mock.calls.at(-1)![0] as { dragHandle: unknown };

        rerender(
            <SortableTaskCard
                task={task(1)}
                projectId={7}
                columnId={1}
                columnIndex={0}
                // Only columnSize changes; sortableReturn's listeners/setActivatorNodeRef/disabled
                // are unchanged — dnd-kit itself keeps them stable in exactly this situation (see
                // board-dnd.tsx), so the handle SortableTaskCard builds must be too.
                columnSize={2}
                columns={columnsProp}
                canManage
                boardBusy={false}
                disabled={false}
                onMove={vi.fn()}
            />,
        );
        const second = taskCardSpy.mock.calls.at(-1)![0] as { dragHandle: unknown };

        expect(second.dragHandle).toBe(first.dragHandle);
    });

    it('rebuilds the dragHandle when disabled changes (busy-state transition)', () => {
        const { rerender } = renderCard({ disabled: false });
        const first = taskCardSpy.mock.calls.at(-1)![0] as { dragHandle: unknown };

        rerender(
            <SortableTaskCard
                task={task(1)}
                projectId={7}
                columnId={1}
                columnIndex={0}
                columnSize={1}
                columns={columnsProp}
                canManage
                boardBusy
                disabled
                onMove={vi.fn()}
            />,
        );
        const second = taskCardSpy.mock.calls.at(-1)![0] as { dragHandle: unknown };

        expect(second.dragHandle).not.toBe(first.dragHandle);
    });

    it('dims the card while dnd-kit reports it as the dragged item', () => {
        sortableReturn.isDragging = true;
        renderCard();

        expect(screen.getByTestId('task-card').parentElement).toHaveClass('opacity-40');
    });
});
