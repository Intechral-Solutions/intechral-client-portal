import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskCompleteControl } from '@/components/tasks/task-complete-control';
import type { TaskRow } from '@/types/tasks';

const task: TaskRow = {
    id: 1,
    title: 'Ship it',
    kind: 'board',
    projectId: 1,
    priority: 'high',
    status: { label: 'To Do', done: false, source: 'column' },
    dueDate: null,
    overdue: false,
    assignee: null,
    context: { kind: 'project', label: 'Alpha', url: null },
    url: null,
    abilities: { complete: true, reopen: true, assign: false },
};
const done: TaskRow = { ...task, status: { label: 'Done', done: true, source: 'column' } };

/**
 * EPIC-014 WP4 (§14.1, §15.1; Direction D §10.2): the hollow ring is the Complete control and a ring
 * with a check is Done. It renders what the server's `abilities` allow, never a role guess, and a
 * hidden control never substitutes for the server's 403.
 */
describe('TaskCompleteControl', () => {
    it('is a button named "Complete <title>" for an open task the viewer may complete', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        render(<TaskCompleteControl task={task} pending={false} onToggle={onToggle} />);

        await user.click(screen.getByRole('button', { name: 'Complete Ship it' }));

        expect(onToggle).toHaveBeenCalledWith(task);
    });

    it('is a button named "Reopen <title>" for a done task the viewer may reopen', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        render(<TaskCompleteControl task={done} pending={false} onToggle={onToggle} />);

        await user.click(screen.getByRole('button', { name: 'Reopen Ship it' }));

        expect(onToggle).toHaveBeenCalledWith(done);
    });

    it('renders no button when the ability is absent, rather than a control that can only 403', () => {
        const { rerender } = render(
            <TaskCompleteControl
                task={{ ...task, abilities: { complete: false, reopen: true, assign: false } }}
                pending={false}
                onToggle={() => {}}
            />,
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <TaskCompleteControl
                task={{ ...done, abilities: { complete: true, reopen: false, assign: false } }}
                pending={false}
                onToggle={() => {}}
            />,
        );
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('draws a different shape for done, so state is never colour alone', () => {
        const { container, rerender } = render(
            <TaskCompleteControl task={task} pending={false} onToggle={() => {}} />,
        );
        expect(container.querySelector('[data-state="open"]')).toBeInTheDocument();
        expect(container.querySelector('path')).not.toBeInTheDocument();

        rerender(<TaskCompleteControl task={done} pending={false} onToggle={() => {}} />);
        expect(container.querySelector('[data-state="done"]')).toBeInTheDocument();
        expect(container.querySelector('path')).toBeInTheDocument();
    });

    it('ignores activation while pending, without losing focus to a disabled control', async () => {
        const user = userEvent.setup();
        const onToggle = vi.fn();
        render(<TaskCompleteControl task={task} pending onToggle={onToggle} />);
        const button = screen.getByRole('button', { name: 'Complete Ship it' });

        button.focus();
        await user.click(button);
        await user.keyboard('{Enter}');

        expect(onToggle).not.toHaveBeenCalled();
        expect(button).toHaveAttribute('aria-disabled', 'true');
        expect(button).toHaveAttribute('aria-busy', 'true');
        expect(button).toHaveFocus();
    });

    it('does not state the done/open state twice for assistive technology when it is not a control', () => {
        const { container } = render(
            <TaskCompleteControl
                task={{ ...task, abilities: { complete: false, reopen: false, assign: false } }}
                pending={false}
                onToggle={() => {}}
            />,
        );

        // The Status column already says "To Do"; the ring is decoration here.
        expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
    });
});
