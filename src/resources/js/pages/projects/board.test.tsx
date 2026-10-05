import { render, screen, within } from '@testing-library/react';

import { navigation, projects, shellUser } from '@/components/shell/shell-fixtures';

import { ProjectBoardPage, type ProjectBoardProps } from '@/pages/projects/board';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { BoardColumn } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

const project = { id: 7, name: 'Portal rebuild', status: 'active' as const };

const columns: BoardColumn[] = [
    { id: 1, name: 'To Do', isDone: false, tasks: [] },
    { id: 2, name: 'Done', isDone: true, tasks: [] },
];

function renderPage(props: Partial<ProjectBoardProps> = {}) {
    return render(
        <ProjectBoardPage
            project={project}
            columns={columns}
            abilities={{ manage: true, openSettings: true }}
            {...props}
        />,
    );
}

it('renders the project name, status, and board region', () => {
    renderPage();

    expect(screen.getByRole('heading', { name: 'Portal rebuild' })).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Kanban board' })).toBeInTheDocument();
});

// FLIPPED IN EPIC-015 WP4: Milestones was a header button; it is now the project tab (§14.1), in the
// shared project navigation with Board current, and there is no separate Milestones button. (The
// fixture has no time scope, so the strip shows the four required tabs, not Time; EPIC-015 WP5.)
it('carries the shared project navigation, Board current, with Milestones as a tab', () => {
    renderPage();

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
    expect(within(nav).getByRole('link', { name: 'Board' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    expect(screen.getAllByRole('link', { name: 'Milestones' })).toHaveLength(1);
    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByRole('tab')).not.toBeInTheDocument();
});

it('names the page section Board for assistive technology, under the one h1', () => {
    renderPage();

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 2, name: 'Board' })).toHaveClass('sr-only');
});

it('shows the Settings link only when abilities.openSettings is true', () => {
    const { unmount } = renderPage({ abilities: { manage: true, openSettings: true } });
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
        'href',
        '/projects/7/edit',
    );
    unmount();

    // A9: a projects.admin holder without projects.manage passes abilities.manage but must not
    // be offered a Settings link that would 403 (EPIC-011E §7, Amendment 4 W6).
    renderPage({ abilities: { manage: true, openSettings: false } });
    expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
});

it('draws no trail of its own, because the shell owns the breadcrumb', () => {
    renderPage();

    // EPIC-013 WP7: this page used to hand-build a `Projects /` trail beside its title. The utility
    // bar has owned the breadcrumb since WP4 and already offers Projects as a link to the index, so
    // the page's copy was a second source of truth and a duplicate landmark. The back-navigation it
    // provided is not lost — it moved to the shell, where `board-migration.spec.ts` asserts there is
    // exactly one of it. This inverts the old assertion rather than dropping it, which is the
    // clearest record of the boundary change.
    // EPIC-015 WP4: the page's only navigation is now the project tabs; still no breadcrumb.
    expect(screen.queryByRole('link', { name: 'Projects' })).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('navigation').map((nav) => nav.getAttribute('aria-label'))).toEqual([
        'Project',
    ]);
});

it('states the project as an entity, with the strata motif §17 allows here', () => {
    const { container } = renderPage();

    // A project workspace is one of the four surfaces Direction D §17 permits the motif on, and the
    // entity header is the only thing that draws it.
    expect(screen.getByRole('heading', { level: 1, name: 'Portal rebuild' })).toBeInTheDocument();
    expect(container.querySelector('[data-strata]')).toBeInTheDocument();
    expect(container.querySelector('[data-page-frame]')).toHaveAttribute(
        'data-page-frame',
        'canvas',
    );
});

it("names the project, linking to its Overview, then Board, in the shell's one breadcrumb (EPIC-015 §11.3)", () => {
    const props: ProjectBoardProps = {
        project,
        columns,
        abilities: { manage: true, openSettings: true },
    };
    setPageProps({
        auth: { user: shellUser, permissions: [] },
        shell: { presentation: 'operational' },
        navigation: navigation([projects], 'projects'),
        ...props,
    });
    const layout = ProjectBoardPage.layout as (page: React.ReactElement) => React.ReactElement;

    render(layout(<ProjectBoardPage {...props} />));

    const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
    expect(crumbs).toHaveLength(1);
    expect(within(crumbs[0]!).getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
        'href',
        '/projects/7',
    );
    expect(within(crumbs[0]!).getByText('Board')).toHaveAttribute('aria-current', 'page');
});

it('survives Inertia 3 probing the layout function with the raw props object', () => {
    const layout = ProjectBoardPage.layout as (page: unknown) => React.ReactElement;

    expect(() => layout({ project: { id: 7 } })).not.toThrow();
});
