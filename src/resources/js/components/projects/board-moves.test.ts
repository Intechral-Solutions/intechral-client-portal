import { applyMove, isNoopMove, locateTask } from '@/components/projects/board-moves';
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

it('applies cleanly through a same-column move that ends up in the identical order (no-op shape)', () => {
    // Moving the first task to index 0 in its own column: applyMove must still return a
    // well-formed board (removal then re-insertion at the same spot), even though requestMove
    // itself would never issue this particular move (isNoopMove would have refused it first).
    const result = applyMove(columns(), { taskId: 1, toColumnId: 1, toIndex: 0 });

    expect(columnById(result, 1).tasks.map((t) => t.id)).toEqual([1, 2, 3]);
});
