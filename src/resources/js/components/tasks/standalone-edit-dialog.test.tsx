import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { StandaloneEditDialog } from '@/components/tasks/standalone-edit-dialog';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';
import type { StandaloneTaskDetail, TaskFormOptions } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const options: TaskFormOptions = {
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

const task: StandaloneTaskDetail = {
    id: 12,
    title: 'Pack the van',
    description: 'Line one',
    priority: 'high',
    dueDate: '2030-05-01',
    overdue: false,
    status: { label: 'In Progress', done: false, source: 'status' },
    statusValue: 'in_progress',
    assignee: { id: 3, name: 'Dana Webb' },
};

function open(overrides: { onOpenChange?: (open: boolean) => void } = {}) {
    return render(
        <StandaloneEditDialog
            open
            onOpenChange={overrides.onOpenChange ?? vi.fn()}
            task={task}
            options={options}
        />,
    );
}

it('is a labelled dialog that opens on the title and prefills every field', () => {
    open();

    const dialog = screen.getByRole('dialog', { name: 'Edit task' });
    expect(within(dialog).getByLabelText(/Title/)).toHaveFocus();
    expect(within(dialog).getByLabelText(/Title/)).toHaveValue('Pack the van');
    expect(within(dialog).getByLabelText('Status')).toHaveValue('in_progress');
    expect(within(dialog).getByLabelText('Priority')).toHaveValue('high');
    expect(within(dialog).getByLabelText('Due date')).toHaveValue('2030-05-01');
    expect(within(dialog).getByLabelText('Description')).toHaveValue('Line one');
});

it('does not offer an assignee here: assignment goes through the narrow endpoint', () => {
    open();

    expect(screen.queryByLabelText('Assignee')).not.toBeInTheDocument();
});

it('puts the standalone fields to tasks.update and never sends a project, ticket or assignee', async () => {
    const user = userEvent.setup();
    open();

    await user.clear(screen.getByLabelText(/Title/));
    await user.type(screen.getByLabelText(/Title/), 'Pack the truck');
    await user.selectOptions(screen.getByLabelText('Status'), 'done');
    await user.clear(screen.getByLabelText('Due date'));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    const submission = submitted()[0]!;
    expect(submission.method).toBe('put');
    expect(submission.url).toBe('/tasks/12');
    expect(submission.data).toEqual({
        title: 'Pack the truck',
        description: 'Line one',
        priority: 'high',
        status: 'done',
        due_date: null,
    });
    expect(Object.keys(submission.data)).not.toContain('assignee_id');
});

it('shows a validation error beside its field and keeps the dialog open', () => {
    setFormErrors({ title: 'The title field is required.' });
    open();

    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
    expect(screen.getByLabelText(/Title/)).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('dialog')).toBeVisible();
});

it('shows a status refusal beside Status, marks it invalid and associates the error', () => {
    setFormErrors({ status: 'The selected status is invalid.' });
    open();

    const status = screen.getByLabelText('Status');
    const error = within(screen.getByRole('dialog')).getByRole('alert');

    expect(error).toHaveTextContent('The selected status is invalid.');
    expect(status).toHaveAttribute('aria-invalid', 'true');
    expect(status).toHaveAttribute('aria-describedby', error.id);
    expect(screen.getByLabelText('Priority')).toHaveAttribute('aria-invalid', 'false');
});

it.each([
    ['status', /^Status$/, { status: 'The selected status is invalid.' }],
    ['title', /Title/, { title: 'The title field is required.' }],
    ['priority', /^Priority$/, { priority: 'The selected priority is invalid.' }],
    ['due date', /^Due date$/, { due_date: 'The due date is not a valid date.' }],
    ['description', /^Description$/, { description: 'The description is invalid.' }],
])('moves focus to the first invalid field when it is %s', async (_name, label, errors) => {
    const user = userEvent.setup();
    open();

    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    (submitted()[0]!.options.onError as (e: Record<string, string>) => void)(errors);

    expect(screen.getByLabelText(label)).toHaveFocus();
});

it('focuses Status when it is the first invalid field of several', async () => {
    const user = userEvent.setup();
    open();

    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    (submitted()[0]!.options.onError as (e: Record<string, string>) => void)({
        description: 'The description is invalid.',
        status: 'The selected status is invalid.',
    });

    expect(screen.getByLabelText('Status')).toHaveFocus();
});

it('closes after a successful save', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    open({ onOpenChange });

    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    (submitted()[0]!.options.onSuccess as () => void)();

    expect(onOpenChange).toHaveBeenCalledWith(false);
});

it('discards edits on cancel', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    open({ onOpenChange });

    await user.type(screen.getByLabelText(/Title/), ' extra');
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onOpenChange).toHaveBeenCalledWith(false);
});
