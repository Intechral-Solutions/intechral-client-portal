import { render, screen } from '@testing-library/react';

import { TaskCard, taskCardPropsAreEqual } from '@/components/projects/task-card';
import type { BoardTask } from '@/types/projects';

const columns = [
    { id: 1, name: 'To Do', isDone: false },
    { id: 2, name: 'Done', isDone: true },
];

function makeTask(overrides: Partial<BoardTask> = {}): BoardTask {
    return {
        id: 1,
        title: 'Fix login',
        priority: 'high',
        dueDate: '2026-06-30',
        overdue: false,
        assignee: { id: 9, name: 'Ada Manager' },
        milestone: { id: 3, name: 'Launch' },
        checklist: { done: 1, total: 4 },
        ...overrides,
    };
}

function renderTask(overrides: Partial<React.ComponentProps<typeof TaskCard>> = {}) {
    const onMove = vi.fn();
    const utils = render(
        <TaskCard
            task={makeTask()}
            projectId={7}
            columnId={1}
            columnIndex={0}
            columnSize={2}
            columns={columns}
            canManage
            boardBusy={false}
            onMove={onMove}
            {...overrides}
        />,
    );

    return { ...utils, onMove };
}

it('renders priority, milestone, title link, checklist, due date and assignee', () => {
    renderTask();

    expect(screen.getByText('High')).toBeInTheDocument();
    expect(screen.getByText('Launch')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Fix login' })).toHaveAttribute(
        'href',
        '/projects/7/tasks/1',
    );
    expect(screen.getByText('1/4')).toBeInTheDocument();
    expect(screen.getByText('Jun 30, 2026')).toBeInTheDocument();
    expect(screen.getByLabelText('Ada Manager')).toHaveTextContent('A');
});

it('marks an overdue due date distinctly', () => {
    const { unmount } = renderTask({ task: makeTask({ overdue: true }) });
    expect(screen.getByText('Jun 30, 2026')).toHaveClass('text-[var(--text-danger)]');
    unmount();

    renderTask({ task: makeTask({ overdue: false }) });
    expect(screen.getByText('Jun 30, 2026')).not.toHaveClass('text-[var(--text-danger)]');
});

it('omits the checklist count when there are no items', () => {
    renderTask({ task: makeTask({ checklist: { done: 0, total: 0 } }) });

    expect(screen.queryByText('0/0')).not.toBeInTheDocument();
});

it('shows the Move control only when the actor can manage the board', () => {
    const { unmount } = renderTask({ canManage: true });
    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toBeInTheDocument();
    unmount();

    renderTask({ canManage: false });
    expect(screen.queryByRole('button', { name: 'Move "Fix login"' })).not.toBeInTheDocument();
});

