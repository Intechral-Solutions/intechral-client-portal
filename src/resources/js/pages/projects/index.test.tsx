import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { navigation, projects as projectsView, shellUser } from '@/components/shell/shell-fixtures';
import { ProjectsIndexPage, type ProjectsIndexProps } from '@/pages/projects/index';
import { inertiaSpies, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { ProjectIndexRow } from '@/types/projects';

/**
 * EPIC-015 WP4 — the projects index (§11.1, §14.1). FLIPPED IN EPIC-015 WP4: this file pinned the
 * EPIC-011E card grid (one card per project with a description, a completion bar and an overdue count);
 * the index is now a Direction D `DataTable` of the §11.1 row, with lifecycle and health as separate
 * columns, and a lifecycle-status filter that defaults to every status.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

const statuses: ProjectsIndexProps['filterOptions']['statuses'] = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'archived', label: 'Archived' },
];

const row = (overrides: Partial<ProjectIndexRow> = {}): ProjectIndexRow => ({
    id: 5,
    name: 'Portal rebuild',
    status: 'active',
    health: {
        state: 'off_track',
        label: 'Off track',
        reasons: [
            { code: 'milestones_overdue', count: 2, earliest: null },
            { code: 'tasks_overdue', count: 1 },
        ],
    },
    tasks: { total: 5, done: 2, completion: 40 },
    targetDate: '2026-12-31',
    nextMilestone: { id: 31, name: 'Go-live', dueDate: '2026-11-02' },
    memberCount: 3,
    ...overrides,
});

function paginated(data: ProjectIndexRow[], overrides = {}): ProjectsIndexProps['projects'] {
    return {
        data,
        current_page: 1,
        last_page: 1,
        from: data.length ? 1 : null,
        to: data.length || null,
        total: data.length,
        links: [],
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    };
}

const baseProps: ProjectsIndexProps = {
    projects: paginated([row()]),
    filters: { status: null },
    filterOptions: { statuses },
    hasProjects: true,
    abilities: { create: false },
};

function renderIndex(props: Partial<ProjectsIndexProps> = {}) {
    return render(<ProjectsIndexPage {...baseProps} {...props} />);
}

function rowOf(name: string) {
    return screen.getByRole('row', { name: new RegExp(name) });
}

it('is a canvas page with one h1, the Projects header and a named table', () => {
    const { container } = renderIndex();

    expect(container.querySelector('[data-page-frame]')).toHaveAttribute(
        'data-page-frame',
        'canvas',
    );
    expect(screen.getAllByRole('heading', { level: 1 }).map((h) => h.textContent)).toEqual([
        'Projects',
    ]);
    expect(screen.getByRole('table', { name: 'Projects' })).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
        'Project',
        'Status',
        'Health',
        'Tasks',
        'Target',
        'Next milestone',
        'Members',
    ]);
});

it('shows lifecycle and derived health as separate facts, health in text with count-only reasons', () => {
    renderIndex();
    const cells = within(rowOf('Portal rebuild')).getAllByRole('cell');

    expect(cells[1]).toHaveTextContent('Active');
    expect(cells[2]).toHaveTextContent('Off track');
    expect(cells[2]).not.toHaveTextContent('Active');
    // Index reasons are count-only: no milestone is named, and that is not missing data.
    expect(cells[2]).toHaveTextContent('2 milestones overdue · 1 task overdue');
});

it('says a project on hold has no derived health, rather than inventing one', () => {
    renderIndex({ projects: paginated([row({ status: 'on_hold', health: null })]) });
    const cells = within(rowOf('Portal rebuild')).getAllByRole('cell');

    expect(cells[1]).toHaveTextContent('On hold');
    expect(cells[2]).toHaveTextContent('No health while not active');
    expect(cells[2]).not.toHaveTextContent(/track|risk/i);
});

it('words task progress as tasks and names the bar, never a bare percentage', () => {
    renderIndex();

    expect(within(rowOf('Portal rebuild')).getByText('2 of 5 tasks done')).toBeInTheDocument();
    const bar = screen.getByRole('progressbar', { name: 'Portal rebuild task progress' });
    expect(bar).toHaveAttribute('aria-valuenow', '2');
    expect(bar).toHaveAttribute('aria-valuemax', '5');
    expect(bar).toHaveAttribute('aria-valuetext', '2 of 5 tasks done, 40%');

    renderIndex({
        projects: paginated([
            row({ id: 6, name: 'Empty one', tasks: { total: 0, done: 0, completion: 0 } }),
        ]),
    });
    expect(within(rowOf('Empty one')).getByText('No tasks')).toBeInTheDocument();
});

it('shows the target date, the next milestone and the member count, or says each is absent', () => {
    renderIndex({
        projects: paginated([
            row(),
            row({ id: 6, name: 'Bare', targetDate: null, nextMilestone: null, memberCount: 1 }),
        ]),
    });

    const full = rowOf('Portal rebuild');
    expect(within(full).getByText('Go-live')).toBeInTheDocument();
    expect(within(full).getByText('3 members')).toBeInTheDocument();
    expect(within(full).getAllByRole('cell')[4]?.querySelector('time')).toHaveAttribute(
        'dateTime',
        '2026-12-31',
    );

    const bare = rowOf('Bare');
    expect(within(bare).getByText('No target date')).toBeInTheDocument();
    expect(within(bare).getByText('No upcoming milestone')).toBeInTheDocument();
    expect(within(bare).getByText('1 member')).toBeInTheDocument();
});

it('opens each project at its Overview, never the Board (EPIC-015 Q5)', () => {
    renderIndex();

    expect(screen.getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
        'href',
        '/projects/5',
    );
    expect(document.querySelector('a[href$="/board"]')).toBeNull();
});

it('offers New project only to actors the create route admits', () => {
    const { unmount } = renderIndex();
    expect(screen.queryByRole('link', { name: 'New project' })).not.toBeInTheDocument();
    unmount();

    renderIndex({ abilities: { create: true } });
    expect(screen.getByRole('link', { name: 'New project' })).toHaveAttribute(
        'href',
        '/projects/create',
    );
});

it('filters by lifecycle status in the URL, every status by default (P5)', async () => {
    const user = userEvent.setup();
    renderIndex();

    const select = screen.getByRole('combobox', { name: 'Status' });
    expect(select).toHaveValue('');
    expect(screen.getByRole('option', { name: 'All statuses' })).toBeInTheDocument();

    await user.selectOptions(select, 'on_hold');
    expect(inertiaSpies.router.get).toHaveBeenCalledWith(
        '/projects',
        { status: 'on_hold' },
        { preserveState: true, preserveScroll: true },
    );
});

it('shows the active status as a removable chip that returns to every status', async () => {
    const user = userEvent.setup();
    renderIndex({ filters: { status: 'archived' } });

    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveValue('archived');
    await user.click(screen.getByRole('button', { name: /Status: Archived/ }));
    expect(inertiaSpies.router.get).toHaveBeenLastCalledWith(
        '/projects',
        {},
        { preserveState: true, preserveScroll: true },
    );
});

it('tells "no projects yet" from "none with this status"', async () => {
    const user = userEvent.setup();
    const { unmount } = renderIndex({
        projects: paginated([]),
        hasProjects: false,
        abilities: { create: true },
    });
    expect(screen.getByText('No projects yet')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'New project' })).toHaveLength(1);
    expect(screen.getByRole('link', { name: 'Create your first project' })).toHaveAttribute(
        'href',
        '/projects/create',
    );
    unmount();

    renderIndex({ projects: paginated([]), filters: { status: 'archived' }, hasProjects: true });
    expect(screen.getByText('No projects with this status')).toBeInTheDocument();
    expect(screen.queryByText('No projects yet')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Show all projects' }));
    expect(inertiaSpies.router.get).toHaveBeenLastCalledWith(
        '/projects',
        {},
        { preserveState: true, preserveScroll: true },
    );
});

it('offers no create action in an empty index to an actor who may not create', () => {
    renderIndex({ projects: paginated([]), hasProjects: false });

    expect(screen.getByText('No projects yet')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New project' })).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Create your first project' }),
    ).not.toBeInTheDocument();
});

it('paginates only when there is more than one page and keeps the server URLs', () => {
    const { unmount } = renderIndex();
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
    unmount();

    renderIndex({
        projects: paginated([row()], {
            current_page: 2,
            last_page: 3,
            prev_page_url: '/projects?status=active&page=1',
            next_page_url: '/projects?status=active&page=3',
        }),
    });

    expect(screen.getByRole('link', { name: 'Previous' })).toHaveAttribute(
        'href',
        '/projects?status=active&page=1',
    );
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/projects?status=active&page=3',
    );
});

it('renders project and milestone names as text, never as markup', () => {
    renderIndex({
        projects: paginated([
            row({
                name: '<img src=x onerror=alert(1)>',
                nextMilestone: { id: 1, name: '<b>bold</b>', dueDate: '2026-11-02' },
            }),
        ]),
    });

    expect(document.querySelector('img')).toBeNull();
    expect(document.querySelector('b')).toBeNull();
    expect(screen.getByText('<b>bold</b>')).toBeInTheDocument();
});

it("uses the shell's one breadcrumb and draws none of its own", () => {
    setPageProps({
        auth: { user: shellUser, permissions: [] },
        shell: { presentation: 'operational' },
        navigation: navigation([projectsView], 'projects'),
    });
    const layout = ProjectsIndexPage.layout as (page: React.ReactElement) => React.ReactElement;

    render(layout(<ProjectsIndexPage {...baseProps} />));

    expect(screen.getAllByRole('navigation', { name: 'Breadcrumb' })).toHaveLength(1);
    expect(screen.getByRole('main').querySelector('nav[aria-label="Breadcrumb"]')).toBeNull();
});
