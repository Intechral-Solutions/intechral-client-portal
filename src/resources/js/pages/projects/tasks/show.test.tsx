import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TimerProvider } from '@/components/time/timer-provider';
import { ProjectTaskShowPage, type ProjectTaskShowProps } from '@/pages/projects/tasks/show';
import { openMenu } from '@/test/menu';
import { navigation, projects, shellUser } from '@/components/shell/shell-fixtures';
import { inertiaSpies, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { TaskDetail, TaskEditOptions } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

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

const manage = {
    manage: true,
    comment: true,
    toggleChecklist: true,
    logTime: true,
    complete: true,
    reopen: true,
};

function renderPage(overrides: Partial<ProjectTaskShowProps> = {}) {
    const props: ProjectTaskShowProps = {
        project: { id: 7, name: 'Portal rebuild' },
        task: task(),
        checklist: [],
        comments: [],
        options,
        abilities: manage,
        timeSummary: null,
        ...overrides,
    };

    return render(
        <TimerProvider enabled={false}>
            <ProjectTaskShowPage {...props} />
        </TimerProvider>,
    );
}

describe('the shared Direction D detail grammar', () => {
    it('is a grid frame with an entity header, strata and a labelled aside', () => {
        const { container } = renderPage();

        expect(container.querySelector('[data-page-frame="grid"]')).not.toBeNull();
        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'Ship it' })).toBeInTheDocument();
        expect(container.querySelector('[data-strata]')).not.toBeNull();
        expect(screen.getByRole('complementary', { name: 'Task details' })).toBeInTheDocument();
    });

    it('names the project in the overline, with no link of its own', () => {
        renderPage();

        expect(screen.getByText('Task · Portal rebuild')).toBeInTheDocument();
    });

    it('draws no navigation landmark of its own (A13.12): the shell supplies the trail', () => {
        renderPage();

        expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Portal rebuild' })).not.toBeInTheDocument();
    });

    it('keeps the board sections and the board-only details', () => {
        renderPage({ task: task({ milestone: { id: 4, name: 'Beta' } }) });

        for (const name of ['Description', 'Checklist', 'Comments', 'Details', 'Time']) {
            expect(screen.getByRole('heading', { level: 2, name })).toBeInTheDocument();
        }
        const terms = screen.getAllByRole('term').map((term) => term.textContent);
        expect(terms).toEqual(['Assignee', 'Due date', 'Priority', 'Milestone', 'Column']);
        expect(screen.getByText('Beta')).toBeInTheDocument();
    });
});

describe('priority label for a viewer without edit options', () => {
    it.each([
        ['low', 'Low'],
        ['high', 'High'],
        ['critical', 'Critical'],
    ] as const)('names %s as %s, not the raw value', (value, label) => {
        renderPage({
            options: null,
            abilities: { ...manage, manage: false },
            task: task({ priority: value }),
        });

        const priority = screen.getByText('Priority').closest('div')!;
        expect(within(priority).getByText(label)).toBeInTheDocument();
        expect(within(priority).queryByText(value)).not.toBeInTheDocument();
    });
});

describe('the shell trail seam (A13.12)', () => {
    function renderInShell() {
        const props = baseProps();
        setPageProps({
            auth: { user: shellUser, permissions: [] },
            shell: { presentation: 'operational' },
            navigation: navigation([projects], 'projects'),
            ...props,
        });
        const layout = ProjectTaskShowPage.layout as (
            page: React.ReactElement,
        ) => React.ReactElement;

        return render(layout(<ProjectTaskShowPage {...props} />));
    }

    it("puts Project › Task in the shell's ONE breadcrumb and none in the page body", () => {
        renderInShell();

        const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
        expect(crumbs).toHaveLength(1);
        expect(within(crumbs[0]!).getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
            'href',
            '/projects/7/board',
        );
        expect(within(crumbs[0]!).getByText('Ship it')).toHaveAttribute('aria-current', 'page');
        expect(within(screen.getByRole('main')).queryByRole('navigation')).not.toBeInTheDocument();
    });

    it('survives Inertia 3 probing the layout function with the raw props object', () => {
        const layout = ProjectTaskShowPage.layout as (page: unknown) => React.ReactElement;

        expect(() => layout({ project: { id: 7 } })).not.toThrow();
    });
});

