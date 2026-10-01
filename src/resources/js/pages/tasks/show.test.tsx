import { act, render, screen, within } from '@testing-library/react';
import type { ReactElement } from 'react';
import userEvent from '@testing-library/user-event';

import { TimerProvider } from '@/components/time/timer-provider';
import { TasksShowPage, type TasksShowProps } from '@/pages/tasks/show';
import { chooseRadio, openMenu } from '@/test/menu';
import { navigation, shellUser, tasks } from '@/components/shell/shell-fixtures';
import {
    inertiaSpies,
    resetInertiaMock,
    setFormErrors,
    setPageProps,
    submitted,
} from '@/test/inertia';
import type { StandaloneTaskDetail } from '@/types/tasks';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

afterEach(resetInertiaMock);

const task: StandaloneTaskDetail = {
    id: 12,
    title: 'Pack the van',
    description: 'Line one\nLine two',
    priority: 'high',
    dueDate: '2030-05-01',
    overdue: false,
    status: { label: 'In Progress', done: false, source: 'status' },
    statusValue: 'in_progress',
    assignee: { id: 3, name: 'Dana Webb' },
};

const props: TasksShowProps = {
    task,
    abilities: {
        update: true,
        complete: true,
        reopen: true,
        delete: true,
        assign: true,
        logTime: true,
    },
    options: {
        priorities: [
            { value: 'low', label: 'Low' },
            { value: 'high', label: 'High' },
        ],
        statuses: [
            { value: 'todo', label: 'To Do' },
            { value: 'in_progress', label: 'In Progress' },
            { value: 'done', label: 'Done' },
        ],
        assignees: { self: { id: 3, name: 'Dana Webb' } },
    },
    timeSummary: { scope: 'own', totalMinutes: 45, entries: [] },
};

function renderPage(overrides: Partial<TasksShowProps> = {}) {
    return render(
        <TimerProvider enabled={false}>
            <TasksShowPage {...props} {...overrides} />
        </TimerProvider>,
    );
}

it('is the shared Direction D detail grammar for a standalone task', () => {
    const { container } = renderPage();

    expect(container.querySelector('[data-page-frame="grid"]')).not.toBeNull();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Pack the van' })).toBeInTheDocument();
    expect(container.querySelector('[data-strata]')).not.toBeNull();
    expect(screen.getByRole('complementary', { name: 'Task details' })).toBeInTheDocument();
    expect(screen.getByText('In Progress')).toBeInTheDocument();
});

it('draws no breadcrumb or navigation landmark of its own (the shell owns the trail)', () => {
    renderPage();

    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
});

