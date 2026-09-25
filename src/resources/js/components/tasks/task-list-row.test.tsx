import { render, screen } from '@testing-library/react';

import { TaskListRow } from '@/components/tasks/task-list-row';
import { resetInertiaMock } from '@/test/inertia';
import type { TaskRow } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const base: TaskRow = {
    id: 1,
    title: 'Ship it',
    priority: 'high',
    status: { label: 'In Progress', done: false, source: 'column' },
    dueDate: null,
    overdue: false,
    assignee: null,
    context: { kind: 'project', label: 'Alpha', url: '/projects/1/board' },
    url: '/projects/1/tasks/1',
};

function renderRow(task: TaskRow) {
    return render(
        <table>
            <tbody>
                <TaskListRow task={task} />
            </tbody>
        </table>,
    );
}

it('renders a project-board task as an Inertia link to its own task page', () => {
    renderRow(base);

    const titleLink = screen.getByRole('link', { name: 'Ship it' });
    expect(titleLink).toHaveAttribute('href', '/projects/1/tasks/1');
    expect(screen.getByRole('link', { name: 'Alpha' })).toHaveAttribute(
        'href',
        '/projects/1/board',
    );
});

it('renders a ticket task with a context link but no title link (its own task has none)', () => {
    renderRow({
        ...base,
        title: 'Diagnose outage',
        status: { label: 'Done', done: true, source: 'status' },
        context: { kind: 'ticket', label: 'TKT-1001', url: '/tickets/9' },
        url: null,
    });

    expect(screen.queryByRole('link', { name: 'Diagnose outage' })).not.toBeInTheDocument();
    expect(screen.getByText('Diagnose outage')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'TKT-1001' })).toHaveAttribute('href', '/tickets/9');
});

it('renders a standalone task with no links at all (D3)', () => {
    renderRow({
        ...base,
        title: 'Loose end',
        context: { kind: 'standalone', label: 'Standalone', url: null },
        url: null,
    });

    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('Loose end')).toBeInTheDocument();
    expect(screen.getByText('Standalone')).toBeInTheDocument();
});

it('shows a done task struck through and an unassigned task with no due date as an em dash', () => {
    renderRow({ ...base, status: { label: 'Done', done: true, source: 'column' } });

    expect(screen.getByRole('link', { name: 'Ship it' })).toHaveClass('line-through');
    // Both the assignee and due-date cells fall back to an em dash when absent.
    expect(screen.getAllByText('—')).toHaveLength(2);
});

it('shows the assignee name and formatted due date when present', () => {
    renderRow({
        ...base,
        assignee: { id: 4, name: 'Ada Admin' },
        dueDate: '2026-01-15',
        overdue: true,
    });

    expect(screen.getByText('Ada Admin')).toBeInTheDocument();
    expect(screen.getByText('Jan 15, 2026')).toHaveClass('text-[var(--text-danger)]');
});
