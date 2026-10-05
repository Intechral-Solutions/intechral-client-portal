import { render, screen, within } from '@testing-library/react';

import { ProjectWorkspaceNav } from '@/components/projects/project-workspace-nav';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

function links() {
    return within(screen.getByRole('navigation', { name: 'Project' })).getAllByRole('link');
}

// FLIPPED IN EPIC-015 WP3: WP2 pinned Overview · Board · Milestones and no Tasks tab (§11.3.1). WP3
// adds projects.tasks.index, so the strip is the final Overview · Board · Tasks · Milestones.
it('offers the final four destinations in order: Overview, Board, Tasks, Milestones', () => {
    render(<ProjectWorkspaceNav projectId={7} current="overview" />);

    expect(links().map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
        ['Overview', '/projects/7'],
        ['Board', '/projects/7/board'],
        ['Tasks', '/projects/7/tasks'],
        ['Milestones', '/projects/7/milestones'],
    ]);
});

it('is page navigation, not an ARIA tab widget', () => {
    render(<ProjectWorkspaceNav projectId={7} current="tasks" />);

    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByRole('tab')).not.toBeInTheDocument();
    expect(links().every((link) => !link.hasAttribute('aria-selected'))).toBe(true);
});

it.each([
    ['overview', 'Overview'],
    ['board', 'Board'],
    ['tasks', 'Tasks'],
    ['milestones', 'Milestones'],
] as const)('marks %s as the current page', (current, label) => {
    render(<ProjectWorkspaceNav projectId={7} current={current} />);

    expect(links().filter((link) => link.getAttribute('aria-current') === 'page')).toEqual([
        screen.getByRole('link', { name: label }),
    ]);
});

// ── EPIC-015 WP5 (optional S3): the Time tab ─────────────────────────────────

it('adds Time last, only when the server offers it', () => {
    render(<ProjectWorkspaceNav projectId={7} current="overview" tabs={{ time: true }} />);

    expect(links().map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
        ['Overview', '/projects/7'],
        ['Board', '/projects/7/board'],
        ['Tasks', '/projects/7/tasks'],
        ['Milestones', '/projects/7/milestones'],
        ['Time', '/projects/7/time'],
    ]);
});

it('renders no Time tab when the server says no, or says nothing', () => {
    const { unmount } = render(
        <ProjectWorkspaceNav projectId={7} current="overview" tabs={{ time: false }} />,
    );
    expect(screen.queryByRole('link', { name: 'Time' })).not.toBeInTheDocument();
    expect(links()).toHaveLength(4);
    unmount();

    render(<ProjectWorkspaceNav projectId={7} current="overview" />);
    expect(screen.queryByRole('link', { name: 'Time' })).not.toBeInTheDocument();
});

it('marks Time as the current page on the Time tab, with link semantics only', () => {
    render(<ProjectWorkspaceNav projectId={7} current="time" tabs={{ time: true }} />);

    expect(links().filter((link) => link.getAttribute('aria-current') === 'page')).toEqual([
        screen.getByRole('link', { name: 'Time' }),
    ]);
    expect(screen.queryByRole('tab')).not.toBeInTheDocument();
});
