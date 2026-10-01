import { render, screen } from '@testing-library/react';

import { TasksIndexPage, type TasksIndexProps } from '@/pages/tasks/index';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Paginated } from '@/types/pagination';
import type { TaskFilterOptions, TaskRow } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

beforeEach(() => {
    setPageProps({
        auth: { user: { id: 5, name: 'Mia Member', email: 'mia@example.test' }, permissions: [] },
    });
});
afterEach(resetInertiaMock);

const createOptions = {
    priorities: [{ value: 'medium' as const, label: 'Medium' }],
    statuses: [{ value: 'todo' as const, label: 'To Do' }],
};

function paginate(
    data: TaskRow[],
    overrides: Partial<Paginated<TaskRow>> = {},
): Paginated<TaskRow> {
    return {
        data,
        current_page: 1,
        last_page: 1,
        from: data.length ? 1 : null,
        to: data.length,
        total: data.length,
        links: [],
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    };
}

const abilities = { complete: true, reopen: true, assign: false };

const filterOptions: TaskFilterOptions = {
    completion: [{ value: 'open', label: 'Open' }],
    priorities: [{ value: 'high', label: 'High' }],
    due: [{ value: 'overdue', label: 'Overdue' }],
    kinds: [{ value: 'project', label: 'Project' }],
    sorts: [{ value: 'due', label: 'Due date' }],
    projects: [],
    milestones: [],
    assignees: [],
    organizations: [],
};

const projectRow: TaskRow = {
    id: 1,
    title: 'Ship it',
    kind: 'board',
    priority: 'high',
    status: { label: 'To Do', done: false, source: 'column' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'project', label: 'Alpha', url: '/projects/1/board' },
    url: '/projects/1/tasks/1',
    abilities,
};

const standaloneRow: TaskRow = {
    id: 3,
    title: 'Loose end',
    kind: 'standalone',
    priority: 'low',
    status: { label: 'To Do', done: false, source: 'status' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'standalone', label: 'Standalone', url: null },
    url: null,
    abilities: { complete: true, reopen: true, assign: true },
};

function defaultProps(overrides: Partial<TasksIndexProps> = {}): TasksIndexProps {
    return {
        tasks: paginate([projectRow, standaloneRow]),
        view: 'mine',
        filters: {
            completion: 'open',
            priority: [],
            due: null,
            kind: null,
            project: null,
            milestone: null,
            assignee: null,
            organization: null,
            q: '',
        },
        filterOptions,
        sort: { by: 'due', dir: 'asc' },
        canViewAll: false,
        createOptions,
        ...overrides,
    };
}

it('renders both surfaced kinds, each with its own correct link behavior', () => {
    render(<TasksIndexPage {...defaultProps()} />);

    expect(screen.getByRole('link', { name: 'Ship it' })).toHaveAttribute(
        'href',
        '/projects/1/tasks/1',
    );
    expect(screen.getByRole('link', { name: 'Alpha' })).toHaveAttribute(
        'href',
        '/projects/1/board',
    );
    expect(screen.getByText('Loose end')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Loose end' })).not.toBeInTheDocument();
    expect(screen.getByText('Standalone')).toBeInTheDocument();
});

it('carries no view tabs of its own: My tasks and All tasks are shell drawer views (EPIC-014 §9.8)', () => {
    render(<TasksIndexPage {...defaultProps({ canViewAll: true })} />);

    for (const name of ['Assigned to Me', 'My Organization', 'My tasks', 'All tasks']) {
        expect(screen.queryByRole('link', { name })).not.toBeInTheDocument();
    }
});

it('describes the view the server resolved', () => {
    const { rerender } = render(<TasksIndexPage {...defaultProps()} />);
    expect(
        screen.getByText('Tasks assigned to you, and unassigned tasks you created.'),
    ).toBeInTheDocument();

    rerender(<TasksIndexPage {...defaultProps({ view: 'all', canViewAll: true })} />);
    expect(
        screen.getByText('Every task you can see: tasks in your projects, and your own tasks.'),
    ).toBeInTheDocument();
});

it('shows an empty state and no table when there are no tasks', () => {
    render(<TasksIndexPage {...defaultProps({ tasks: paginate([]) })} />);

    expect(screen.getByText('No tasks found.')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
});

it('renders the standalone create-task toggle', () => {
    render(<TasksIndexPage {...defaultProps()} />);

    expect(screen.getByRole('button', { name: '+ New task' })).toBeInTheDocument();
});

it('omits pagination controls when there is only one page', () => {
    render(<TasksIndexPage {...defaultProps()} />);

    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
});

it('shows pagination controls across multiple pages', () => {
    render(
        <TasksIndexPage
            {...defaultProps({
                tasks: paginate([projectRow], {
                    last_page: 2,
                    next_page_url: '/tasks?page=2',
                }),
            })}
        />,
    );

    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute('href', '/tasks?page=2');
});
