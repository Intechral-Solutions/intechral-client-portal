import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ProjectTasksIndexPage, type ProjectTasksIndexProps } from '@/pages/projects/tasks/index';
import { chooseRadio, openMenu } from '@/test/menu';
import { inertiaSpies, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Paginated } from '@/types/pagination';
import type {
    ProjectTaskFilterOptions,
    ProjectTaskFilters,
    ProjectTaskRow,
    TaskBulkResult,
} from '@/types/tasks';

/*
 * EPIC-015 WP3 — the project Tasks tab (§13). The shared Tasks grammar (table, row keys, assignment
 * menu, focus repair, bulk) is pinned in depth by the Tasks workspace's own suites; this file pins what
 * project scope changes and that the shared behaviour is wired here too.
 */

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

function paginate(
    data: ProjectTaskRow[],
    overrides: Partial<Paginated<ProjectTaskRow>> = {},
): Paginated<ProjectTaskRow> {
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

function taskRow(overrides: Partial<ProjectTaskRow> = {}): ProjectTaskRow {
    const id = overrides.id ?? 1;

    return {
        id,
        title: 'Ship it',
        kind: 'board',
        projectId: 7,
        priority: 'high',
        status: { label: 'In Progress', done: false, source: 'column' },
        dueDate: null,
        overdue: false,
        assignee: { id: 5, name: 'Mia Member' },
        context: { kind: 'project', label: 'Apollo', url: '/projects/7' },
        url: `/projects/7/tasks/${id}`,
        abilities: { complete: true, reopen: true, assign: false },
        milestone: { id: 31, name: 'Go-live' },
        ...overrides,
    };
}

const first = taskRow();
const second = taskRow({ id: 2, title: 'Write docs', milestone: null });
const third = taskRow({ id: 3, title: 'Third thing' });

const noFilters: ProjectTaskFilters = {
    completion: 'open',
    priority: [],
    due: null,
    milestone: null,
    assignee: null,
    q: '',
};

const filterOptions: ProjectTaskFilterOptions = {
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
    sorts: [
        { value: 'due', label: 'Due date' },
        { value: 'title', label: 'Title' },
        { value: 'board', label: 'Board order' },
    ],
    milestones: [
        { id: 31, name: 'Go-live' },
        { id: 32, name: 'Handover' },
    ],
    assignees: [
        { id: 5, name: 'Mia Member' },
        { id: 6, name: 'Noor Newhire' },
    ],
};

function props(overrides: Partial<ProjectTasksIndexProps> = {}): ProjectTasksIndexProps {
    return {
        project: { id: 7, name: 'Apollo', status: 'active' },
        tasks: paginate([first, second]),
        filters: noFilters,
        filterOptions,
        sort: { by: 'due', dir: 'asc' },
        projectHasTasks: true,
        assigneeOptions: { self: { id: 5, name: 'Mia Member' }, projects: [] },
        abilities: {},
        ...overrides,
    };
}

const row = (title: string) => screen.getByText(title).closest('tr') as HTMLElement;
const lastGet = () => inertiaSpies.router.get.mock.calls.at(-1)!;

describe('the project frame (§11.1, §11.4)', () => {
    it('names the project as the one h1, with its lifecycle and the Tasks tab current', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'Apollo' })).toBeInTheDocument();
        expect(screen.getByText('Active')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Tasks' })).toBeInTheDocument();

        const nav = screen.getByRole('navigation', { name: 'Project' });
        expect(
            within(nav)
                .getAllByRole('link')
                .map((link) => [link.textContent, link.getAttribute('href')]),
        ).toEqual([
            ['Overview', '/projects/7'],
            ['Board', '/projects/7/board'],
            ['Tasks', '/projects/7/tasks'],
            ['Milestones', '/projects/7/milestones'],
        ]);
        expect(
            within(nav)
                .getAllByRole('link')
                .filter((link) => link.getAttribute('aria-current') === 'page'),
        ).toEqual([within(nav).getByRole('link', { name: 'Tasks' })]);
        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    });

    it('draws no breadcrumb of its own: the shell owns the trail', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        expect(screen.queryByRole('navigation', { name: /breadcrumb/i })).not.toBeInTheDocument();
    });

    it('offers Settings only when the server says so (A9), and never a create action (P8)', () => {
        const { rerender } = render(<ProjectTasksIndexPage {...props()} />);
        expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();

        rerender(<ProjectTasksIndexPage {...props({ abilities: { openSettings: true } })} />);
        expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
            'href',
            '/projects/7/edit',
        );

        expect(screen.queryByRole('button', { name: /new task|add task|create/i })).toBeNull();
        expect(screen.queryByRole('link', { name: /new task|add task|create/i })).toBeNull();
    });

    it('sits on the canvas frame with the filter bar above the table', () => {
        const { container } = render(<ProjectTasksIndexPage {...props()} />);

        expect(container.querySelector('[data-page-frame="canvas"]')).toBeInTheDocument();
        const group = screen.getByRole('group', { name: 'Filter tasks' });
        const table = screen.getByRole('table', { name: 'Tasks' });
        expect(
            group.compareDocumentPosition(table) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });
});

