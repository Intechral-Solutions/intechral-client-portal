import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TasksIndexPage, type TasksIndexProps } from '@/pages/tasks/index';
import { chooseRadio, openMenu } from '@/test/menu';
import { inertiaSpies, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Paginated } from '@/types/pagination';
import type {
    TaskAssigneeOptions,
    TaskBulkResult,
    TaskFilterOptions,
    TaskListFilters,
    TaskRow,
} from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

let mockTimers: { context: { type: string; id: number } | null }[] = [];
vi.mock('@/components/time/timer-provider', () => ({
    useOptionalTimers: () => ({ timers: mockTimers }),
}));

function setFlash(bulk?: TaskBulkResult) {
    setPageProps({
        auth: { user: { id: 5, name: 'Mia Member', email: 'mia@example.test' }, permissions: [] },
        flash: { success: null, error: null, status: null, warning: null, bulk: bulk ?? null },
    });
}

beforeEach(() => {
    mockTimers = [];
    setFlash();
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
    completion: [
        { value: 'open', label: 'Open' },
        { value: 'done', label: 'Done' },
        { value: 'any', label: 'Any' },
    ],
    priorities: [
        { value: 'low', label: 'Low' },
        { value: 'medium', label: 'Medium' },
        { value: 'high', label: 'High' },
        { value: 'critical', label: 'Critical' },
    ],
    due: [
        { value: 'overdue', label: 'Overdue' },
        { value: 'today', label: 'Due today' },
    ],
    kinds: [
        { value: 'project', label: 'Project' },
        { value: 'standalone', label: 'Standalone' },
    ],
    sorts: [
        { value: 'due', label: 'Due date' },
        { value: 'title', label: 'Title' },
    ],
    projects: [{ id: 1, name: 'Alpha' }],
    milestones: [],
    assignees: [],
    organizations: [],
};

const projectRow: TaskRow = {
    id: 1,
    title: 'Ship it',
    kind: 'board',
    projectId: 1,
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
    projectId: null,
    priority: 'low',
    status: { label: 'To Do', done: false, source: 'status' },
    dueDate: null,
    overdue: false,
    assignee: { id: 5, name: 'Mia Member' },
    context: { kind: 'standalone', label: 'Standalone', url: null },
    url: '/tasks/3',
    abilities: { complete: true, reopen: true, assign: true },
};

const assigneeOptions: TaskAssigneeOptions = {
    self: { id: 5, name: 'Mia Member' },
    projects: [
        {
            projectId: 1,
            members: [
                { id: 5, name: 'Mia Member' },
                { id: 6, name: 'Noor Newhire' },
            ],
        },
    ],
};

const third: TaskRow = { ...projectRow, id: 4, title: 'Third thing', url: '/projects/1/tasks/4' };

const noFilters: TaskListFilters = {
    completion: 'open',
    priority: [],
    due: null,
    kind: null,
    project: null,
    milestone: null,
    assignee: null,
    organization: null,
    q: '',
};

function defaultProps(overrides: Partial<TasksIndexProps> = {}): TasksIndexProps {
    return {
        tasks: paginate([projectRow, standaloneRow]),
        view: 'mine',
        filters: noFilters,
        filterOptions,
        sort: { by: 'due', dir: 'asc' },
        canViewAll: false,
        createOptions,
        assigneeOptions,
        ...overrides,
    };
}

const row = (title: string) => screen.getByText(title).closest('tr') as HTMLElement;
const lastGet = () => inertiaSpies.router.get.mock.calls.at(-1)!;

describe('page architecture (EPIC-014 §14.1)', () => {
    it('has one h1 naming the view the server resolved, and a description that follows it', () => {
        const { rerender } = render(<TasksIndexPage {...defaultProps()} />);
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'My tasks' })).toBeVisible();
        expect(
            screen.getByText('Tasks assigned to you, and unassigned tasks you created.'),
        ).toBeVisible();

        rerender(<TasksIndexPage {...defaultProps({ view: 'all', canViewAll: true })} />);
        expect(screen.getByRole('heading', { level: 1, name: 'All tasks' })).toBeVisible();
        expect(
            screen.getByText('Every task you can see: tasks in your projects, and your own tasks.'),
        ).toBeVisible();
    });

    it('sits on the canvas frame with the filter bar above the table', () => {
        const { container } = render(<TasksIndexPage {...defaultProps()} />);

        expect(container.querySelector('[data-page-frame="canvas"]')).toBeInTheDocument();
        const group = screen.getByRole('group', { name: 'Filter tasks' });
        const table = screen.getByRole('table', { name: 'Tasks' });
        expect(
            group.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('carries no view tabs, breadcrumb or drawer duplicates of its own: the shell owns views', () => {
        render(<TasksIndexPage {...defaultProps({ canViewAll: true })} />);

        for (const name of ['Assigned to Me', 'My Organization', 'My tasks', 'All tasks']) {
            expect(screen.queryByRole('link', { name })).not.toBeInTheDocument();
        }
        expect(screen.queryByRole('navigation', { name: /breadcrumb/i })).not.toBeInTheDocument();
    });

    it('renders both surfaced kinds from the DTO, each with its own link behavior', () => {
        render(<TasksIndexPage {...defaultProps()} />);

        expect(screen.getByRole('link', { name: 'Ship it' })).toHaveAttribute(
            'href',
            '/projects/1/tasks/1',
        );
        expect(screen.getByRole('link', { name: 'Alpha' })).toHaveAttribute(
            'href',
            '/projects/1/board',
        );
        // WP5: a standalone task has its own page, `tasks.show`; its context is a label, not a link.
        expect(screen.getByRole('link', { name: 'Loose end' })).toHaveAttribute('href', '/tasks/3');
        expect(screen.queryByRole('link', { name: 'Standalone' })).not.toBeInTheDocument();
    });
});

describe('filters drive server visits (EPIC-014 §9; the server is the only filter authority)', () => {
    it('visits /tasks with the next query on a select change, preserving state and scroll', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        await user.selectOptions(screen.getByLabelText('Due'), 'Overdue');

        expect(lastGet()).toEqual([
            '/tasks',
            { due: 'overdue' },
            { preserveState: true, preserveScroll: true },
        ]);
    });

    it('keeps the served view and the other active filters in the next query', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({
                    view: 'all',
                    canViewAll: true,
                    filters: { ...noFilters, priority: ['high'], q: 'report' },
                    sort: { by: 'title', dir: 'desc' },
                })}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Completion'), 'Any');

        expect(lastGet()[1]).toEqual({
            view: 'all',
            completion: 'any',
            priority: ['high'],
            q: 'report',
            sort: 'title',
            dir: 'desc',
        });
    });

    it('exposes the completion filter, closing the WP3 → WP4 gap, and sends "any" for Any', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        expect(screen.getByLabelText('Completion')).toHaveValue('open');
        await user.selectOptions(screen.getByLabelText('Completion'), 'Done');
        expect(lastGet()[1]).toEqual({ completion: 'done' });
    });

    it('searches on submit and sorts through the server, never in the browser', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        await user.type(screen.getByRole('searchbox', { name: 'Search tasks' }), 'plan{Enter}');
        expect(lastGet()[1]).toEqual({ q: 'plan' });

        await user.selectOptions(screen.getByLabelText('Sort by'), 'Title');
        expect(lastGet()[1]).toEqual({ sort: 'title' });
        expect(
            screen
                .getAllByRole('row')
                .map((r) => r.textContent)
                .join(),
        ).toContain('Ship it');
    });

    it('removes a filter from its chip and clears everything from Clear filters', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({ filters: { ...noFilters, due: 'overdue', kind: 'project' } })}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Remove filter: Due: Overdue' }));
        expect(lastGet()[1]).toEqual({ kind: 'project' });

        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(lastGet()[1]).toEqual({});
    });

    it('shows no assignee control in My Tasks and offers it in All Tasks', () => {
        const { rerender } = render(<TasksIndexPage {...defaultProps()} />);
        expect(screen.queryByLabelText('Assignee')).not.toBeInTheDocument();

        rerender(<TasksIndexPage {...defaultProps({ view: 'all', canViewAll: true })} />);
        expect(screen.getByLabelText('Assignee')).toBeVisible();
    });

    it('keeps a filter the server applied but did not label, clearable and without a name (owner decision B)', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({
                    tasks: paginate([]),
                    filters: { ...noFilters, project: 777 },
                    filterOptions: { ...filterOptions, projects: [] },
                })}
            />,
        );

        expect(screen.getByText('Project filter', { selector: 'span' })).toBeVisible();
        expect(screen.getByText('No tasks match these filters.')).toBeVisible();
        expect(document.body).not.toHaveTextContent('777');

        await user.click(screen.getByRole('button', { name: 'Remove filter: Project filter' }));
        expect(lastGet()[1]).toEqual({});
    });
});

