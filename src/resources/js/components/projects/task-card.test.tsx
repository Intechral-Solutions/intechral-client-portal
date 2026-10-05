import { render, screen } from '@testing-library/react';

import { TaskCard, taskCardPropsAreEqual } from '@/components/projects/task-card';
import type { BoardTask } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

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
        done: false,
        abilities: { complete: false, reopen: false },
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
        expect(taskCardPropsAreEqual(base, propsFor(cardOf(a), { taskBusy: true }))).toBe(false);
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

describe('dragHandle slot (EPIC-011E §9, WP6)', () => {
    // TaskCard never imports dnd-kit or knows it exists (EPIC-011E §9): it only ever renders
    // whatever node `dragHandle` is, so these tests stand in for `SortableTaskCard`'s real,
    // fully-wired handle with a plain marker element.
    function marker() {
        return <span data-testid="handle-marker" aria-hidden="true" tabIndex={-1} />;
    }

    it('renders nothing extra when no dragHandle is given (every pre-WP6 card, and every read-only one)', () => {
        renderTask();

        expect(screen.queryByTestId('handle-marker')).not.toBeInTheDocument();
    });

    it('renders whatever dragHandle it is handed, as a sibling of the priority badge, not a wrapper around any other content', () => {
        renderTask({ dragHandle: marker() });

        const handle = screen.getByTestId('handle-marker');
        expect(handle).toBeInTheDocument();
        expect(screen.getByText('High')).toBeInTheDocument(); // the priority badge, still rendered

        // Nothing meaningful sits inside an aria-hidden ancestor: the handle's own aria-hidden
        // span contains only itself, and is a sibling of — never a parent of — the title link,
        // the priority badge, or the Move button.
        const article = screen.getByRole('article');
        expect(
            article.querySelector('[aria-hidden="true"] a, [aria-hidden="true"] button'),
        ).toBeNull();
    });

    it('does not add the handle to the tab order: title link then Move button only, unaffected by a dragHandle being present', () => {
        renderTask({ dragHandle: marker() });

        const article = screen.getByRole('article');
        const tabbables = Array.from(
            article.querySelectorAll<HTMLElement>('a[href], button:not([tabindex="-1"])'),
        );
        expect(tabbables).toHaveLength(2);
        expect(tabbables[0]).toHaveAccessibleName('Fix login');
        expect(tabbables[1]).toHaveAccessibleName('Move "Fix login"');
    });

    it('is compared by identity in the structural memo, exactly like columns and onMove', () => {
        const a = makeTask({ id: 1 });
        const handle = marker();
        const stableHandleOnMove = () => {};
        const propsFor = (overrides: Record<string, unknown> = {}) =>
            ({
                task: a,
                projectId: 7,
                columnId: 1,
                columnIndex: 0,
                columnSize: 2,
                columns,
                canManage: true,
                boardBusy: false,
                onMove: stableHandleOnMove,
                dragHandle: handle,
                ...overrides,
            }) as React.ComponentProps<typeof TaskCard>;

        expect(taskCardPropsAreEqual(propsFor(), propsFor())).toBe(true);
        expect(taskCardPropsAreEqual(propsFor(), propsFor({ dragHandle: marker() }))).toBe(false);
        expect(taskCardPropsAreEqual(propsFor({ dragHandle: undefined }), propsFor())).toBe(false);
    });
});

