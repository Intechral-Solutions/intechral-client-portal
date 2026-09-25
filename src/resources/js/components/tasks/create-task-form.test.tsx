import { act, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CreateTaskForm } from '@/components/tasks/create-task-form';
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

it('is closed by default and shows the toggle', () => {
    render(<CreateTaskForm options={options} />);

    expect(screen.queryByLabelText('Title')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: '+ New task' })).toBeInTheDocument();
});

it('opens the form and focuses the title field', async () => {
    const user = userEvent.setup();
    render(<CreateTaskForm options={options} />);

    await user.click(screen.getByRole('button', { name: '+ New task' }));

    expect(screen.getByLabelText(/Title/)).toHaveFocus();
});

it('offers only "Me" and "Unassigned" for the assignee, never a directory (D3, A7)', async () => {
    const user = userEvent.setup();
    render(<CreateTaskForm options={options} />);
    await user.click(screen.getByRole('button', { name: '+ New task' }));

    const select = screen.getByLabelText('Assignee') as HTMLSelectElement;
    const optionLabels = Array.from(select.options).map((option) => option.text);
    expect(optionLabels).toEqual(['Unassigned', 'Me']);
});

it('submits title, assignee, priority, status and due date to tasks.store', async () => {
    const user = userEvent.setup();
    render(<CreateTaskForm options={options} />);
    await user.click(screen.getByRole('button', { name: '+ New task' }));

    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Water the plants' } });
    await user.selectOptions(screen.getByLabelText('Assignee'), 'Me');
    await user.selectOptions(screen.getByLabelText('Priority'), 'Low');
    await user.selectOptions(screen.getByLabelText('Status'), 'Done');
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-02-01' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    expect(inertiaSpies.form.post).toHaveBeenCalledWith(
        '/tasks',
        expect.objectContaining({ preserveScroll: true, only: ['tasks', 'flash'] }),
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
    render(<CreateTaskForm options={options} />);
    await user.click(screen.getByRole('button', { name: '+ New task' }));

    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Solo task' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    expect(submitted()[0]).toEqual(
        expect.objectContaining({ data: expect.objectContaining({ assignee_id: null }) }),
    );
});

it('closes and returns focus to the toggle on success', async () => {
    const user = userEvent.setup();
    render(<CreateTaskForm options={options} />);
    await user.click(screen.getByRole('button', { name: '+ New task' }));
    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Ship it' } });
    await user.click(screen.getByRole('button', { name: 'Create task' }));

    const { onSuccess } = submitted()[0]!.options as { onSuccess: () => void };
    act(() => onSuccess());

    expect(screen.queryByLabelText(/Title/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: '+ New task' })).toHaveFocus();
});

it('shows an inline validation error and keeps the form open, not swallowed by a collapsed default (X5)', () => {
    setFormErrors({ title: 'The title field is required.' });
    render(<CreateTaskForm options={options} />);

    // The form starts open on its own, with no click needed: a validation error arriving with
    // the page must never be hidden behind a "+ New task" toggle the viewer has not pressed.
    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
    expect(screen.getByLabelText(/Title/)).toBeInTheDocument();
});

it('never renders an edit, delete, or complete control (create-only, D3)', async () => {
    const user = userEvent.setup();
    render(<CreateTaskForm options={options} />);
    await user.click(screen.getByRole('button', { name: '+ New task' }));

    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /save/i })).not.toBeInTheDocument();
});

it('guards against a same-tick double submit', async () => {
    render(<CreateTaskForm options={options} />);
    await userEvent.setup().click(screen.getByRole('button', { name: '+ New task' }));
    fireEvent.change(screen.getByLabelText(/Title/), { target: { value: 'Ship it' } });

    const form = screen.getByLabelText(/Title/).closest('form')!;
    fireEvent.submit(form);
    fireEvent.submit(form);

    expect(inertiaSpies.form.post).toHaveBeenCalledTimes(1);
});