describe('empty states (Direction D §15.2)', () => {
    it('says what will appear in a truly empty view and offers the create action', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([]) })} />);

        expect(screen.getByText('No open tasks')).toBeVisible();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
        expect(screen.queryByText('No tasks match these filters.')).not.toBeInTheDocument();

        const [, emptyAction] = screen.getAllByRole('button', { name: 'New task' });
        await user.click(emptyAction!);
        expect(screen.getByRole('dialog', { name: 'New task' })).toBeVisible();
    });

    it('words the truly empty All Tasks view for All Tasks', () => {
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([]), view: 'all', canViewAll: true })}
            />,
        );

        expect(
            screen.getByText('Tasks in your projects and your own tasks will appear here.'),
        ).toBeVisible();
    });

    it('is a filtered empty state when anything narrows the view, with Clear filters', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([]), filters: { ...noFilters, due: 'today' } })}
            />,
        );

        expect(screen.getByText('No tasks match these filters.')).toBeVisible();
        expect(screen.queryByText('No open tasks')).not.toBeInTheDocument();

        const group = screen.getByRole('group', { name: 'Filter tasks' });
        await user.click(within(group).getByRole('button', { name: 'Clear filters' }));
        expect(lastGet()[1]).toEqual({});
    });

    it('treats an active search as narrowing', () => {
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([]), filters: { ...noFilters, q: 'zzz' } })}
            />,
        );

        expect(screen.getByText('No tasks match these filters.')).toBeVisible();
    });

    it('treats a non-default completion as narrowing, so "no done tasks" is not "no tasks yet"', () => {
        render(
            <TasksIndexPage
                {...defaultProps({
                    tasks: paginate([]),
                    filters: { ...noFilters, completion: 'done' },
                })}
            />,
        );

        expect(screen.getByText('No tasks match these filters.')).toBeVisible();
    });
});