function baseProps(): ProjectTaskShowProps {
    return {
        project: { id: 7, name: 'Portal rebuild' },
        task: task(),
        checklist: [],
        comments: [],
        options,
        abilities: manage,
        timeSummary: null,
    };
}

describe('abilities', () => {
    it('shows Edit and Delete for a manager, and no raw status editor', () => {
        renderPage();

        expect(screen.getByRole('button', { name: 'Edit task' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete task' })).toBeInTheDocument();
        expect(screen.queryByLabelText('Status')).not.toBeInTheDocument();
    });

    it('hides Edit and Delete from a non-manager but keeps comments and checklist', () => {
        renderPage({
            abilities: { ...manage, manage: false, complete: false, reopen: false },
            options: null,
        });

        expect(screen.queryByRole('button', { name: 'Edit task' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete task' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Change assignee/ })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Comments' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Checklist' })).toBeInTheDocument();
    });

    it('offers Complete to a member-assignee who may not manage', () => {
        renderPage({ abilities: { ...manage, manage: false, reopen: false }, options: null });

        expect(screen.getByRole('button', { name: 'Complete task' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit task' })).not.toBeInTheDocument();
    });

    it('offers Reopen, not Complete, for a task in the Done column', () => {
        renderPage({
            task: task({
                status: { label: 'Done', done: true, source: 'column' },
                column: { id: 5, name: 'Done', isDone: true },
            }),
        });

        expect(screen.getByRole('button', { name: 'Reopen task' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Complete task' })).not.toBeInTheDocument();
    });

    it('completes through the WP2 route and shows a Done-column configuration error accessibly', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Complete task' }));
        expect(inertiaSpies.router.put.mock.calls[0]![0]).toBe('/tasks/1/complete');

        const callbacks = inertiaSpies.router.put.mock.calls[0]![2] as {
            onError: (errors: Record<string, string>) => void;
        };
        act(() =>
            callbacks.onError({ complete: "This project's board has no single Done column." }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent('no single Done column');
    });
});

describe('details', () => {
    it('shows the departed-assignee indicator for a viewer who cannot manage', () => {
        renderPage({
            abilities: { ...manage, manage: false, complete: false, reopen: false },
            options: null,
            task: task({ assignee: { id: 99, name: 'Xi Departed' }, assigneeIsMember: false }),
        });

        expect(screen.getByText('Xi Departed')).toBeInTheDocument();
        expect(screen.getByText('(no longer a project member)')).toBeInTheDocument();
    });

    it('lets a manager assign among the project members only, keeping a departed holder as the current value', async () => {
        const user = userEvent.setup();
        renderPage({
            options: {
                ...options,
                members: [
                    { id: 9, name: 'Ada Manager' },
                    { id: 10, name: 'Bo Member' },
                ],
            },
            task: task({ assignee: { id: 99, name: 'Xi Departed' }, assigneeIsMember: false }),
        });

        await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
        const items = await screen.findAllByRole('menuitemradio');
        expect(items.map((item) => item.textContent)).toEqual([
            'Unassigned',
            'Ada Manager',
            'Bo Member',
            'Xi Departed (no longer a project member)',
        ]);

        items[2]!.focus();
        await user.keyboard('{Enter}');
        expect(inertiaSpies.router.put).toHaveBeenCalledWith(
            '/tasks/1/assignee',
            { assignee_id: 10 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('renders a plain-text description with line breaks preserved, or an empty state', () => {
        const { rerender } = renderPage({ task: task({ description: null }) });
        expect(screen.getByText('No description.')).toBeInTheDocument();

        rerender(
            <TimerProvider enabled={false}>
                <ProjectTaskShowPage
                    {...baseProps()}
                    task={task({ description: 'Line one\nLine two' })}
                />
            </TimerProvider>,
        );
        expect(
            screen.getByText(
                (_, element) =>
                    element?.tagName === 'P' && element.textContent === 'Line one\nLine two',
            ),
        ).toBeInTheDocument();
    });

    it('keeps the contextual time panel in the aside', () => {
        renderPage();

        const aside = screen.getByRole('complementary', { name: 'Task details' });
        expect(within(aside).getByRole('heading', { level: 2, name: 'Time' })).toBeInTheDocument();
        expect(within(aside).getByRole('button', { name: 'Start timer' })).toBeInTheDocument();
    });
});
