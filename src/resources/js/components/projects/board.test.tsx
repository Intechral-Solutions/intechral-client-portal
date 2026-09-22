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

/**
 * The most recent `router.reload(...)` call's options, cast to what `reconcileAfterFailure`
 * passes (EPIC-011E Amendment 6, Fix 1). A failure defers releasing the single-flight guard to
 * this call's own `onFinish`, so a test must invoke it explicitly to settle the board, the same
 * way it invokes a move's own `onFinish` above.
 */
function lastReload(): { only: string[]; onFinish?: () => void } {
    const calls = inertiaSpies.router.reload.mock.calls;
    expect(calls.length).toBeGreaterThan(0);

    return calls[calls.length - 1]![0] as { only: string[]; onFinish?: () => void };
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
    expect(lastReload().only).toEqual(['columns']);

    // Fix 1: the single-flight guard is held through the reconciliation reload, not released by
    // the PUT's own onFinish — the board is still busy and focus has not returned yet.
    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'true',
    );
    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).not.toHaveFocus();

    act(() => lastReload().onFinish?.());

    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'false',
    );
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
        expect(lastReload().only).toEqual(['columns']);
        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'true',
        );

        act(() => lastReload().onFinish?.());

        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'false',
        );
        expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();
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
    expect(lastReload().only).toEqual(['columns']);
    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'true',
    );

    act(() => lastReload().onFinish?.());

    expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'false',
    );
    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();
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

describe('reconciliation sequencing (EPIC-011E Amendment 6, Fix 1)', () => {
    it('holds the single-flight guard through a failed move’s reconciliation reload, refuses a second move until it settles, and a later move is never overwritten by the stale reload', async () => {
        const original = initialColumns();
        const { rerender } = render(
            <Board projectId={7} columns={original} abilities={{ manage: true }} />,
        );

        // Move A: "Fix login" (To Do) fails on the network.
        await openMoveMenuAndSelectFirst('Fix login');
        const moveA = optimisticSubmitted()[0]!;
        act(() => moveA.options.onNetworkError?.());
        rerender(<Board projectId={7} columns={original} abilities={{ manage: true }} />);
        act(() => moveA.options.onFinish?.());

        // A's reconciliation reload is outstanding: a second move is refused. The guard now
        // spans the whole failure-plus-reconcile cycle, not just the PUT — this is the
        // structural fix for the race (a late reload for A could otherwise land after a
        // successful B and overwrite it).
        const user = userEvent.setup();
        const writeDocsCard = screen.getByRole('article', { name: 'Write docs' });
        within(writeDocsCard).getByRole('button', { name: /Move/ }).focus();
        await user.keyboard('{Enter}');
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(optimisticSubmitted()).toHaveLength(1);
        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'true',
        );

        // Only once A's reload settles does the guard release.
        const reloadA = lastReload();
        act(() => reloadA.onFinish?.());
        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'false',
        );

        // Move B: "Write docs" (In Progress) is now allowed, and succeeds.
        await openMoveMenuAndSelectFirst('Write docs');
        expect(optimisticSubmitted()).toHaveLength(2);
        const moveB = optimisticSubmitted()[1]!;
        const bColumns = moveB.transform({ columns: original }).columns as BoardColumn[];
        rerender(<Board projectId={7} columns={bColumns} abilities={{ manage: true }} />);
        act(() => {
            moveB.options.onSuccess?.({ props: { columns: bColumns } });
            moveB.options.onFinish?.();
        });

        const toDoColumn = document.querySelector('[data-column-id="1"]') as HTMLElement;
        expect(within(toDoColumn).getByRole('link', { name: 'Write docs' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Move "Write docs"' })).toHaveFocus();

        // A defensive proof of the generation guard (EPIC-011E Amendment 6): if A's already-
        // consumed reload callback were somehow invoked again — the exact "late, stale
        // reconciliation" shape the bug described — it must not publish a side effect for a
        // move that is no longer current. It must not steal focus back to "Fix login", and B's
        // result must still stand.
        act(() => reloadA.onFinish?.());
        expect(within(toDoColumn).getByRole('link', { name: 'Write docs' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Move "Fix login"' })).not.toHaveFocus();
        expect(screen.getByRole('button', { name: 'Move "Write docs"' })).toHaveFocus();
    });

    it('still performs the reconciliation reload on failure when no later move is attempted, and the guard clears cleanly', async () => {
        const original = initialColumns();
        const { rerender } = render(
            <Board projectId={7} columns={original} abilities={{ manage: true }} />,
        );

        await openMoveMenuAndSelectFirst('Fix login');
        const move = lastMove();

        act(() => move.options.onError?.({ position: 'The selected position is invalid.' }));
        rerender(<Board projectId={7} columns={original} abilities={{ manage: true }} />);
        act(() => move.options.onFinish?.());

        expect(lastReload().only).toEqual(['columns']);
        act(() => lastReload().onFinish?.());

        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'false',
        );
        expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();

        // The guard is fully released: a further move is accepted normally.
        await openMoveMenuAndSelectFirst('Fix login');
        expect(optimisticSubmitted()).toHaveLength(2);
    });
});

describe('defensive hardening (EPIC-011E Amendment 6, Fix 3)', () => {
    it('recovers from a synchronous throw when starting a move: the guard clears, the failure is reported normally, and another move can follow', async () => {
        render(<Board projectId={7} columns={initialColumns()} abilities={{ manage: true }} />);

        inertiaSpies.router.optimistic.mockImplementationOnce(() => {
            throw new Error('boom: synchronous failure before any visit started');
        });

        await openMoveMenuAndSelectFirst('Fix login');

        // No visit was ever actually issued for the failed attempt, and no reconciliation reload
        // was scheduled — there is nothing for a later visit to reconcile.
        expect(optimisticSubmitted()).toHaveLength(0);
        expect(inertiaSpies.router.reload).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent("Couldn't save the move. Try again.");
        expect(screen.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
            'aria-busy',
            'false',
        );
        expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toHaveFocus();

        // The guard was released rather than stranded: a subsequent move is attempted normally.
        await openMoveMenuAndSelectFirst('Fix login');
        expect(optimisticSubmitted()).toHaveLength(1);
    });
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
