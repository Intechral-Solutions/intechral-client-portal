import { render, screen } from '@testing-library/react';

import { TasksIndexPage, type TasksIndexProps } from '@/pages/tasks/index';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Paginated } from '@/types/pagination';
import type { TaskRow } from '@/types/tasks';

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

const projectRow: TaskRow = {
    id: 1,
    title: 'Ship it',
    priority: 'high',
    status: { label: 'To Do', done: false, source: 'column' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'project', label: 'Alpha', url: '/projects/1/board' },
    url: '/projects/1/tasks/1',
};

const ticketRow: TaskRow = {
    id: 2,
    title: 'Diagnose outage',
    priority: 'critical',
    status: { label: 'Done', done: true, source: 'status' },
    dueDate: null,
    overdue: false,
    assignee: null,
    context: { kind: 'ticket', label: 'TKT-1001', url: '/tickets/9' },
    url: null,
};

const standaloneRow: TaskRow = {
    id: 3,
    title: 'Loose end',
    priority: 'low',
    status: { label: 'To Do', done: false, source: 'status' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'standalone', label: 'Standalone', url: null },
    url: null,
};

function defaultProps(overrides: Partial<TasksIndexProps> = {}): TasksIndexProps {
    return {
        tasks: paginate([projectRow, ticketRow, standaloneRow]),
        view: 'mine',
        canViewOrg: true,
        createOptions,
        ...overrides,
    };
}

it('renders every task kind, each with its own correct link behavior', () => {
    render(<TasksIndexPage {...defaultProps()} />);

    expect(screen.getByRole('link', { name: 'Ship it' })).toHaveAttribute(
        'href',
        '/projects/1/tasks/1',
    );
    expect(screen.getByText('Diagnose outage')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Diagnose outage' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'TKT-1001' })).toHaveAttribute('href', '/tickets/9');
    expect(screen.getByText('Loose end')).toBeInTheDocument();
    expect(screen.getByText('Standalone')).toBeInTheDocument();
});

it('shows both view tabs when the org tab is available, "Assigned to Me" marked current', () => {
    render(<TasksIndexPage {...defaultProps({ view: 'mine' })} />);

    const mine = screen.getByRole('link', { name: 'Assigned to Me' });
    const org = screen.getByRole('link', { name: 'My Organization' });
    expect(mine).toHaveAttribute('aria-current', 'page');
    expect(org).not.toHaveAttribute('aria-current');
    expect(org).toHaveAttribute('href', expect.stringContaining('view=org'));
});

it('hides the org tab entirely when canViewOrg is false', () => {
    render(<TasksIndexPage {...defaultProps({ canViewOrg: false })} />);

    expect(screen.queryByRole('link', { name: 'My Organization' })).not.toBeInTheDocument();
});

it('marks the org tab current when viewing it', () => {
    render(<TasksIndexPage {...defaultProps({ view: 'org' })} />);

    expect(screen.getByRole('link', { name: 'My Organization' })).toHaveAttribute(
        'aria-current',
        'page',
    );
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
