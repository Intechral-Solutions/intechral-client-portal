import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { TaskTable } from '@/components/tasks/task-table';
import { chooseRadio, openMenu } from '@/test/menu';
import { resetInertiaMock } from '@/test/inertia';
import type { TaskAssigneeOptions, TaskRow } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const priorityLabels = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };

const board: TaskRow = {
    id: 1,
    title: 'Ship it',
    kind: 'board',
    projectId: 1,
    priority: 'high',
    status: { label: 'In Progress', done: false, source: 'column' },
    dueDate: null,
    overdue: false,
    assignee: null,
    context: { kind: 'project', label: 'Alpha', url: '/projects/1/board' },
    url: '/projects/1/tasks/1',
    abilities: { complete: true, reopen: true, assign: false },
};
const standalone: TaskRow = {
    id: 2,
    title: 'Loose end',
    kind: 'standalone',
    projectId: null,
    priority: 'low',
    status: { label: 'To Do', done: false, source: 'status' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'standalone', label: 'Standalone', url: null },
    url: '/tasks/2',
    abilities: { complete: true, reopen: true, assign: true },
};

const assigneeOptions: TaskAssigneeOptions = {
    self: { id: 5, name: 'Mia Member' },
    projects: [
        {
            projectId: 1,
            members: [
                { id: 5, name: 'Mia Member' },
                { id: 6, name: 'Noor Newhire' },
            ],
        },
    ],
};

function Harness({
    tasks = [board, standalone],
    running = new Set<number>(),
    pending = new Set<number>(),
    onToggle = vi.fn(),
    onOpen = vi.fn(),
    withSelection = true,
    assigning = new Set<number>(),
    onAssign = vi.fn(),
}: {
    tasks?: TaskRow[];
    running?: Set<number>;
    pending?: Set<number>;
    onToggle?: (task: TaskRow) => void;
    onOpen?: (task: TaskRow) => void;
    withSelection?: boolean;
    assigning?: Set<number>;
    onAssign?: (task: TaskRow, userId: number | null) => void;
}) {
    const [selected, setSelected] = useState<Set<number>>(new Set());

    return (
        <TaskTable
            tasks={tasks}
            priorityLabels={priorityLabels}
            runningTaskIds={running}
            pendingIds={pending}
            onToggle={onToggle}
            onOpen={onOpen}
            assigneeOptions={assigneeOptions}
            assigningIds={assigning}
            onAssign={onAssign}
            selection={withSelection ? { selected, onChange: setSelected } : undefined}
        />
    );
}

const row = (title: string) => screen.getByText(title).closest('tr') as HTMLElement;

