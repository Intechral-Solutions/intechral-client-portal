import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Board } from '@/components/projects/board';
import {
    inertiaSpies,
    optimisticSubmitted,
    resetInertiaMock,
    submitted,
    type OptimisticSubmission,
} from '@/test/inertia';
import type { BoardColumn, BoardTask } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function task(overrides: Partial<BoardTask> = {}): BoardTask {
    return {
        id: 1,
        title: 'Fix login',
        priority: 'medium',
        dueDate: null,
        overdue: false,
        assignee: null,
        milestone: null,
        checklist: { done: 0, total: 0 },
        ...overrides,
    };
}

function initialColumns(): BoardColumn[] {
    return [
        { id: 1, name: 'To Do', isDone: false, tasks: [task({ id: 1, title: 'Fix login' })] },
        {
            id: 2,
            name: 'In Progress',
            isDone: false,
            tasks: [task({ id: 2, title: 'Write docs' })],
        },
        { id: 3, name: 'Done', isDone: true, tasks: [] },
    ];
}

/** The one submission a test triggered, with its options cast to what board.tsx passes. */
function lastMove(): OptimisticSubmission {
    const submissions = optimisticSubmitted();
    expect(submissions).toHaveLength(1);

    return submissions[0]!;
}

async function openMoveMenuAndSelectFirst(cardTitle: string) {
    const user = userEvent.setup();
    const article = screen.getByRole('article', { name: cardTitle });
    within(article).getByRole('link').focus();
    await user.tab(); // title link -> Move button
    await user.keyboard('{Enter}'); // opens, focuses first item
    await user.keyboard('{Enter}'); // selects it

    return user;
}

it('renders columns, their tasks, and an empty column', () => {
    render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

    expect(screen.getByRole('region', { name: 'Kanban board' })).toBeInTheDocument();
    expect(screen.getByRole('article', { name: 'Fix login' })).toBeInTheDocument();
    expect(screen.getByRole('article', { name: 'Write docs' })).toBeInTheDocument();
    expect(screen.getByText('No tasks.')).toBeInTheDocument();
});

it('hides Add task and Move controls from a non-manager, but keeps the board and title links', () => {
    render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: false }} />);

    expect(screen.queryByRole('button', { name: /Add task/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Move "/ })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Fix login' })).toBeInTheDocument();
});

it('sends the exact move payload for a cross-column selection and marks the board busy', async () => {
    render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

    await openMoveMenuAndSelectFirst('Fix login');

    const move = lastMove();
    expect(move.method).toBe('put');
    expect(move.url).toBe('/projects/7/tasks/1/move');
    expect(move.data.column_id).toBe(2); // "Move to In Progress" is the first other column
    expect(move.options).toMatchObject({ only: ['columns', 'flash'], preserveScroll: true });
    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'true',
    );
});

it('refuses a second move while one is pending (single flight)', async () => {
    render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

    await openMoveMenuAndSelectFirst('Fix login');
    expect(optimisticSubmitted()).toHaveLength(1);

    // The board is busy: every Move trigger (including the one just used) is aria-disabled and
    // Radix's own guard refuses to open it (pinned at the unit level in move-task-menu.test.tsx).
    const user = userEvent.setup();
    const otherCard = screen.getByRole('article', { name: 'Write docs' });
    within(otherCard).getByRole('button', { name: /Move/ }).focus();
    await user.keyboard('{Enter}');

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(optimisticSubmitted()).toHaveLength(1);
});

