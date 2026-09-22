import {
    applyMove,
    columnDroppableId,
    findTask,
    isNoopMove,
    locateTask,
    resolveDragTarget,
} from '@/components/projects/board-moves';
import type { BoardColumn, BoardTask } from '@/types/projects';

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
        { id: 1, name: 'To Do', isDone: false, tasks: [task(1), task(2), task(3)] },
        { id: 2, name: 'Doing', isDone: false, tasks: [task(4)] },
        { id: 3, name: 'Done', isDone: true, tasks: [] },
    ];
}

/** The column with this id, or throws — every test below knows it exists. */
function columnById(result: BoardColumn[], id: number): BoardColumn {
    const column = result.find((c) => c.id === id);
    if (!column) throw new Error(`Expected a column with id ${id}`);

    return column;
}

it('does not mutate its input', () => {
    const input = columns();
    const snapshot = JSON.parse(JSON.stringify(input));

    applyMove(input, { taskId: 1, toColumnId: 2, toIndex: 0 });

    expect(input).toEqual(snapshot);
});

it('moves a task within a column downward', () => {
    const result = applyMove(columns(), { taskId: 1, toColumnId: 1, toIndex: 1 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([2, 1, 3]);
});

it('moves a task within a column upward', () => {
    const result = applyMove(columns(), { taskId: 3, toColumnId: 1, toIndex: 0 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([3, 1, 2]);
});

it('moves a task across columns, inserting at the given index', () => {
    const result = applyMove(columns(), { taskId: 2, toColumnId: 2, toIndex: 0 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([1, 3]);
    expect(columnById(result, 2).tasks.map((t) => t.id)).toEqual([2, 4]);
});

it('drops a task into an empty column', () => {
    const result = applyMove(columns(), { taskId: 4, toColumnId: 3, toIndex: 0 });

    expect(columnById(result, 2).tasks).toEqual([]);
    expect(columnById(result, 3).tasks.map((t) => t.id)).toEqual([4]);
});

it('inserts at the first position', () => {
    const result = applyMove(columns(), { taskId: 4, toColumnId: 1, toIndex: 0 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([4, 1, 2, 3]);
});

it('appends at the last position', () => {
    const result = applyMove(columns(), { taskId: 4, toColumnId: 1, toIndex: 3 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([1, 2, 3, 4]);
});

it('clamps an index past the tail to the true tail', () => {
    const result = applyMove(columns(), { taskId: 4, toColumnId: 1, toIndex: 999 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([1, 2, 3, 4]);
});

it('clamps a negative index to the front', () => {
    const result = applyMove(columns(), { taskId: 4, toColumnId: 1, toIndex: -5 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([4, 1, 2, 3]);
});

it('is a no-op for an unknown task id', () => {
    const input = columns();
    const result = applyMove(input, { taskId: 999, toColumnId: 1, toIndex: 0 });

    expect(result).toBe(input);
});

it('is a no-op for an unknown target column id', () => {
    const input = columns();
    const result = applyMove(input, { taskId: 1, toColumnId: 999, toIndex: 0 });

    expect(result).toBe(input);
});

it('leaves unaffected columns referentially unchanged', () => {
    const input = columns();
    const result = applyMove(input, { taskId: 1, toColumnId: 2, toIndex: 0 });

    expect(columnById(result, 3)).toBe(columnById(input, 3));
});

describe('locateTask', () => {
    it('finds a task and reports its column, index and column size', () => {
        expect(locateTask(columns(), 2)).toEqual({
            columnId: 1,
            columnName: 'To Do',
            columnIsDone: false,
            index: 1,
            columnSize: 3,
        });
    });

    it('returns null for an unknown task id', () => {
        expect(locateTask(columns(), 999)).toBeNull();
    });
});

describe('isNoopMove', () => {
    it('is true when the task is already at that column and index', () => {
        expect(isNoopMove(columns(), { taskId: 2, toColumnId: 1, toIndex: 1 })).toBe(true);
    });

    it('is false when the column differs', () => {
        expect(isNoopMove(columns(), { taskId: 2, toColumnId: 2, toIndex: 1 })).toBe(false);
    });

    it('is false when the index differs', () => {
        expect(isNoopMove(columns(), { taskId: 2, toColumnId: 1, toIndex: 0 })).toBe(false);
    });

    it('is true for an unknown task id (handled cleanly, not attempted)', () => {
        expect(isNoopMove(columns(), { taskId: 999, toColumnId: 1, toIndex: 0 })).toBe(true);
    });
});

describe('findTask', () => {
    it('finds the full task record wherever it sits', () => {
        expect(findTask(columns(), 4)).toEqual(task(4));
    });

    it('returns null for an unknown task id', () => {
        expect(findTask(columns(), 999)).toBeNull();
    });
});

describe('columnDroppableId', () => {
    it('never collides with a real task id, textually', () => {
        expect(columnDroppableId(1)).toBe('column-1');
        expect(columnDroppableId(1)).not.toBe(1);
    });
});

// EPIC-011E §9, WP6: what a dnd-kit hover/drop target (`over.id`) means in board terms. These
// pin the drag path's target-intent calculation without any drag library or geometry — the same
// discipline `applyMove` above is held to.
describe('resolveDragTarget', () => {
    it('resolves a same-column drop two cards forward to land after the hovered card (dragging down)', () => {
        // To Do: [1, 2, 3]. Dragging 1 over 3 uses 3's own original index (2) unmodified —
        // inserted into the already-shorter [2, 3] (1 removed), index 2 is the tail, landing
        // task 1 after both 2 and 3. This is deliberately the same arithmetic
        // `@dnd-kit/sortable`'s own `arrayMove` utility performs.
        expect(resolveDragTarget(columns(), 1, 3)).toEqual({
            taskId: 1,
            toColumnId: 1,
            toIndex: 2,
        });
    });

    it('resolves an adjacent same-column drop forward as a clean swap, not a no-op (regression: an earlier "shift-corrected" formula collapsed this to no-op)', () => {
        // Dragging 1 over its immediate neighbor 2: 2's own original index (1) is where 1 lands
        // once inserted into the already-shorter [2, 3], swapping the pair.
        const target = resolveDragTarget(columns(), 1, 2);

        expect(target).toEqual({ taskId: 1, toColumnId: 1, toIndex: 1 });
        expect(isNoopMove(columns(), target!)).toBe(false);
        expect(
            applyMove(columns(), target!)
                .find((c) => c.id === 1)!
                .tasks.map((t) => t.id),
        ).toEqual([2, 1, 3]);
    });

    it('resolves a same-column drop over an earlier card to just before it (dragging up)', () => {
        // Dragging 3 over 1: removing 3 first leaves 1's own index (0) unaffected.
        expect(resolveDragTarget(columns(), 3, 1)).toEqual({
            taskId: 3,
            toColumnId: 1,
            toIndex: 0,
        });
    });

    it('resolves a cross-column drop over a card to just before it, in the target column', () => {
        // Doing: [4]. Dragging 2 (from To Do) over 4 targets Doing, at 4's own index (0).
        expect(resolveDragTarget(columns(), 2, 4)).toEqual({
            taskId: 2,
            toColumnId: 2,
            toIndex: 0,
        });
    });

    it('resolves a drop on an empty column body to that column, at index 0', () => {
        expect(resolveDragTarget(columns(), 4, columnDroppableId(3))).toEqual({
            taskId: 4,
            toColumnId: 3,
            toIndex: 0,
        });
    });

    it('resolves a drop on a populated column body to the true tail (append)', () => {
        // Doing's own task (4) dropped on To Do's body: append after 1, 2, 3.
        expect(resolveDragTarget(columns(), 4, columnDroppableId(1))).toEqual({
            taskId: 4,
            toColumnId: 1,
            toIndex: 3,
        });
    });

    it('resolves a drop on a task’s own column body (past its own last card) to the tail of its own column', () => {
        // Task 1 dropped on To Do's own body: append after 2 and 3, excluding itself.
        expect(resolveDragTarget(columns(), 1, columnDroppableId(1))).toEqual({
            taskId: 1,
            toColumnId: 1,
            toIndex: 2,
        });
    });

    it('resolves hovering the dragged task’s own placeholder to its current, unchanged spot', () => {
        const target = resolveDragTarget(columns(), 2, 2);

        expect(target).toEqual({ taskId: 2, toColumnId: 1, toIndex: 1 });
        expect(isNoopMove(columns(), target!)).toBe(true);
    });

    it('is null for a missing target (drag ended over nothing)', () => {
        expect(resolveDragTarget(columns(), 1, null)).toBeNull();
    });

    it('is null for a column id that does not exist', () => {
        expect(resolveDragTarget(columns(), 1, columnDroppableId(999))).toBeNull();
    });

    it('is null for a task id that does not exist', () => {
        expect(resolveDragTarget(columns(), 1, 999)).toBeNull();
    });

    it('is null when the dragged task itself is not found on the board', () => {
        expect(resolveDragTarget(columns(), 999, 1)).toBeNull();
    });
});

it('applies cleanly through a same-column move that ends up in the identical order (no-op shape)', () => {
    // Moving the first task to index 0 in its own column: applyMove must still return a
    // well-formed board (removal then re-insertion at the same spot), even though requestMove
    // itself would never issue this particular move (isNoopMove would have refused it first).
    const result = applyMove(columns(), { taskId: 1, toColumnId: 1, toIndex: 0 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([1, 2, 3]);
});