describe('Complete and Reopen (EPIC-014 §8, §15.1)', () => {
    it("completes through the WP2 endpoint, from the server's ability, without inventing state", async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        await user.click(screen.getByRole('button', { name: 'Complete Ship it' }));

        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/1/complete',
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
        // The row is unchanged until the server answers.
        expect(
            within(row('Ship it')).getByRole('button', { name: 'Complete Ship it' }),
        ).toBeVisible();
    });

    it('reopens a done task through the reopen endpoint', async () => {
        const user = userEvent.setup();
        const done = {
            ...projectRow,
            status: { label: 'Done', done: true, source: 'column' as const },
        };
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([done]) })} />);

        await user.click(screen.getByRole('button', { name: 'Reopen Ship it' }));

        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/1/reopen',
            {},
            expect.anything(),
        );
    });

    it('offers no ring to a viewer without the ability', () => {
        render(
            <TasksIndexPage
                {...defaultProps({
                    tasks: paginate([
                        {
                            ...standaloneRow,
                            abilities: { complete: false, reopen: false, assign: false },
                        },
                    ]),
                })}
            />,
        );

        expect(screen.queryByRole('button', { name: /Complete|Reopen/ })).not.toBeInTheDocument();
    });

    it('ignores a second activation while the first is in flight, and accepts one afterwards', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);
        const ring = screen.getByRole('button', { name: 'Complete Ship it' });

        await user.click(ring);
        await user.click(ring);
        expect(inertiaSpies.router.put).toHaveBeenCalledTimes(1);

        const options = inertiaSpies.router.put.mock.calls[0]![2] as { onFinish: () => void };
        act(() => options.onFinish());
        await user.click(screen.getByRole('button', { name: 'Complete Ship it' }));
        expect(inertiaSpies.router.put).toHaveBeenCalledTimes(2);
    });

    it('presents a board configuration error where the action happened, accessibly, and can dismiss it', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);
        await user.click(screen.getByRole('button', { name: 'Complete Ship it' }));

        const options = inertiaSpies.router.put.mock.calls[0]![2] as {
            onError: (errors: Record<string, string>) => void;
            onFinish: () => void;
        };
        act(() => {
            options.onError({
                complete:
                    "This project's board has no single Done column, so tasks cannot be completed from here.",
            });
            options.onFinish();
        });

        const alert = screen.getByRole('alert');
        expect(alert).toHaveTextContent('Ship it');
        expect(alert).toHaveTextContent('has no single Done column');

        await user.click(within(alert).getByRole('button', { name: 'Dismiss message' }));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('moves focus to the next row after a keyboard Complete, as Direction D §14.2 asks', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([projectRow, standaloneRow, third]) })}
            />,
        );
        row('Ship it').focus();

        await user.keyboard('e');
        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/1/complete',
            {},
            expect.anything(),
        );

        const options = inertiaSpies.router.put.mock.calls[0]![2] as {
            onSuccess: () => void;
            onFinish: () => void;
        };
        act(() => {
            options.onSuccess();
            options.onFinish();
        });
        // The open list no longer holds the completed row.
        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([standaloneRow, third]) })} />);

        expect(row('Loose end')).toHaveFocus();
    });

    it('leaves focus alone after a mouse Complete from elsewhere on the page', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ tasks: paginate([projectRow, standaloneRow]) })} />,
        );

        await user.click(screen.getByRole('searchbox'));
        // The ring is clicked without taking focus (the pointer-only path in Safari).
        screen.getByRole('button', { name: 'Complete Ship it' }).click();
        const options = inertiaSpies.router.put.mock.calls[0]![2] as { onSuccess: () => void };
        act(() => options.onSuccess());
        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([standaloneRow]) })} />);

        expect(row('Loose end')).not.toHaveFocus();
    });

    it('hands focus to the empty state when Complete removes the only row', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ tasks: paginate([projectRow]) })} />,
        );
        row('Ship it').focus();

        await user.keyboard('e');
        const options = inertiaSpies.router.put.mock.calls[0]![2] as {
            onSuccess: () => void;
            onFinish: () => void;
        };
        act(() => {
            options.onSuccess();
            options.onFinish();
        });
        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([]) })} />);

        const region = screen.getByText('No open tasks').closest('[tabindex="-1"]');
        expect(region).toHaveFocus();
    });

    it('does not move focus when the server refuses the action, even though new props arrive with the error', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([projectRow, standaloneRow, third]) })}
            />,
        );
        row('Ship it').focus();

        await user.keyboard('e');
        const options = inertiaSpies.router.put.mock.calls[0]![2] as {
            onError: (errors: Record<string, string>) => void;
            onFinish: () => void;
        };
        // A refusal redirects back to the list, so the page gets fresh props (a new array holding the
        // same rows) before or after onError runs; either order must leave focus where it was.
        rerender(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([{ ...projectRow }, standaloneRow, third]) })}
            />,
        );
        act(() => {
            options.onError({ complete: 'This project has no single Done column.' });
            options.onFinish();
        });
        rerender(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([{ ...projectRow }, standaloneRow, third]) })}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('no single Done column');
        expect(row('Ship it')).toHaveFocus();
        expect(row('Loose end')).not.toHaveFocus();
    });

    it('puts focus on the search field when removing the last chip', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ filters: { ...noFilters, priority: ['high'] } })} />,
        );

        await user.click(screen.getByRole('button', { name: 'Remove filter: Priority: High' }));
        rerender(<TasksIndexPage {...defaultProps({ filters: noFilters })} />);

        expect(screen.getByRole('searchbox')).toHaveFocus();
    });

    it('opens a task with Enter on its row, as a client visit', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);
        row('Ship it').focus();

        await user.keyboard('{Enter}');

        expect(inertiaSpies.router.visit).toHaveBeenCalledWith('/projects/1/tasks/1');
    });
});

