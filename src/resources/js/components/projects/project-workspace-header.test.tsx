import { render, screen, within } from '@testing-library/react';

import { ProjectWorkspaceHeader } from '@/components/projects/project-workspace-header';

/**
 * EPIC-015 WP4 — the one project header shared by Overview, Board, Tasks and Milestones, so the four
 * pages cannot drift apart.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

const project = { id: 7, name: 'Portal rebuild', status: 'on_hold' as const };

it('states the project as the one h1, with its lifecycle status and the "Project" overline', () => {
    const { container } = render(<ProjectWorkspaceHeader project={project} current="board" />);

    expect(screen.getAllByRole('heading', { level: 1 }).map((h) => h.textContent)).toEqual([
        'Portal rebuild',
    ]);
    expect(screen.getByText('Project')).toBeInTheDocument();
    expect(screen.getByText('On hold')).toBeInTheDocument();
    expect(container.querySelector('[data-strata]')).toBeInTheDocument();
});

it.each(['overview', 'board', 'tasks', 'milestones'] as const)(
    'puts the four project tabs on the strata with %s current, as page links',
    (current) => {
        render(<ProjectWorkspaceHeader project={project} current={current} />);

        const nav = screen.getByRole('navigation', { name: 'Project' });
        expect(within(nav).getAllByRole('link')).toHaveLength(4);
        expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1);
        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    },
);

it('offers Settings as a header action only when the server allows it, never as a tab', () => {
    const { unmount } = render(<ProjectWorkspaceHeader project={project} current="tasks" />);
    expect(screen.queryByRole('link', { name: 'Settings' })).not.toBeInTheDocument();
    unmount();

    render(<ProjectWorkspaceHeader project={project} current="tasks" openSettings />);
    expect(screen.getByRole('link', { name: 'Settings' })).toHaveAttribute(
        'href',
        '/projects/7/edit',
    );
    expect(
        within(screen.getByRole('navigation', { name: 'Project' })).queryByRole('link', {
            name: 'Settings',
        }),
    ).not.toBeInTheDocument();
});

it('shows key facts beside the status when given', () => {
    render(
        <ProjectWorkspaceHeader
            project={project}
            current="overview"
            meta={<span>Target Dec 31, 2026</span>}
        />,
    );

    expect(screen.getByText('Target Dec 31, 2026')).toBeInTheDocument();
});
