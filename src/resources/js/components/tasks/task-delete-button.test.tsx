import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskDeleteButton } from '@/components/tasks/task-delete-button';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function renderButton() {
    return render(<TaskDeleteButton url="/projects/7/tasks/3" taskTitle="Ship it" />);
}

it('asks for confirmation and does nothing until confirmed', async () => {
    const user = userEvent.setup();
    renderButton();

    await user.click(screen.getByRole('button', { name: 'Delete task' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete this task?' });
    expect(dialog).toHaveAccessibleDescription(
        'This permanently removes "Ship it". It cannot be undone.',
    );
    expect(submitted()).toHaveLength(0);

    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(submitted()).toHaveLength(0);
});

it('submits a server-confirmed delete for the task URL', async () => {
    const user = userEvent.setup();
    renderButton();

    await user.click(screen.getByRole('button', { name: 'Delete task' }));
    await user.click(
        within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete task' }),
    );

    const submission = submitted()[0]!;
    expect(submission.method).toBe('delete');
    expect(submission.url).toBe('/projects/7/tasks/3');
});

it('shows the D4 refusal inside the dialog and clears it when dismissed', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'This task has recorded time and cannot be deleted.' });
    renderButton();

    await user.click(screen.getByRole('button', { name: 'Delete task' }));
    expect(within(screen.getByRole('dialog')).getByRole('alert')).toHaveTextContent(
        'This task has recorded time and cannot be deleted.',
    );

    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }));
    await user.click(screen.getByRole('button', { name: 'Delete task' }));
    expect(within(screen.getByRole('dialog')).queryByRole('alert')).not.toBeInTheDocument();
});