describe('project rows (§13.3)', () => {
    it('shows Milestone in place of Context and never repeats the project on a row', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        const headers = screen
            .getAllByRole('columnheader')
            .map((header) => header.textContent?.trim());
        expect(headers).toContain('Milestone');
        expect(headers).not.toContain('Context');

        const table = screen.getByRole('table', { name: 'Tasks' });
        expect(within(table).queryByText('Apollo')).not.toBeInTheDocument();
        // Every row is a board task here, so the source tag would say nothing.
        expect(within(table).queryByText('Board')).not.toBeInTheDocument();
    });

    it('names the milestone, or says there is none, in text', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        const milestone = row('Ship it').querySelector('[data-cell="milestone"]')!;
        expect(milestone).toHaveTextContent('Milestone: Go-live');
        expect(milestone).toHaveAttribute('title', 'Go-live');
        expect(row('Write docs').querySelector('[data-cell="milestone"]')).toHaveTextContent(
            'No milestone',
        );
    });

    it('keeps the milestone in the second band at S, as the flexible item, so a row stays two bands', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        const cell = row('Ship it').querySelector('[data-cell="milestone"]')!.closest('td')!;
        expect(cell).toHaveClass('max-md:order-4', 'max-md:grow', 'max-md:truncate');
        expect(cell).not.toHaveClass('max-md:sr-only');
    });

    it("links each title to the task's project page", () => {
        render(<ProjectTasksIndexPage {...props()} />);

        expect(screen.getByRole('link', { name: 'Ship it' })).toHaveAttribute(
            'href',
            '/projects/7/tasks/1',
        );
    });

    it("shows the viewer's running timer from the shell's TimerProvider, with no fetch", () => {
        mockTimers = [{ context: { type: 'Task', id: 2 } }];
        render(<ProjectTasksIndexPage {...props()} />);

        expect(within(row('Write docs')).getByText('Timer running')).toBeInTheDocument();
        expect(within(row('Ship it')).queryByText('Timer running')).not.toBeInTheDocument();
    });
});

