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

it('links back to the projects index', () => {
    renderPage();

    expect(screen.getByRole('link', { name: 'Projects' })).toHaveAttribute('href', '/projects');
});
