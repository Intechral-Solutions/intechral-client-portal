import { act, fireEvent, render, screen } from '@testing-library/react';
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

describe('duplicate-submission guard (EPIC-011E Amendment 6, Fix 2)', () => {
    // `fireEvent.submit`, not `userEvent`, on purpose: two submits dispatched back to back in
    // the same synchronous block, before React has had a chance to commit `form.processing` and
    // disable the button — the exact same-tick window the button's own `disabled` attribute
    // cannot cover, which is what the ref guard in QuickAddTask exists for.
    function submitTwiceInTheSameTick() {
        const titleInput = screen.getByLabelText('New task title');
        fireEvent.change(titleInput, { target: { value: 'Ship it' } });
        const form = titleInput.closest('form')!;

        fireEvent.submit(form);
        fireEvent.submit(form);

        return form;
    }

    it('sends exactly one POST for two submits in the same tick, not relying on the button’s disabled state', () => {
        render(<QuickAddTask projectId={7} columnId={2} open onClose={vi.fn()} />);

        submitTwiceInTheSameTick();

        expect(inertiaSpies.form.post).toHaveBeenCalledTimes(1);
        expect(submitted()).toHaveLength(1);
    });

    it('does not create a task optimistically: nothing is posted before the guard actually allows it', () => {
        render(<QuickAddTask projectId={7} columnId={2} open onClose={vi.fn()} />);

        // The very first submit is itself the only side effect — no separate optimistic step
        // exists to race.
        submitTwiceInTheSameTick();

        expect(submitted()).toHaveLength(1);
    });

    it('accepts a new submit once the first request has settled with a validation error, and the form stays open and editable', () => {
        const onClose = vi.fn();
        render(<QuickAddTask projectId={7} columnId={2} open onClose={onClose} />);

        const form = submitTwiceInTheSameTick();
        expect(submitted()).toHaveLength(1);

        // onFinish is the one terminal callback shared by every outcome (success, validation
        // error, HTTP/server error, network failure); a validation error specifically must leave
        // the form open rather than only clearing the guard on the success branch.
        const { onFinish } = submitted()[0]!.options as { onFinish: () => void };
        onFinish();

        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByLabelText('New task title')).toBeInTheDocument();

        fireEvent.submit(form);
        expect(submitted()).toHaveLength(2);
    });

    it('accepts a new submit once a successful request has settled and closed the form', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const { rerender } = render(
            <QuickAddTask projectId={7} columnId={2} open onClose={onClose} />,
        );

        await user.type(screen.getByLabelText('New task title'), 'Ship it');
        await user.click(screen.getByRole('button', { name: 'Add' }));
        expect(submitted()).toHaveLength(1);

        const { onSuccess, onFinish } = submitted()[0]!.options as {
            onSuccess: () => void;
            onFinish: () => void;
        };
        act(() => {
            onSuccess();
            onFinish();
        });
        // The parent controls `open`; simulate it closing the form the way board.tsx would after
        // onClose fires, then reopening it for a fresh add — the guard must not still be held.
        rerender(<QuickAddTask projectId={7} columnId={2} open={false} onClose={onClose} />);
        rerender(<QuickAddTask projectId={7} columnId={2} open onClose={onClose} />);

        await user.type(screen.getByLabelText('New task title'), 'Ship it again');
        await user.click(screen.getByRole('button', { name: 'Add' }));

        expect(submitted()).toHaveLength(2);
    });
});