describe('structural memo', () => {
    // Inertia hands the optimistic callback a deep clone of every prop, so every task object is
    // a new reference during the optimistic phase (EPIC-011E §8, WP0 gate item 8; §25). A
    // reference-equality `memo` would re-render every card on every move; `taskCardPropsAreEqual`
    // is the comparator that instead lets only the card whose rendered fields actually changed
    // through. React's own `memo` mechanics (given a comparator returning true/false, it does or
    // does not re-render) are trusted; these tests pin the comparator's true/false decisions,
    // which is the part WP5 actually wrote and could get wrong. A DOM-level check further below
    // confirms updates that must propagate actually do.
    function cardOf(task: BoardTask): BoardTask {
        return JSON.parse(JSON.stringify(task)) as BoardTask;
    }

    function propsFor(task: BoardTask, overrides: Record<string, unknown> = {}) {
        return {
            task,
            projectId: 7,
            columnId: 1,
            columnIndex: 0,
            columnSize: 2,
            columns,
            canManage: true,
            boardBusy: false,
            onMove: stableOnMove,
            ...overrides,
        } as React.ComponentProps<typeof TaskCard>;
    }

    function stableOnMove() {}

    it('is equal for a deep clone that changes no rendered field (identity-only change)', () => {
        const a = makeTask({ id: 1, title: 'A' });

        expect(taskCardPropsAreEqual(propsFor(a), propsFor(cardOf(a)))).toBe(true);
    });

    it('is unequal when a rendered task field changes: title, priority, dueDate, overdue', () => {
        const a = makeTask({ id: 1 });

        expect(taskCardPropsAreEqual(propsFor(a), propsFor({ ...cardOf(a), title: 'New' }))).toBe(
            false,
        );
        expect(
            taskCardPropsAreEqual(propsFor(a), propsFor({ ...cardOf(a), priority: 'low' })),
        ).toBe(false);
        expect(
            taskCardPropsAreEqual(propsFor(a), propsFor({ ...cardOf(a), dueDate: '2030-01-01' })),
        ).toBe(false);
        expect(taskCardPropsAreEqual(propsFor(a), propsFor({ ...cardOf(a), overdue: true }))).toBe(
            false,
        );
    });

    it('is unequal when assignee or milestone changes, equal when they clone identically (including null)', () => {
        const a = makeTask({ id: 1, assignee: { id: 9, name: 'Ada' }, milestone: null });

        expect(taskCardPropsAreEqual(propsFor(a), propsFor(cardOf(a)))).toBe(true);
        expect(
            taskCardPropsAreEqual(
                propsFor(a),
                propsFor({ ...cardOf(a), assignee: { id: 10, name: 'Bea' } }),
            ),
        ).toBe(false);
        expect(taskCardPropsAreEqual(propsFor(a), propsFor({ ...cardOf(a), assignee: null }))).toBe(
            false,
        );
    });

    it('is unequal when the checklist counts change', () => {
        const a = makeTask({ id: 1, checklist: { done: 1, total: 4 } });

        expect(
            taskCardPropsAreEqual(
                propsFor(a),
                propsFor({ ...cardOf(a), checklist: { done: 2, total: 4 } }),
            ),
        ).toBe(false);
    });

    it('is unequal when the card changes column, position, column size, ability, or busy state', () => {
        const a = makeTask({ id: 1 });
        const base = propsFor(a);

        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { columnId: 2 }))).toBe(false);
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { columnIndex: 1 }))).toBe(false);
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { columnSize: 3 }))).toBe(false);
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { canManage: false }))).toBe(false);
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { boardBusy: true }))).toBe(false);
    });

    it('compares columns and onMove by identity, not by content', () => {
        const a = makeTask({ id: 1 });
        const base = propsFor(a);

        // A structurally-identical but differently-referenced columns array or callback is
        // treated as changed: board.tsx guarantees identity stability for both, so this is a
        // simple, cheap check that catches an accidental fresh reference upstream.
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { columns: [...columns] }))).toBe(
            false,
        );
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { onMove: () => {} }))).toBe(false);
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a)))).toBe(true);
    });

    it('propagates a genuine field change to the DOM when the card is actually re-rendered', () => {
        const a = makeTask({ id: 1, title: 'Original title' });
        const { rerender } = render(<TaskCard {...propsFor(a)} />);
        expect(screen.getByRole('link', { name: 'Original title' })).toBeInTheDocument();

        rerender(<TaskCard {...propsFor({ ...cardOf(a), title: 'Updated title' })} />);

        expect(screen.getByRole('link', { name: 'Updated title' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Original title' })).not.toBeInTheDocument();
    });
});

it('tabs from the title link straight to the Move button, and nothing else', () => {
    renderTask();

    const article = screen.getByRole('article');
    const tabbables = Array.from(
        article.querySelectorAll<HTMLElement>('a[href], button:not([tabindex="-1"])'),
    );
    expect(tabbables).toHaveLength(2);
    expect(tabbables[0]).toHaveAccessibleName('Fix login');
    expect(tabbables[1]).toHaveAccessibleName('Move "Fix login"');
});