describe('bulk Complete and Reopen (EPIC-014 §15.2)', () => {
    it('shows no bulk bar until something is selected', () => {
        render(<TasksIndexPage {...defaultProps()} />);

        expect(screen.queryByRole('toolbar', { name: 'Bulk actions' })).not.toBeInTheDocument();
    });

    it('posts the selected ids with the chosen action and then drops the selection', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([projectRow, standaloneRow, third]) })}
            />,
        );

        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));
        await user.click(screen.getByRole('checkbox', { name: 'Select Third thing' }));
        const bar = screen.getByRole('toolbar', { name: 'Bulk actions' });
        expect(bar).toHaveTextContent('2 tasks selected');

        await user.click(within(bar).getByRole('button', { name: 'Complete' }));
        expect(inertiaSpies.router.post).toHaveBeenCalledWith(
            '/tasks/bulk',
            { action: 'complete', ids: [1, 4] },
            expect.objectContaining({ preserveScroll: true }),
        );

        const options = inertiaSpies.router.post.mock.calls[0]![2] as {
            onSuccess: () => void;
            onFinish: () => void;
        };
        act(() => {
            options.onSuccess();
            options.onFinish();
        });
        expect(screen.queryByRole('toolbar', { name: 'Bulk actions' })).not.toBeInTheDocument();
    });

    it('reopens the selection through the same endpoint', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        await user.click(screen.getByRole('checkbox', { name: 'Select Loose end' }));
        await user.click(
            within(screen.getByRole('toolbar')).getByRole('button', { name: 'Reopen' }),
        );

        expect(inertiaSpies.router.post).toHaveBeenCalledWith(
            '/tasks/bulk',
            { action: 'reopen', ids: [3] },
            expect.anything(),
        );
    });

    it('selects with X, selects the page from the header, and clears with Escape', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        row('Ship it').focus();
        await user.keyboard('x');
        expect(screen.getByRole('toolbar')).toHaveTextContent('1 task selected');

        await user.click(screen.getByRole('checkbox', { name: 'Select all tasks' }));
        expect(screen.getByRole('toolbar')).toHaveTextContent('2 tasks selected');

        screen.getByRole('button', { name: 'Complete' }).focus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    });

    it('returns focus to the row that held it when Clear selection removes the bar', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        row('Loose end').focus();
        await user.keyboard('x');
        await user.click(screen.getByRole('button', { name: 'Clear selection' }));

        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
        expect(row('Loose end')).toHaveFocus();
    });

    it('returns focus to the first row when the row that held it left with a bulk action', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ tasks: paginate([projectRow, standaloneRow]) })} />,
        );
        row('Ship it').focus();
        await user.keyboard('x');
        await user.click(
            within(screen.getByRole('toolbar')).getByRole('button', { name: 'Complete' }),
        );

        const options = inertiaSpies.router.post.mock.calls[0]![2] as {
            onSuccess: () => void;
            onFinish: () => void;
        };
        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([standaloneRow]) })} />);
        act(() => {
            options.onSuccess();
            options.onFinish();
        });

        expect(row('Loose end')).toHaveFocus();
    });

    it('offers each bulk action only when a selected row allows it, by server ability', async () => {
        const user = userEvent.setup();
        const completeOnly: TaskRow = {
            ...projectRow,
            abilities: { complete: true, reopen: false, assign: false },
        };
        const reopenOnly: TaskRow = {
            ...third,
            status: { label: 'Done', done: true, source: 'column' },
            abilities: { complete: false, reopen: true, assign: false },
        };
        const neither: TaskRow = {
            ...standaloneRow,
            abilities: { complete: false, reopen: false, assign: false },
        };
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([completeOnly, reopenOnly, neither]) })}
            />,
        );
        const bar = () => screen.getByRole('toolbar', { name: 'Bulk actions' });

        await user.click(screen.getByRole('checkbox', { name: 'Select Loose end' }));
        expect(within(bar()).getByRole('button', { name: 'Complete' })).toBeDisabled();
        expect(within(bar()).getByRole('button', { name: 'Reopen' })).toBeDisabled();

        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));
        expect(within(bar()).getByRole('button', { name: 'Complete' })).toBeEnabled();
        expect(within(bar()).getByRole('button', { name: 'Reopen' })).toBeDisabled();

        await user.click(screen.getByRole('checkbox', { name: 'Select Third thing' }));
        expect(within(bar()).getByRole('button', { name: 'Complete' })).toBeEnabled();
        expect(within(bar()).getByRole('button', { name: 'Reopen' })).toBeEnabled();
    });

    it('does not post twice from a double click', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);
        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));
        const complete = within(screen.getByRole('toolbar')).getByRole('button', {
            name: 'Complete',
        });

        await user.dblClick(complete);

        expect(inertiaSpies.router.post).toHaveBeenCalledTimes(1);
    });

    it('drops a selection for a row that is no longer on the page', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<TasksIndexPage {...defaultProps()} />);
        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));

        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([standaloneRow]) })} />);

        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    });

    it("reports a partial result by bucket, as counts, from the server's own summary", () => {
        setFlash({
            action: 'complete',
            succeeded: [1],
            notPermitted: [2],
            configurationError: [3],
            failed: [4],
        });
        render(<TasksIndexPage {...defaultProps()} />);

        const summary = screen.getByTestId('bulk-summary');
        expect(summary).toHaveTextContent('1 completed');
        expect(summary).toHaveTextContent('1 not permitted');
        expect(summary).toHaveTextContent('1 blocked by the project board setup');
        expect(summary).toHaveTextContent('1 failed');
    });

    it('shows no summary when everything succeeded', () => {
        setFlash({
            action: 'complete',
            succeeded: [1, 2],
            notPermitted: [],
            configurationError: [],
            failed: [],
        });
        render(<TasksIndexPage {...defaultProps()} />);

        expect(screen.queryByTestId('bulk-summary')).not.toBeInTheDocument();
    });
});