describe('TaskTable content', () => {
    it('is a named table with the §14.1 columns', () => {
        render(<Harness />);

        const table = screen.getByRole('table', { name: 'Tasks' });
        expect(
            within(table)
                .getAllByRole('columnheader')
                .map((c) => c.textContent),
        ).toEqual(['', 'Complete', 'Task', 'Status', 'Priority', 'Context', 'Assignee', 'Due']);
    });

    it('links a board task to its page and its project, and shows its source tag', () => {
        render(<Harness />);

        const boardRow = within(row('Ship it'));
        expect(boardRow.getByRole('link', { name: 'Ship it' })).toHaveAttribute(
            'href',
            '/projects/1/tasks/1',
        );
        expect(boardRow.getByRole('link', { name: 'Alpha' })).toHaveAttribute(
            'href',
            '/projects/1/board',
        );
        expect(boardRow.getByText('Board')).toHaveClass('font-mono');
    });

    it('links a standalone title to its own page (WP5), though its context stays plain text', () => {
        render(<Harness />);

        const standaloneRow = within(row('Loose end'));
        expect(standaloneRow.getByRole('link', { name: 'Loose end' })).toHaveAttribute(
            'href',
            '/tasks/2',
        );
        expect(standaloneRow.getAllByText('Standalone').length).toBeGreaterThan(0);
        expect(standaloneRow.getByText('Mia Member')).toBeVisible();
    });

    it('renders a title as plain text, never a dead link, when a row has no page to open', () => {
        render(<Harness tasks={[{ ...standalone, url: null }]} />);

        expect(within(row('Loose end')).queryByRole('link')).not.toBeInTheDocument();
    });

    it('shows an unassigned task as an em dash and its priority with the server label', () => {
        render(<Harness />);

        expect(
            within(row('Ship it')).getByText('—', { selector: '[data-cell="assignee"]' }),
        ).toBeVisible();
        expect(within(row('Ship it')).getByText('High')).toBeVisible();
    });

    it('marks a critical priority in danger', () => {
        render(<Harness tasks={[{ ...board, priority: 'critical' }]} />);

        expect(screen.getByText('Critical').parentElement).toHaveClass('text-danger');
    });

    it('mutes a done row and keeps its status text', () => {
        render(
            <Harness
                tasks={[{ ...board, status: { label: 'Done', done: true, source: 'column' } }]}
            />,
        );

        expect(row('Ship it')).toHaveClass('text-text-muted');
        expect(screen.getByText('Done')).toBeVisible();
    });

    it('shows an overdue due date with a glyph and a text cue, in danger weight 500', () => {
        render(<Harness tasks={[{ ...board, dueDate: '2026-01-15', overdue: true }]} />);

        const due = within(row('Ship it'))
            .getByText('Jan 15, 2026')
            .closest('[data-cell="due"]') as HTMLElement;
        expect(due).toHaveClass('text-danger', 'font-medium');
        expect(due.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
        expect(due).toHaveTextContent('Overdue');
    });

    it('always shows the canonical formatted date, never a browser-derived "Today"', () => {
        const now = new Date();
        const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
        render(
            <Harness
                tasks={[
                    { ...board, dueDate: today },
                    { ...standalone, dueDate: '2099-03-04' },
                ]}
            />,
        );

        expect(screen.queryByText('Today')).not.toBeInTheDocument();
        const due = within(row('Loose end')).getByText('Mar 4, 2099');
        expect(due.tagName).toBe('TIME');
        expect(due).toHaveAttribute('datetime', '2099-03-04');
        // The "Due" word is kept for assistive technology.
        expect(within(row('Loose end')).getByText('Due', { exact: false })).toHaveClass('sr-only');
        expect(row('Ship it').querySelector('time')).toHaveAttribute('datetime', today);
    });

    it('keeps a long title whole in the DOM and truncates it only visually at S', () => {
        const long = 'A very long title '.repeat(12).trim();
        render(
            <Harness
                tasks={[
                    { ...board, title: long },
                    { ...standalone, title: long + ' 2' },
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: long });
        expect(link).toHaveTextContent(long);
        expect(link).toHaveClass('max-md:truncate', 'max-md:min-w-0');
        const plain = screen.getByText(long + ' 2');
        expect(plain).toHaveClass('max-md:truncate');
        expect(plain).toHaveTextContent(long + ' 2');
    });

    it("shows the viewer's running timer as a state on the row, with no control and no clock", () => {
        render(<Harness running={new Set([1])} />);

        expect(row('Ship it')).toHaveClass('bg-live-soft');
        expect(within(row('Ship it')).getByText('Timer running')).toBeVisible();
        expect(within(row('Loose end')).queryByText('Timer running')).not.toBeInTheDocument();
        expect(
            within(row('Ship it')).queryByRole('button', { name: /timer/i }),
        ).not.toBeInTheDocument();
    });

    it('does not set the task up as a card', () => {
        const { container } = render(<Harness />);

        expect(container.innerHTML).not.toMatch(/shadow-|rounded-(md|lg|overlay)/);
    });
});

describe('TaskTable controls', () => {
    it('gives each row a Complete or Reopen ring from its own abilities', () => {
        render(
            <Harness
                tasks={[
                    board,
                    { ...standalone, abilities: { complete: false, reopen: false, assign: false } },
                    {
                        ...board,
                        id: 3,
                        title: 'Finished',
                        status: { label: 'Done', done: true, source: 'column' },
                    },
                ]}
            />,
        );

        expect(
            within(row('Ship it')).getByRole('button', { name: 'Complete Ship it' }),
        ).toBeVisible();
        expect(within(row('Loose end')).queryByRole('button')).not.toBeInTheDocument();
        expect(
            within(row('Finished')).getByRole('button', { name: 'Reopen Finished' }),
        ).toBeVisible();
    });

    it('reports a ring click and marks a row pending', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        const { rerender } = render(<Harness onToggle={onToggle} />);

        await user.click(screen.getByRole('button', { name: 'Complete Ship it' }));
        expect(onToggle).toHaveBeenCalledWith(board);

        rerender(<Harness onToggle={onToggle} pending={new Set([1])} />);
        expect(screen.getByRole('button', { name: 'Complete Ship it' })).toHaveAttribute(
            'aria-busy',
            'true',
        );
    });

    it('offers a named selection checkbox per task and for the page', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));
        expect(screen.getByRole('checkbox', { name: 'Select Ship it' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Select all tasks' })).toBeVisible();
    });

    it('can render without a selection column', () => {
        render(<Harness withSelection={false} />);

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    });
});

describe('TaskTable assignment (EPIC-014 R6)', () => {
    it('offers an assign control only on rows whose server ability allows it', () => {
        render(<Harness tasks={[board, standalone]} />);

        // `board.abilities.assign` is false: read-only text, no control.
        expect(
            within(row('Ship it')).queryByRole('button', { name: /Change assignee/ }),
        ).not.toBeInTheDocument();
        expect(
            within(row('Loose end')).getByRole('button', {
                name: 'Assignee: Mia Member. Change assignee of “Loose end”',
            }),
        ).toBeVisible();
    });

    it('keeps the assignee readable, and in the table, on a row the viewer may not assign', () => {
        render(<Harness tasks={[{ ...board, assignee: { id: 6, name: 'Noor Newhire' } }]} />);

        expect(within(row('Ship it')).getByText('Noor Newhire')).toBeInTheDocument();
    });

    it('draws the control compactly at S: the name stays for assistive technology only', () => {
        render(<Harness tasks={[standalone]} />);

        expect(within(row('Loose end')).getByText('Mia Member')).toHaveClass('max-md:sr-only');
    });

    it('offers a standalone row Me and Unassigned, and nobody else', async () => {
        const user = userEvent.setup();
        render(<Harness tasks={[{ ...standalone, assignee: null }]} />);

        await openMenu(
            user,
            screen.getByRole('button', { name: /Change assignee of “Loose end”/ }),
        );

        expect(
            (await screen.findAllByRole('menuitemradio')).map((item) => item.textContent),
        ).toEqual(['Unassigned', 'Me']);
    });

    it('offers a managed board row its project members from the server list', async () => {
        const user = userEvent.setup();
        render(
            <Harness
                tasks={[{ ...board, abilities: { complete: true, reopen: true, assign: true } }]}
            />,
        );

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));

        expect(
            (await screen.findAllByRole('menuitemradio')).map((item) => item.textContent),
        ).toEqual(['Unassigned', 'Mia Member', 'Noor Newhire']);
    });

    it('reports the chosen person and the row', async () => {
        const user = userEvent.setup();
        const onAssign = vi.fn();
        render(
            <Harness
                onAssign={onAssign}
                tasks={[{ ...board, abilities: { complete: true, reopen: true, assign: true } }]}
            />,
        );

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');

        expect(onAssign).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ id: 1 }), 6);
    });

    it("marks the control busy while that row's assignment is in flight", () => {
        render(<Harness assigning={new Set([2])} />);

        expect(
            screen.getByRole('button', { name: /Change assignee of “Loose end”/ }),
        ).toHaveAttribute('aria-busy', 'true');
    });

    it('does not let row shortcuts fire from inside an open assign menu', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        render(<Harness onToggle={onToggle} />);

        await openMenu(
            user,
            screen.getByRole('button', { name: /Change assignee of “Loose end”/ }),
        );
        await screen.findByRole('menu');
        await user.keyboard('e');

        expect(onToggle).not.toHaveBeenCalled();
    });
});