describe('row actions reuse the Tasks endpoints (§13.3)', () => {
    it('renders Complete and Reopen only from each row’s abilities', () => {
        render(
            <ProjectTasksIndexPage
                {...props({
                    tasks: paginate([
                        first,
                        taskRow({
                            id: 2,
                            title: 'Read only',
                            abilities: { complete: false, reopen: false, assign: false },
                        }),
                    ]),
                })}
            />,
        );

        expect(screen.getByRole('button', { name: 'Complete Ship it' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Complete Read only' })).toBeNull();
    });

    it('completes through tasks.complete and moves focus to the next row (Direction D §14.2)', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <ProjectTasksIndexPage {...props({ tasks: paginate([first, second, third]) })} />,
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
        rerender(<ProjectTasksIndexPage {...props({ tasks: paginate([second, third]) })} />);

        expect(row('Write docs')).toHaveFocus();
    });

    it('hands focus to the filtered-empty state when the last visible row leaves', async () => {
        const user = userEvent.setup();
        const narrowed = { ...noFilters, milestone: 31 };
        const { rerender } = render(
            <ProjectTasksIndexPage {...props({ tasks: paginate([first]), filters: narrowed })} />,
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
        rerender(<ProjectTasksIndexPage {...props({ tasks: paginate([]), filters: narrowed })} />);

        expect(
            screen.getByText('No tasks match these filters.').closest('[tabindex="-1"]'),
        ).toHaveFocus();
    });

    it('assigns through tasks.assignee.update from the server’s candidate pool', async () => {
        const user = userEvent.setup();
        render(
            <ProjectTasksIndexPage
                {...props({
                    tasks: paginate([
                        taskRow({ abilities: { complete: true, reopen: true, assign: true } }),
                    ]),
                    assigneeOptions: {
                        self: { id: 5, name: 'Mia Member' },
                        projects: [
                            {
                                projectId: 7,
                                members: [
                                    { id: 5, name: 'Mia Member' },
                                    { id: 6, name: 'Noor Newhire' },
                                ],
                            },
                        ],
                    },
                })}
            />,
        );

        await openMenu(user, screen.getByRole('button', { name: /Change assignee of “Ship it”/ }));
        await chooseRadio(user, 'Noor Newhire');

        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/1/assignee',
            { assignee_id: 6 },
            expect.anything(),
        );
    });

    it('bulk-completes the selection through the shared /tasks/bulk endpoint', async () => {
        const user = userEvent.setup();
        render(<ProjectTasksIndexPage {...props()} />);

        await user.click(screen.getByRole('checkbox', { name: 'Select Ship it' }));
        await user.click(
            within(screen.getByRole('toolbar', { name: 'Bulk actions' })).getByRole('button', {
                name: 'Complete',
            }),
        );

        expect(inertiaSpies.router.post).toHaveBeenCalledWith(
            '/tasks/bulk',
            { action: 'complete', ids: [1] },
            expect.anything(),
        );
    });

    it('summarizes a bulk outcome that changed nothing for some tasks', () => {
        setFlash({
            action: 'complete',
            succeeded: [1],
            notPermitted: [2],
            configurationError: [],
            failed: [],
        });
        render(<ProjectTasksIndexPage {...props()} />);

        expect(screen.getByTestId('bulk-summary')).toHaveTextContent(
            '1 completed. Not changed: 1 not permitted.',
        );
    });
});

describe('filters drive visits to this project’s Tasks tab (§13.2)', () => {
    it('visits projects.tasks.index with the next query, preserving state and scroll', async () => {
        const user = userEvent.setup();
        render(<ProjectTasksIndexPage {...props()} />);

        await user.selectOptions(screen.getByLabelText('Due'), 'Overdue');

        expect(lastGet()).toEqual([
            '/projects/7/tasks',
            { due: 'overdue' },
            { preserveState: true, preserveScroll: true },
        ]);
    });

    it('offers the project filters only: milestone and assignee, never project, kind or organization', () => {
        render(<ProjectTasksIndexPage {...props()} />);

        expect(screen.getByLabelText('Milestone')).toBeInTheDocument();
        expect(screen.getByLabelText('Assignee')).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Project' })).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Kind' })).not.toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Organization' })).not.toBeInTheDocument();
    });

    it('sends a milestone on its own: the project is the route’s', async () => {
        const user = userEvent.setup();
        render(<ProjectTasksIndexPage {...props()} />);

        await user.selectOptions(screen.getByLabelText('Milestone'), 'Handover');

        expect(lastGet()[1]).toEqual({ milestone: 32 });
    });

    it('offers the board order as a sort and keeps the other active filters', async () => {
        const user = userEvent.setup();
        render(
            <ProjectTasksIndexPage
                {...props({ filters: { ...noFilters, assignee: 'none', q: 'docs' } })}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Sort by'), 'Board order');

        expect(lastGet()[1]).toEqual({ assignee: 'none', q: 'docs', sort: 'board' });
    });

    it('labels active filters as chips from the offered options, and a forged assignee by type only', () => {
        render(
            <ProjectTasksIndexPage
                {...props({ filters: { ...noFilters, milestone: 31, assignee: 999 } })}
            />,
        );

        expect(screen.getByRole('button', { name: /Milestone: Go-live/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Assignee filter/ })).toBeInTheDocument();
        expect(screen.queryByText(/999/)).not.toBeInTheDocument();
    });

    it('clears every filter but keeps the sort', async () => {
        const user = userEvent.setup();
        render(
            <ProjectTasksIndexPage
                {...props({
                    tasks: paginate([]),
                    filters: { ...noFilters, milestone: 31, due: 'overdue' },
                    sort: { by: 'board', dir: 'asc' },
                })}
            />,
        );

        // The filter bar and the filtered-empty state both offer it; either clears the same way.
        for (const button of screen.getAllByRole('button', { name: 'Clear filters' })) {
            await user.click(button);
            expect(lastGet()[1]).toEqual({ sort: 'board', dir: 'asc' });
        }
    });
});

describe('empty states (§13, no create here)', () => {
    it('says a project has no tasks yet and points at the Board, where tasks are created', () => {
        render(
            <ProjectTasksIndexPage {...props({ tasks: paginate([]), projectHasTasks: false })} />,
        );

        expect(screen.getByText('No tasks in this project yet')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open board' })).toHaveAttribute(
            'href',
            '/projects/7/board',
        );
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('never says the project is empty when filters hide its tasks', () => {
        render(
            <ProjectTasksIndexPage
                {...props({ tasks: paginate([]), filters: { ...noFilters, q: 'zzz' } })}
            />,
        );

        expect(screen.getByText('No tasks match these filters.')).toBeInTheDocument();
        expect(screen.queryByText('No tasks in this project yet')).not.toBeInTheDocument();
    });

    it('says every task is done when only the open default hides them, and offers all tasks', async () => {
        const user = userEvent.setup();
        render(<ProjectTasksIndexPage {...props({ tasks: paginate([]) })} />);

        expect(screen.getByText('No open tasks')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Show all tasks' }));

        expect(lastGet()[1]).toEqual({ completion: 'any' });
    });
});