it('announces success, clears busy state, and restores focus to the moved card after settling', async () => {
    const { rerender } = render(
        <Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />,
    );

    await openMoveMenuAndSelectFirst('Fix login');
    const move = lastMove();

    // The optimistic phase: apply the recorded transform and re-render, as Inertia's quiet
    // props swap would.
    const optimisticColumns = move.transform({ columns: initialColumns() })
        .columns as BoardColumn[];
    rerender(<Board projectId={7} columns={optimisticColumns} abilities={{ manage: true }} />);
    // The optimistic swap already moved the card in the DOM, into column 2 ("In Progress").
    const inProgressColumn = document.querySelector('[data-column-id="2"]') as HTMLElement;
    expect(within(inProgressColumn).getByRole('link', { name: 'Fix login' })).toBeInTheDocument();

    // The server's canonical response, indistinguishable here from the optimistic guess. Real
    // Inertia invokes these lifecycle callbacks outside any React event handler; act() is what
    // React's synthetic-event batching would otherwise provide.
    act(() => {
        move.options.onSuccess?.({ props: { columns: optimisticColumns } });
        move.options.onFinish?.();
    });

    expect(
        screen.getByText('Moved "Fix login" to In Progress, position 2 of 2.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'false',
    );
    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();
});

it('reverts, reloads, and shows an assertive alert on a validation failure (422)', async () => {
    const original = initialColumns();
    const { rerender } = render(
        <Board projectId={7} columns={original} abilities={{ manage: true }} />,
    );

    await openMoveMenuAndSelectFirst('Fix login');
    const move = lastMove();

    const optimisticColumns = move.transform({ columns: original }).columns as BoardColumn[];
    rerender(<Board projectId={7} columns={optimisticColumns} abilities={{ manage: true }} />);

    act(() => move.options.onError?.({ column_id: 'The selected column is invalid.' }));
    // The library would already have replayed the pre-move baseline by the time onFinish runs.
    rerender(<Board projectId={7} columns={original} abilities={{ manage: true }} />);
    act(() => move.options.onFinish?.());

    expect(screen.getByRole('alert')).toHaveTextContent('The selected column is invalid.');
    expect(inertiaSpies.router.reload).toHaveBeenCalledWith({ only: ['columns'] });
    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();
});

it.each([403, 404, 500])(
    'reverts, reloads, and shows a generic alert on a %i http exception',
    async (status) => {
        const original = initialColumns();
        const { rerender } = render(
            <Board projectId={7} columns={original} abilities={{ manage: true }} />,
        );

        await openMoveMenuAndSelectFirst('Fix login');
        const move = lastMove();

        let result: boolean | void = undefined;
        act(() => {
            result = move.options.onHttpException?.({ status });
        });
        rerender(<Board projectId={7} columns={original} abilities={{ manage: true }} />);
        act(() => move.options.onFinish?.());

        // Suppresses Inertia's default error modal (EPIC-011E §8).
        expect(result).toBe(false);
        expect(screen.getByRole('alert')).toBeInTheDocument();
        expect(inertiaSpies.router.reload).toHaveBeenCalledWith({ only: ['columns'] });
    },
);

it('reverts, reloads, and shows an alert on a network error', async () => {
    const original = initialColumns();
    const { rerender } = render(
        <Board projectId={7} columns={original} abilities={{ manage: true }} />,
    );

    await openMoveMenuAndSelectFirst('Fix login');
    const move = lastMove();

    let result: boolean | void = undefined;
    act(() => {
        result = move.options.onNetworkError?.();
    });
    rerender(<Board projectId={7} columns={original} abilities={{ manage: true }} />);
    act(() => move.options.onFinish?.());

    expect(result).toBe(false);
    expect(screen.getByRole('alert')).toHaveTextContent("Couldn't save the move. Try again.");
    expect(inertiaSpies.router.reload).toHaveBeenCalledWith({ only: ['columns'] });
});

it('does a full reload instead of the usual reconciliation on a 401/419 session expiry', async () => {
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, reload });

    render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);
    await openMoveMenuAndSelectFirst('Fix login');
    const move = lastMove();

    let result: boolean | void = undefined;
    act(() => {
        result = move.options.onHttpException?.({ status: 419 });
    });

    expect(result).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(inertiaSpies.router.reload).not.toHaveBeenCalled();

    vi.unstubAllGlobals();
});

describe('quick-add', () => {
    it('opens the correct column’s form and keeps only one open at a time', async () => {
        const user = userEvent.setup();
        render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

        await user.click(screen.getByRole('button', { name: 'Add task to To Do' }));
        expect(screen.getByLabelText('New task title')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Add task to Done' }));
        // Still exactly one input: opening a second column's form closed the first.
        expect(screen.getAllByLabelText('New task title')).toHaveLength(1);
    });

    it('returns focus to the column’s Add task toggle after a successful create', async () => {
        const user = userEvent.setup();
        render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

        const toggle = screen.getByRole('button', { name: 'Add task to To Do' });
        await user.click(toggle);
        await user.type(screen.getByLabelText('New task title'), 'New task');

        const submission = submitted();
        await user.click(screen.getByRole('button', { name: 'Add' }));
        const { onSuccess } = submission[submission.length - 1]!.options as {
            onSuccess: () => void;
        };
        act(() => onSuccess());

        expect(screen.queryByLabelText('New task title')).not.toBeInTheDocument();
        expect(toggle).toHaveFocus();
    });
});