describe('running timer state (EPIC-014 §15.3)', () => {
    it("marks only the row whose task has the viewer's running timer, from the provider", () => {
        mockTimers = [
            { context: { type: 'Task', id: 1 } },
            { context: { type: 'Project', id: 3 } },
            { context: null },
        ];
        render(<TasksIndexPage {...defaultProps()} />);

        expect(within(row('Ship it')).getByText('Timer running')).toBeVisible();
        expect(within(row('Loose end')).queryByText('Timer running')).not.toBeInTheDocument();
        // State only: no row timer control (S1 is separable and not part of WP4).
        expect(screen.queryByRole('button', { name: /timer/i })).not.toBeInTheDocument();
    });
});

describe('create dialog and pagination', () => {
    it('opens the create dialog from the page header action', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps()} />);

        await user.click(screen.getByRole('button', { name: 'New task' }));

        expect(screen.getByRole('dialog', { name: 'New task' })).toBeVisible();
    });

    it('omits pagination controls when there is only one page', () => {
        render(<TasksIndexPage {...defaultProps()} />);

        expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
    });

    it('uses the server paginator links, which already carry the normalized query', () => {
        render(
            <TasksIndexPage
                {...defaultProps({
                    tasks: paginate([projectRow], {
                        last_page: 2,
                        next_page_url: '/tasks?due=overdue&page=2',
                    }),
                })}
            />,
        );

        expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
            'href',
            '/tasks?due=overdue&page=2',
        );
    });
});