describe('TaskTable row keys (EPIC-014 §15.1)', () => {
    it('E completes the focused row when its ability allows, and does nothing when it does not', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        const denied = {
            ...standalone,
            abilities: { complete: false, reopen: false, assign: false },
        };
        render(<Harness tasks={[board, denied]} onToggle={onToggle} />);

        row('Ship it').focus();
        await user.keyboard('e');
        expect(onToggle).toHaveBeenCalledWith(board);

        onToggle.mockClear();
        row('Loose end').focus();
        await user.keyboard('e');
        expect(onToggle).not.toHaveBeenCalled();
    });

    it('E reopens a done row only with the reopen ability', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        const done = { ...board, status: { label: 'Done', done: true, source: 'column' as const } };
        const { rerender } = render(<Harness tasks={[done]} onToggle={onToggle} />);

        row('Ship it').focus();
        await user.keyboard('e');
        expect(onToggle).toHaveBeenCalledWith(done);

        onToggle.mockClear();
        rerender(
            <Harness
                tasks={[{ ...done, abilities: { complete: true, reopen: false, assign: false } }]}
                onToggle={onToggle}
            />,
        );
        row('Ship it').focus();
        await user.keyboard('e');
        expect(onToggle).not.toHaveBeenCalled();
    });

    it('does not complete a row that is already pending', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        render(<Harness onToggle={onToggle} pending={new Set([1])} />);

        row('Ship it').focus();
        await user.keyboard('e');

        expect(onToggle).not.toHaveBeenCalled();
    });

    it('Enter on the row opens a task that has a page and ignores one that has none', async () => {
        const user = userEvent.setup();
        const onOpen = vi.fn();
        render(<Harness onOpen={onOpen} tasks={[board, { ...standalone, url: null }]} />);

        row('Ship it').focus();
        await user.keyboard('{Enter}');
        expect(onOpen).toHaveBeenCalledWith(board);

        onOpen.mockClear();
        row('Loose end').focus();
        await user.keyboard('{Enter}');
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('J, K and X work across the rows', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        row('Ship it').focus();
        await user.keyboard('j');
        expect(row('Loose end')).toHaveFocus();
        await user.keyboard('x');
        expect(screen.getByRole('checkbox', { name: 'Select Loose end' })).toBeChecked();
        await user.keyboard('k');
        expect(row('Ship it')).toHaveFocus();
    });

    it('Escape clears the selection from inside the table (Direction D §14.3)', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        row('Ship it').focus();
        await user.keyboard('x');
        expect(screen.getByRole('checkbox', { name: 'Select Ship it' })).toBeChecked();

        await user.keyboard('{Escape}');
        expect(screen.getByRole('checkbox', { name: 'Select Ship it' })).not.toBeChecked();
    });
});
