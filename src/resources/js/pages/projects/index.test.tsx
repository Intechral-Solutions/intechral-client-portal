import { render, screen, within } from '@testing-library/react';

import { ProjectsIndexPage, type ProjectsIndexProps } from '@/pages/projects/index';
import { resetInertiaMock } from '@/test/inertia';
import type { ProjectCardData } from '@/types/projects';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const card = (overrides: Partial<ProjectCardData> = {}): ProjectCardData => ({
    id: 5,
    name: 'Portal rebuild',
    description: 'Move the portal to React.',
    status: 'active',
    targetDate: '2026-12-31',
    completion: 40,
    overdueCount: 0,
    memberCount: 3,
    ...overrides,
});

function paginated(data: ProjectCardData[], overrides = {}): ProjectsIndexProps['projects'] {
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

function renderIndex(props: Partial<ProjectsIndexProps> = {}) {
    return render(
        <ProjectsIndexPage
            projects={paginated([card()])}
            abilities={{ create: false }}
            {...props}
        />,
    );
}

it('renders one card per project with status, progress, members and a date-only due date', () => {
    renderIndex({
        projects: paginated([
            card(),
            card({
                id: 6,
                name: 'Solo',
                description: null,
                status: 'on_hold',
                memberCount: 1,
                targetDate: null,
            }),
        ]),
    });

    const first = screen.getByRole('article', { name: /Portal rebuild/ });
    expect(within(first).getByText('Move the portal to React.')).toBeInTheDocument();
    expect(within(first).getByText('Active')).toBeInTheDocument();
    expect(within(first).getByText('3 members')).toBeInTheDocument();
    // Not shifted by the browser's timezone: 2026-12-31 stays the 31st.
    expect(within(first).getByText(/Due Dec 31, 2026/)).toBeInTheDocument();
    expect(
        within(first).getByRole('progressbar', { name: 'Portal rebuild completion' }),
    ).toHaveAttribute('aria-valuenow', '40');

    const second = screen.getByRole('article', { name: /Solo/ });
    expect(within(second).getByText('On hold')).toBeInTheDocument();
    expect(within(second).getByText('1 member')).toBeInTheDocument();
    expect(within(second).queryByText(/Due/)).not.toBeInTheDocument();
});

it('shows the overdue count instead of the due date when tasks are overdue', () => {
    renderIndex({ projects: paginated([card({ overdueCount: 2 })]) });

    expect(screen.getByText('2 overdue')).toBeInTheDocument();
    expect(screen.queryByText(/Due Dec/)).not.toBeInTheDocument();
});

it('links each card, by its title, to the project Overview (EPIC-015 Q5)', () => {
    renderIndex();

    // Opening a project is a generic project link: it targets projects.show, not the board.
    const link = screen.getByRole('link', { name: 'Portal rebuild' });
    expect(link).toHaveAttribute('href', '/projects/5');
});

it('offers New project only to actors the create route admits', () => {
    const { unmount } = renderIndex({ abilities: { create: true } });
    expect(screen.getByRole('link', { name: 'New project' })).toHaveAttribute(
        'href',
        '/projects/create',
    );
    unmount();

    renderIndex({ abilities: { create: false } });
    expect(screen.queryByRole('link', { name: 'New project' })).not.toBeInTheDocument();
});

it('shows an empty state, with a create link only when allowed', () => {
    const { unmount } = renderIndex({ projects: paginated([]), abilities: { create: true } });
    expect(screen.getByText('No projects found.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Create your first project' })).toHaveAttribute(
        'href',
        '/projects/create',
    );
    unmount();

    renderIndex({ projects: paginated([]), abilities: { create: false } });
    expect(screen.getByText('No projects found.')).toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: /Create your first project/ }),
    ).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'New project' })).not.toBeInTheDocument();
});

it('paginates only when there is more than one page and keeps the server URLs', () => {
    const { unmount } = renderIndex();
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();
    unmount();

    renderIndex({
        projects: paginated([card()], {
            current_page: 2,
            last_page: 3,
            prev_page_url: '/projects?page=1',
            next_page_url: '/projects?page=3',
        }),
    });

    expect(screen.getByRole('link', { name: 'Previous' })).toHaveAttribute(
        'href',
        '/projects?page=1',
    );
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute('href', '/projects?page=3');
    expect(screen.getByText('Page 2 of 3')).toBeInTheDocument();
});

it('renders project names and descriptions as text, never as markup', () => {
    renderIndex({
        projects: paginated([
            card({ name: '<img src=x onerror=alert(1)>', description: '<b>bold</b>' }),
        ]),
    });

    expect(document.querySelector('img')).toBeNull();
    expect(document.querySelector('b')).toBeNull();
    expect(screen.getByText('<b>bold</b>')).toBeInTheDocument();
});