describe('single-row assignment (EPIC-014 R6, WP5)', () => {
    const managed: TaskRow = {
        ...projectRow,
        abilities: { complete: true, reopen: true, assign: true },
    };

    it('assigns a standalone row to the viewer or releases it, through the narrow endpoint', async () => {
        const user = userEvent.setup();
        render(
            <TasksIndexPage
                {...defaultProps({ tasks: paginate([{ ...standaloneRow, assignee: null }]) })}
            />,
        );

        await openMenu(
            user,
            screen.getByRole('button', { name: /Change assignee of “Loose end”/ }),
        );
        await chooseRadio(user, 'Me');

        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/3/assignee',
            { assignee_id: 5 },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        );
    });

    it('assigns a managed board row to a listed project member', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([managed]) })} />);

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');

        expect(inertiaSpies.router.put.mock.calls[0]).toEqual([
            '/tasks/1/assignee',
            { assignee_id: 6 },
            expect.any(Object),
        ]);
    });

    it('offers no control on a row the server did not allow to be assigned', () => {
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([projectRow]) })} />);

        expect(screen.queryByRole('button', { name: /Change assignee/ })).not.toBeInTheDocument();
        expect(within(row('Ship it')).getByText('Mia Member')).toBeInTheDocument();
    });

    it('is single-flight per row and marks the control busy until the request finishes', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([managed]) })} />);

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');

        const trigger = screen.getByRole('button', { name: /Change assignee of “Ship it”/ });
        expect(trigger).toHaveAttribute('aria-busy', 'true');
        expect(inertiaSpies.router.put).toHaveBeenCalledTimes(1);

        const options = inertiaSpies.router.put.mock.calls[0]![2] as { onFinish: () => void };
        act(() => options.onFinish());
        expect(trigger).not.toHaveAttribute('aria-busy');
    });

    it('shows a refusal in a live alert naming the task, and clears it when dismissed', async () => {
        const user = userEvent.setup();
        render(<TasksIndexPage {...defaultProps({ tasks: paginate([managed]) })} />);

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');
        const options = inertiaSpies.router.put.mock.calls[0]![2] as {
            onError: (errors: Record<string, string>) => void;
        };
        act(() => options.onError({ assignee_id: 'The selected assignee is invalid.' }));

        expect(screen.getByRole('alert')).toHaveTextContent('Could not change “Ship it”');
        expect(screen.getByRole('alert')).toHaveTextContent('The selected assignee is invalid.');

        await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('repairs focus onto the next row when the reassigned row leaves the view', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ tasks: paginate([managed, third]) })} />,
        );

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');
        const options = inertiaSpies.router.put.mock.calls[0]![2] as { onFinish: () => void };

        // The server moved the task out of My Tasks: the row (and the focused trigger) is gone.
        (document.activeElement as HTMLElement | null)?.blur();
        rerender(<TasksIndexPage {...defaultProps({ tasks: paginate([third]) })} />);
        act(() => options.onFinish());

        expect(row('Third thing')).toHaveFocus();
    });

    it('leaves focus alone when the row is still listed after the assignment', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <TasksIndexPage {...defaultProps({ tasks: paginate([managed, third]) })} />,
        );

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');
        rerender(
            <TasksIndexPage
                {...defaultProps({
                    tasks: paginate([
                        { ...managed, assignee: { id: 6, name: 'Noor Newhire' } },
                        third,
                    ]),
                })}
            />,
        );

        expect(row('Third thing')).not.toHaveFocus();
        expect(
            screen.getByRole('button', {
                name: 'Assignee: Noor Newhire. Change assignee of “Ship it”',
            }),
        ).toBeInTheDocument();
    });
});