it('has only the sections a standalone task has: no project, milestone, column, checklist or comments', () => {
    renderPage();

    expect(screen.getByRole('heading', { level: 2, name: 'Description' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Details' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2, name: 'Time' })).toBeInTheDocument();
    for (const absent of ['Checklist', 'Comments']) {
        expect(screen.queryByRole('heading', { name: absent })).not.toBeInTheDocument();
    }
    for (const absent of ['Milestone', 'Column', 'Project']) {
        expect(screen.queryByText(absent)).not.toBeInTheDocument();
    }
});

it('renders the description with its line breaks, or an empty note', () => {
    const { rerender } = renderPage();
    expect(
        screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === 'Line one\nLine two'),
    ).toBeInTheDocument();

    rerender(
        <TimerProvider enabled={false}>
            <TasksShowPage {...props} task={{ ...task, description: null }} />
        </TimerProvider>,
    );
    expect(screen.getByText('No description.')).toBeInTheDocument();
});

it("shows the contextual time panel with the viewer's time and a start control when eligible", () => {
    renderPage();

    const time = screen.getByRole('heading', { level: 2, name: 'Time' }).closest('section')!;
    expect(within(time).getByText('Your time logged')).toBeInTheDocument();
    expect(within(time).getByRole('button', { name: 'Start timer' })).toBeInTheDocument();
});

it('offers no start control when the server says the viewer is not eligible (P5)', () => {
    renderPage({ abilities: { ...props.abilities, logTime: false } });

    const time = screen.getByRole('heading', { level: 2, name: 'Time' }).closest('section')!;
    expect(within(time).queryByRole('button', { name: 'Start timer' })).not.toBeInTheDocument();
    expect(within(time).getByText('Your time logged')).toBeInTheDocument();
});

it('offers Complete task for an open task and Reopen task for a done one, from the abilities', () => {
    const { rerender } = renderPage();
    expect(screen.getByRole('button', { name: 'Complete task' })).toBeInTheDocument();

    rerender(
        <TimerProvider enabled={false}>
            <TasksShowPage
                {...props}
                task={{
                    ...task,
                    status: { label: 'Done', done: true, source: 'status' },
                    statusValue: 'done',
                }}
            />
        </TimerProvider>,
    );
    expect(screen.getByRole('button', { name: 'Reopen task' })).toBeInTheDocument();
});

it('shows a configuration-style refusal accessibly and clears it on the next success', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByRole('button', { name: 'Complete task' }));
    const options = inertiaSpies.router.put.mock.calls[0]![2] as {
        onError: (errors: Record<string, string>) => void;
        onSuccess: () => void;
    };
    act(() => options.onError({ complete: 'Nope, not now.' }));

    expect(screen.getByRole('alert')).toHaveTextContent('Nope, not now.');

    act(() => options.onSuccess());
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('hides every control the abilities refuse', () => {
    renderPage({
        abilities: {
            update: false,
            complete: false,
            reopen: false,
            delete: false,
            assign: false,
            logTime: false,
        },
        options: null,
    });

    for (const name of ['Complete task', 'Reopen task', 'Edit task', 'Delete task']) {
        expect(screen.queryByRole('button', { name })).not.toBeInTheDocument();
    }
    expect(screen.queryByRole('button', { name: /Change assignee/ })).not.toBeInTheDocument();
    // The assignee is still readable.
    expect(screen.getByText('Dana Webb')).toBeInTheDocument();
});

it('assigns and releases through the narrow endpoint, offering only Me and Unassigned', async () => {
    const user = userEvent.setup();
    renderPage({ task: { ...task, assignee: null } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    const items = await screen.findAllByRole('menuitemradio');
    expect(items.map((item) => item.textContent)).toEqual(['Unassigned', 'Me']);

    items[1]!.focus();
    await user.keyboard('{Enter}');
    expect(inertiaSpies.router.put).toHaveBeenCalledWith(
        '/tasks/12/assignee',
        { assignee_id: 3 },
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('releases a held task by choosing Unassigned', async () => {
    const user = userEvent.setup();
    renderPage();

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    await chooseRadio(user, 'Unassigned');

    expect(inertiaSpies.router.put.mock.calls[0]).toEqual([
        '/tasks/12/assignee',
        { assignee_id: null },
        expect.any(Object),
    ]);
});

it('shows an assignment refusal in a live alert', async () => {
    const user = userEvent.setup();
    renderPage({ task: { ...task, assignee: null } });

    await openMenu(user, screen.getByRole('button', { name: /Change assignee/ }));
    await chooseRadio(user, 'Me');
    const options = inertiaSpies.router.put.mock.calls[0]![2] as {
        onError: (errors: Record<string, string>) => void;
    };
    act(() => options.onError({ assignee_id: 'The selected assignee is invalid.' }));

    expect(screen.getByRole('alert')).toHaveTextContent('The selected assignee is invalid.');
});

it('opens the edit dialog from the header and returns focus to its opener', async () => {
    const user = userEvent.setup();
    renderPage();

    const opener = screen.getByRole('button', { name: 'Edit task' });
    await user.click(opener);
    expect(screen.getByRole('dialog', { name: 'Edit task' })).toBeVisible();

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(opener).toHaveFocus();
});

it('confirms before deleting through tasks.destroy and shows the recorded-time refusal in place', async () => {
    const user = userEvent.setup();
    setFormErrors({ delete: 'This task has recorded time and cannot be deleted.' });
    renderPage();

    await user.click(screen.getByRole('button', { name: 'Delete task' }));
    const dialog = screen.getByRole('dialog', { name: 'Delete this task?' });
    expect(submitted()).toHaveLength(0);
    expect(within(dialog).getByRole('alert')).toHaveTextContent('recorded time');

    await user.click(within(dialog).getByRole('button', { name: 'Delete task' }));
    expect(submitted()[0]).toMatchObject({ method: 'delete', url: '/tasks/12' });
});

describe('the shell trail (A13.12)', () => {
    function renderInShell() {
        setPageProps({
            auth: { user: shellUser, permissions: [] },
            shell: { presentation: 'operational' },
            navigation: navigation([tasks], 'tasks'),
            ...props,
        });
        const layout = TasksShowPage.layout as (page: ReactElement) => ReactElement;

        return render(layout(<TasksShowPage {...props} />));
    }

    it("puts the task as the last segment of the shell's ONE breadcrumb, never in the page body", () => {
        renderInShell();

        const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
        expect(crumbs).toHaveLength(1);
        expect(within(crumbs[0]!).getByRole('link', { name: 'Tasks' })).toBeInTheDocument();
        expect(within(crumbs[0]!).getByText('Pack the van')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(within(screen.getByRole('main')).queryByRole('navigation')).not.toBeInTheDocument();
    });

    it('survives Inertia 3 probing the layout function with the raw props object', () => {
        const layout = TasksShowPage.layout as (page: unknown) => ReactElement;

        expect(() => layout({ task })).not.toThrow();
    });
});
