import { render, screen } from '@testing-library/react';

import { ProjectBoardPage, type ProjectBoardProps } from '@/pages/projects/board';
import { resetInertiaMock, setPageProps } from '@/test/inertia';
import type { BoardColumn } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

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

it('always links to Milestones', () => {
    renderPage();

    expect(screen.getByRole('link', { name: 'Milestones' })).toHaveAttribute(
        'href',
        '/projects/7/milestones',
    );
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
    expect(screen.queryByRole('link', { name: 'Projects' })).not.toBeInTheDocument();
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
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
