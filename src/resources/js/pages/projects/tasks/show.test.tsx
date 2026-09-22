import { render, screen } from '@testing-library/react';

import { TimerProvider } from '@/components/time/timer-provider';
import { ProjectTaskShowPage, type ProjectTaskShowProps } from '@/pages/projects/tasks/show';
import { resetInertiaMock } from '@/test/inertia';
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
    members: [{ id: 9, name: 'Ada Manager' }],
    milestones: [],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
};

function renderPage(overrides: Partial<ProjectTaskShowProps> = {}) {
    const props: ProjectTaskShowProps = {
        project: { id: 7, name: 'Portal rebuild' },
        task: task(),
        checklist: [],
        comments: [],
        options,
        abilities: { manage: true, comment: true, toggleChecklist: true, logTime: true },
        timeSummary: null,
        ...overrides,
    };

    return render(
        <TimerProvider enabled={false}>
            <ProjectTaskShowPage {...props} />
        </TimerProvider>,
    );
}

it('links back to the board via Inertia', () => {
    renderPage();

    const link = screen.getByRole('link', { name: 'Portal rebuild' });
    expect(link).toHaveAttribute('href', '/projects/7/board');
});

it('shows the manager edit panel and delete action when abilities.manage is true', () => {
    renderPage({
        abilities: { manage: true, comment: true, toggleChecklist: true, logTime: true },
    });

    expect(screen.getByRole('heading', { name: 'Edit task' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete task' })).toBeInTheDocument();
});

it('hides the edit panel and delete action for a non-manager but keeps comments and checklist', () => {
    renderPage({
        abilities: { manage: false, comment: true, toggleChecklist: true, logTime: true },
        options: null,
    });

    expect(screen.queryByRole('heading', { name: 'Edit task' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete task' })).not.toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Comments' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Checklist' })).toBeInTheDocument();
});

it('shows the departed-assignee indicator in the read-only details', () => {
    renderPage({
        task: task({ assignee: { id: 99, name: 'Xi Departed' }, assigneeIsMember: false }),
    });

    expect(screen.getByText('Xi Departed')).toBeInTheDocument();
    expect(screen.getByText('(no longer a project member)')).toBeInTheDocument();
});

it('renders a plain-text description with line breaks preserved, or an empty state', () => {
    const { rerender } = renderPage({ task: task({ description: null }) });
    expect(screen.getByText('No description.')).toBeInTheDocument();

    rerender(
        <TimerProvider enabled={false}>
            <ProjectTaskShowPage
                project={{ id: 7, name: 'Portal rebuild' }}
                task={task({ description: 'Line one\nLine two' })}
                checklist={[]}
                comments={[]}
                options={options}
                abilities={{ manage: true, comment: true, toggleChecklist: true, logTime: true }}
                timeSummary={null}
            />
        </TimerProvider>,
    );
    expect(
        screen.getByText((_, element) => element?.textContent === 'Line one\nLine two'),
    ).toBeInTheDocument();
});
