import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { CreateTaskDialog } from '@/components/tasks/create-task-dialog';
import { Button } from '@/components/ui/button';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setPageProps,
    submitted,
} from '@/test/inertia';
import type { TaskCreateOptions } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

const options: TaskCreateOptions = {
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
    statuses: [
        { value: 'todo', label: 'To Do' },
        { value: 'in_progress', label: 'In Progress' },
        { value: 'done', label: 'Done' },
    ],
};

beforeEach(() => {
    setPageProps({
        auth: { user: { id: 5, name: 'Mia Member', email: 'mia@example.test' }, permissions: [] },
    });
});
afterEach(resetInertiaMock);

/**
 * EPIC-014 WP4 (§14.1): the create form is a `FormDialog` opened from the page header, replacing the
 * inline collapsible form with the same fields and the server-enforced assignee set (the actor or
 * nobody). It posts to the existing `tasks.store`; there is no second create endpoint.
 */
function Harness({ initiallyOpen = false }: { initiallyOpen?: boolean }) {
    const [open, setOpen] = useState(initiallyOpen);

    return (
        <>
            <Button type="button" onClick={() => setOpen(true)}>
                New task
            </Button>
            <CreateTaskDialog open={open} onOpenChange={setOpen} options={options} />
        </>
    );
}

it('renders nothing until it is opened', () => {
    render(<Harness />);

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('opens a named dialog with the title field focused', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'New task' }));

    expect(screen.getByRole('dialog', { name: 'New task' })).toBeVisible();
    expect(screen.getByLabelText(/Title/)).toHaveFocus();
});

it('offers only "Me" and "Unassigned" for the assignee, never a directory (D3, A7)', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    const select = screen.getByLabelText('Assignee') as HTMLSelectElement;
    expect(Array.from(select.options).map((option) => option.text)).toEqual(['Unassigned', 'Me']);
});

it('submits title, assignee, priority, status and due date to tasks.store', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Water the plants' } });
    await user.selectOptions(screen.getByLabelText('Assignee'), 'Me');
    await user.selectOptions(screen.getByLabelText('Priority'), 'Low');
    await user.selectOptions(screen.getByLabelText('Status'), 'Done');
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-02-01' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    expect(inertiaSpies.form.post).toHaveBeenCalledWith(
        '/tasks',
        expect.objectContaining({ preserveScroll: true }),
    );
    expect(submitted()).toEqual([
        expect.objectContaining({
            method: 'post',
            url: '/tasks',
            data: {
                title: 'Water the plants',
                description: '',
                assignee_id: 5,
                priority: 'low',
                status: 'done',
                due_date: '2026-02-01',
            },
        }),
    ]);
});

it('sends a null assignee when left unassigned', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Solo task' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    expect(submitted()[0]).toEqual(
        expect.objectContaining({ data: expect.objectContaining({ assignee_id: null }) }),
    );
});

it('closes on success and returns focus to what opened it', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));
    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Ship it' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    const { onSuccess } = submitted()[0]!.options as { onSuccess: () => void };
    act(() => onSuccess());

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    // Radix hands focus back on the next tick.
    await waitFor(() => expect(screen.getByRole('button', { name: 'New task' })).toHaveFocus());
});

it('starts empty again after a successful create', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));
    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Ship it' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));
    act(() => (submitted()[0]!.options as { onSuccess: () => void }).onSuccess());

    await user.click(screen.getByRole('button', { name: 'New task' }));

    expect(screen.getByLabelText(/Title/)).toHaveValue('');
});

it('shows an inline validation error and stays open', async () => {
    setFormErrors({ title: 'The title field is required.' });
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
    expect(screen.getByLabelText(/Title/)).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('dialog')).toBeVisible();
});

it('cancels without sending anything and returns focus', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(inertiaSpies.form.post).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'New task' })).toHaveFocus();
});

it('never renders an edit, delete or complete control (create only)', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));

    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /delete|save/i })).not.toBeInTheDocument();
});

it('guards against a same-tick double submit', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole('button', { name: 'New task' }));
    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Ship it' } });

    const form = screen.getByLabelText(/Title/).closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);

    expect(inertiaSpies.form.post).toHaveBeenCalledTimes(1);
});
