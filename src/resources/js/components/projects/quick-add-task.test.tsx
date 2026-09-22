import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { QuickAddTask } from '@/components/projects/quick-add-task';
import { inertiaSpies, resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

it('renders nothing when closed', () => {
    render(<QuickAddTask projectId={7} columnId={2} open={false} onClose={vi.fn()} />);

    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
});

it('focuses the title field when opened', () => {
    render(<QuickAddTask projectId={7} columnId={2} open onClose={vi.fn()} />);

    expect(screen.getByLabelText('New task title')).toHaveFocus();
});

it('submits column_id, title and a medium priority to projects.tasks.store', async () => {
    const user = userEvent.setup();
    render(<QuickAddTask projectId={7} columnId={2} open onClose={vi.fn()} />);

    await user.type(screen.getByLabelText('New task title'), 'Ship it');
    await user.click(screen.getByRole('button', { name: 'Add' }));

    expect(inertiaSpies.form.post).toHaveBeenCalledWith(
        '/projects/7/tasks',
        expect.objectContaining({ preserveScroll: true, only: ['columns', 'flash'] }),
    );
    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'post',
            url: '/projects/7/tasks',
            data: { title: 'Ship it', column_id: 2, priority: 'medium' },
        }),
    ]);
});

it('closes and calls onClose on success', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<QuickAddTask projectId={7} columnId={2} open onClose={onClose} />);

    await user.type(screen.getByLabelText('New task title'), 'Ship it');
    await user.click(screen.getByRole('button', { name: 'Add' }));

    const { onSuccess } = submitted()[0]!.options as { onSuccess: () => void };
    onSuccess();

    expect(onClose).toHaveBeenCalledTimes(1);
});

it('shows an inline validation error and does not close', () => {
    setFormErrors({ title: 'The title field is required.' });
    render(<QuickAddTask projectId={7} columnId={2} open onClose={vi.fn()} />);

    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
});

it('calls onClose when Cancel is clicked', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<QuickAddTask projectId={7} columnId={2} open onClose={onClose} />);

    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onClose).toHaveBeenCalledTimes(1);
});
