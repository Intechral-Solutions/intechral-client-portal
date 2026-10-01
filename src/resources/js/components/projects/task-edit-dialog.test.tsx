import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskEditDialog } from '@/components/projects/task-edit-dialog';
import { resetInertiaMock, setFormErrors, submitted } from '@/test/inertia';
import type { TaskDetail, TaskEditOptions } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function task(overrides: Partial<TaskDetail> = {}): TaskDetail {
    return {
        id: 1,
        title: 'Ship it',
        description: 'Do the thing.',
        priority: 'medium',
        dueDate: '2026-06-30',
        overdue: false,
        status: { label: 'To Do', done: false, source: 'column' },
        column: { id: 2, name: 'To Do', isDone: false },
        assignee: { id: 9, name: 'Ada Manager' },
        assigneeIsMember: true,
        milestone: null,
        ...overrides,
    };
}

const options: TaskEditOptions = {
    members: [
        { id: 9, name: 'Ada Manager' },
        { id: 10, name: 'Bo Member' },
    ],
    milestones: [{ id: 4, name: 'Beta' }],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
};

function renderDialog(
    overrides: { task?: TaskDetail; onOpenChange?: (open: boolean) => void } = {},
) {
    return render(
        <TaskEditDialog
            open
            onOpenChange={overrides.onOpenChange ?? vi.fn()}
            projectId={7}
            task={overrides.task ?? task()}
            options={options}
        />,
    );
}

it('is a labelled dialog that opens on the title and prefills every field', () => {
    renderDialog();

    const dialog = screen.getByRole('dialog', { name: 'Edit task' });
    expect(within(dialog).getByLabelText(/Title/)).toHaveFocus();
    expect(within(dialog).getByLabelText(/Title/)).toHaveValue('Ship it');
    expect(within(dialog).getByLabelText('Priority')).toHaveValue('medium');
    expect(within(dialog).getByLabelText('Assignee')).toHaveValue('9');
    expect(within(dialog).getByLabelText('Milestone')).toHaveValue('');
    expect(within(dialog).getByLabelText('Due date')).toHaveValue('2026-06-30');
    expect(within(dialog).getByLabelText('Description')).toHaveValue('Do the thing.');
});

it('offers no raw status field: board completion is column-authoritative', () => {
    renderDialog();

    expect(screen.queryByLabelText('Status')).not.toBeInTheDocument();
});

it('adds a departed assignee as a labelled option without folding them into the candidate pool', () => {
    renderDialog({
        task: task({ assignee: { id: 99, name: 'Xi Departed' }, assigneeIsMember: false }),
    });

    expect(screen.getByLabelText('Assignee')).toHaveValue('99');
    expect(
        screen.getByRole('option', { name: 'Xi Departed (no longer a project member)' }),
    ).toBeInTheDocument();
    expect(options.members.some((member) => member.id === 99)).toBe(false);
});

it('submits the transformed payload with numeric or null assignee/milestone ids', async () => {
    const user = userEvent.setup();
    renderDialog();

    await user.selectOptions(screen.getByLabelText('Milestone'), '4');
    await user.selectOptions(screen.getByLabelText('Assignee'), '');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    const submission = submitted()[0]!;
    expect(submission.method).toBe('put');
    expect(submission.url).toBe('/projects/7/tasks/1');
    expect(submission.data).toMatchObject({ assignee_id: null, milestone_id: 4 });
});

it('shows validation errors beside their fields and keeps the dialog open', () => {
    setFormErrors({ title: 'The title field is required.' });
    renderDialog();

    expect(screen.getByRole('alert')).toHaveTextContent('The title field is required.');
    expect(screen.getByLabelText(/Title/)).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('dialog')).toBeVisible();
});

it('closes after a successful save', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    renderDialog({ onOpenChange });

    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    (submitted()[0]!.options.onSuccess as () => void)();

    expect(onOpenChange).toHaveBeenCalledWith(false);
});