describe('Complete/Reopen control (EPIC-015 WP5 S1)', () => {
    it('offers Complete on an open card the server lets the viewer complete', () => {
        const onToggleComplete = vi.fn();
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete,
        });

        const control = screen.getByRole('button', { name: 'Complete Fix login' });
        control.click();

        expect(onToggleComplete).toHaveBeenCalledExactlyOnceWith(1, false, 'Fix login');
        expect(screen.queryByRole('button', { name: 'Reopen Fix login' })).not.toBeInTheDocument();
    });

    it('offers Reopen on a done card, and says the state with a checked ring', () => {
        const onToggleComplete = vi.fn();
        renderTask({
            task: makeTask({ done: true, abilities: { complete: false, reopen: true } }),
            onToggleComplete,
        });

        const control = screen.getByRole('button', { name: 'Reopen Fix login' });
        expect(control.querySelector('[data-state="done"]')).not.toBeNull();
        control.click();

        expect(onToggleComplete).toHaveBeenCalledExactlyOnceWith(1, true, 'Fix login');
    });

    it('renders no control at all when the ability that applies is false', () => {
        // Open card: only `complete` matters; a true `reopen` must not surface a control.
        renderTask({
            task: makeTask({ abilities: { complete: false, reopen: true } }),
            onToggleComplete: vi.fn(),
        });
        expect(screen.queryByRole('button', { name: /^(Complete|Reopen) / })).toBeNull();
        expect(document.querySelector('[data-task-complete]')).toBeNull();
    });

    it('renders no control for a done card the viewer may not reopen', () => {
        renderTask({
            task: makeTask({ done: true, abilities: { complete: true, reopen: false } }),
            onToggleComplete: vi.fn(),
        });
        expect(screen.queryByRole('button', { name: /^(Complete|Reopen) / })).toBeNull();
    });

    it('renders no control without a handler, whatever the abilities say', () => {
        renderTask({ task: makeTask({ abilities: { complete: true, reopen: true } }) });
        expect(screen.queryByRole('button', { name: 'Complete Fix login' })).toBeNull();
    });

    it('is aria-disabled, focusable and inert, but not busy, while another card holds the board', () => {
        const onToggleComplete = vi.fn();
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete,
            boardBusy: true,
            taskBusy: false,
        });

        const control = screen.getByRole('button', { name: 'Complete Fix login' });
        expect(control).toHaveAttribute('aria-disabled', 'true');
        // Unavailable is not busy: this control is doing nothing, so it must not announce itself so.
        expect(control).not.toHaveAttribute('aria-busy');
        expect(control).not.toHaveClass('opacity-60');
        expect(control).not.toBeDisabled();
        control.focus();
        expect(control).toHaveFocus();

        control.click();
        expect(onToggleComplete).not.toHaveBeenCalled();
    });

    it('is busy only on the card whose own request is in flight', () => {
        const onToggleComplete = vi.fn();
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete,
            boardBusy: true,
            taskBusy: true,
        });

        const control = screen.getByRole('button', { name: 'Complete Fix login' });
        expect(control).toHaveAttribute('aria-disabled', 'true');
        expect(control).toHaveAttribute('aria-busy', 'true');
        expect(control).toHaveClass('opacity-60');

        control.click();
        expect(onToggleComplete).not.toHaveBeenCalled();
    });

    it('is a sibling of the title link and the drag handle, never inside either', () => {
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete: vi.fn(),
            dragHandle: <span data-testid="handle-marker" />,
        });

        const control = screen.getByRole('button', { name: 'Complete Fix login' });
        const link = screen.getByRole('link', { name: 'Fix login' });

        expect(link.contains(control)).toBe(false);
        expect(control.contains(link)).toBe(false);
        expect(control.closest('a')).toBeNull();
        expect(screen.getByTestId('handle-marker').contains(control)).toBe(false);
    });

    it('does not activate the title link when the ring is pressed', () => {
        const onToggleComplete = vi.fn();
        const linkClick = vi.fn();
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete,
        });
        screen.getByRole('link', { name: 'Fix login' }).addEventListener('click', linkClick);

        screen.getByRole('button', { name: 'Complete Fix login' }).click();

        expect(onToggleComplete).toHaveBeenCalledOnce();
        // The link is a sibling, so its own activation never runs; drag listeners live only on
        // the handle (board-dnd), so nothing on the card starts a drag from the ring.
        expect(linkClick).not.toHaveBeenCalled();
    });

    it('puts the control first in the card tab order, before the title link and Move', () => {
        renderTask({
            task: makeTask({ abilities: { complete: true, reopen: true } }),
            onToggleComplete: vi.fn(),
        });

        const tabbables = Array.from(
            screen
                .getByRole('article')
                .querySelectorAll<HTMLElement>('a[href], button:not([tabindex="-1"])'),
        );
        expect(
            tabbables.map((element) => element.getAttribute('aria-label') ?? element.textContent),
        ).toEqual(['Complete Fix login', 'Fix login', 'Move "Fix login"']);
    });

    it('re-renders the memoized card when done or an ability changes, and not otherwise', () => {
        const base = makeTask({ abilities: { complete: true, reopen: true } });
        const props = {
            task: base,
            projectId: 7,
            columnId: 1,
            columnIndex: 0,
            columnSize: 2,
            columns,
            canManage: true,
            boardBusy: false,
            onMove: () => {},
            onToggleComplete: () => {},
        } as React.ComponentProps<typeof TaskCard>;

        expect(
            taskCardPropsAreEqual(props, { ...props, task: JSON.parse(JSON.stringify(base)) }),
        ).toBe(true);
        expect(taskCardPropsAreEqual(props, { ...props, task: { ...base, done: true } })).toBe(
            false,
        );
        expect(
            taskCardPropsAreEqual(props, {
                ...props,
                task: { ...base, abilities: { complete: false, reopen: true } },
            }),
        ).toBe(false);
        expect(
            taskCardPropsAreEqual(props, {
                ...props,
                task: { ...base, abilities: { complete: true, reopen: false } },
            }),
        ).toBe(false);
        expect(taskCardPropsAreEqual(props, { ...props, onToggleComplete: () => {} })).toBe(false);
    });
});
