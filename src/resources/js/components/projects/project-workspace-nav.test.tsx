import { render, screen, within } from '@testing-library/react';

import { ProjectWorkspaceNav } from '@/components/projects/project-workspace-nav';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

function links() {
    return within(screen.getByRole('navigation', { name: 'Project' })).getAllByRole('link');
}

it('offers exactly the WP2 destinations that exist: Overview, Board, Milestones', () => {
    render(<ProjectWorkspaceNav projectId={7} current="overview" />);

    expect(links().map((link) => [link.textContent, link.getAttribute('href')])).toEqual([
        ['Overview', '/projects/7'],
        ['Board', '/projects/7/board'],
        ['Milestones', '/projects/7/milestones'],
    ]);
});

it('has no Tasks tab before WP3 adds projects.tasks.index (§11.3.1)', () => {
    render(<ProjectWorkspaceNav projectId={7} current="overview" />);

    expect(screen.queryByRole('link', { name: /tasks/i })).not.toBeInTheDocument();
    expect(links().some((link) => link.getAttribute('href') === '/projects/7/tasks')).toBe(false);
});

it.each([
    ['overview', 'Overview'],
    ['board', 'Board'],
    ['milestones', 'Milestones'],
] as const)('marks %s as the current page', (current, label) => {
    render(<ProjectWorkspaceNav projectId={7} current={current} />);

    expect(links().filter((link) => link.getAttribute('aria-current') === 'page')).toEqual([
        screen.getByRole('link', { name: label }),
    ]);
});
