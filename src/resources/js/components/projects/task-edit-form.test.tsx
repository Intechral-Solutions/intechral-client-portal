import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TaskEditForm } from '@/components/projects/task-edit-form';
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

it('prefills every field from the task', () => {
    render(<TaskEditForm projectId={7} task={task()} options={options} />);

    expect(screen.getByLabelText('Title')).toHaveValue('Ship it');
    expect(screen.getByLabelText('Priority')).toHaveValue('medium');
    expect(screen.getByLabelText('Assignee')).toHaveValue('9');
    expect(screen.getByLabelText('Milestone')).toHaveValue('');
    expect(screen.getByLabelText('Due date')).toHaveValue('2026-06-30');
    expect(screen.getByLabelText('Description')).toHaveValue('Do the thing.');
});

it('adds a departed assignee as a labelled option without folding them into the candidate pool', () => {
    render(
        <TaskEditForm
            projectId={7}
            task={task({ assignee: { id: 99, name: 'Xi Departed' }, assigneeIsMember: false })}
            options={options}
        />,
    );

    const select = screen.getByLabelText('Assignee') as HTMLSelectElement;
    expect(select).toHaveValue('99');
    expect(
        screen.getByRole('option', { name: 'Xi Departed (no longer a project member)' }),
    ).toBeInTheDocument();
    // Never added to the reusable candidate list itself.
    expect(options.members.some((member) => member.id === 99)).toBe(false);
});

it('submits the transformed payload with numeric or null assignee/milestone ids', async () => {
    const user = userEvent.setup();
    render(<TaskEditForm projectId={7} task={task()} options={options} />);

    await user.selectOptions(screen.getByLabelText('Milestone'), '4');
    await user.selectOptions(screen.getByLabelText('Assignee'), '');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));

    const submission = submitted()[0]!;
    expect(submission.method).toBe('put');
    expect(submission.url).toBe('/projects/7/tasks/1');
    expect(submission.data).toMatchObject({ assignee_id: null, milestone_id: 4 });
});

it('shows validation errors beside their fields', () => {
    setFormErrors({ title: 'The title field is required.' });
    render(<TaskEditForm projectId={7} task={task()} options={options} />);

    expect(screen.getByText('The title field is required.')).toBeInTheDocument();
});
