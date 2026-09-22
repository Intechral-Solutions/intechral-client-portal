import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { APPEND_TO_END } from '@/components/projects/board-moves';
import { MoveTaskMenu, type MoveTargetColumn } from '@/components/projects/move-task-menu';

const columns: MoveTargetColumn[] = [
    { id: 1, name: 'To Do', isDone: false },
    { id: 2, name: 'In Progress', isDone: false },
    { id: 3, name: 'Done', isDone: true },
];

function renderMenu(overrides: Partial<React.ComponentProps<typeof MoveTaskMenu>> = {}) {
    const onMove = vi.fn();
    render(
        <MoveTaskMenu
            taskId={5}
            taskTitle="Fix login"
            columns={columns}
            currentColumnId={1}
            currentIndex={1}
            columnSize={3}
            disabled={false}
            onMove={onMove}
            {...overrides}
        />,
    );

    return { onMove };
}

it('has an accessible name naming the task', () => {
    renderMenu();

    expect(screen.getByRole('button', { name: 'Move "Fix login"' })).toBeInTheDocument();
});

it('opens by keyboard and lists every other column (with a done suffix) plus move up/down', async () => {
    // jsdom Radix finding (EPIC-011E WP2): DropdownMenu is reliable opened by keyboard, not
    // pointer, in this environment; keyboard is also the canonical path (§10).
    const user = userEvent.setup();
    renderMenu();

    await user.tab();
    await user.keyboard('{Enter}');

    expect(screen.getByRole('menuitem', { name: 'Move to In Progress' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Move to Done (done)' })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: 'Move to To Do' })).not.toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Move up' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Move down' })).toBeInTheDocument();
});

it('calls onMove with the append sentinel for a cross-column destination', async () => {
    const user = userEvent.setup();
    const { onMove } = renderMenu();

    await user.tab();
    await user.keyboard('{Enter}'); // opens, focuses the first item: "Move to In Progress"
    await user.keyboard('{Enter}'); // selects it

    expect(onMove).toHaveBeenCalledWith(2, APPEND_TO_END);
});

it('calls onMove with index - 1 for Move up', async () => {
    const user = userEvent.setup();
    const { onMove } = renderMenu({ currentIndex: 1 });

    await user.tab();
    await user.keyboard('{Enter}');
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}'); // In Progress, Done, Move up

    expect(onMove).toHaveBeenCalledWith(1, 0);
});

it('calls onMove with index + 1 for Move down', async () => {
    const user = userEvent.setup();
    const { onMove } = renderMenu({ currentIndex: 1 });

    await user.tab();
    await user.keyboard('{Enter}');
    await user.keyboard('{ArrowDown}{ArrowDown}{ArrowDown}{Enter}');

    expect(onMove).toHaveBeenCalledWith(1, 2);
});

it('disables Move up at the first position', async () => {
    const user = userEvent.setup();
    renderMenu({ currentIndex: 0 });

    await user.tab();
    await user.keyboard('{Enter}');

    expect(screen.getByRole('menuitem', { name: 'Move up' })).toHaveAttribute(
        'aria-disabled',
        'true',
    );
    expect(screen.getByRole('menuitem', { name: 'Move down' })).not.toHaveAttribute(
        'aria-disabled',
    );
});

it('disables Move down at the last position', async () => {
    const user = userEvent.setup();
    renderMenu({ currentIndex: 2, columnSize: 3 });

    await user.tab();
    await user.keyboard('{Enter}');

    expect(screen.getByRole('menuitem', { name: 'Move down' })).toHaveAttribute(
        'aria-disabled',
        'true',
    );
    expect(screen.getByRole('menuitem', { name: 'Move up' })).not.toHaveAttribute('aria-disabled');
});

it('marks the trigger aria-disabled and refuses to open while a move is pending', async () => {
    const user = userEvent.setup();
    renderMenu({ disabled: true });

    const button = screen.getByRole('button', { name: 'Move "Fix login"' });
    expect(button).toHaveAttribute('aria-disabled', 'true');

    await user.tab();
    await user.keyboard('{Enter}');

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
});
