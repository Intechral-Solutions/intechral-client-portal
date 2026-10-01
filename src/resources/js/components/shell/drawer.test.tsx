import { render, screen, within } from '@testing-library/react';

import { resetInertiaMock } from '@/test/inertia';

import { Drawer } from './drawer';
import { helpdesk, projects, tasks } from './shell-fixtures';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function renderDrawer(workspace = projects, props: Partial<Parameters<typeof Drawer>[0]> = {}) {
    return render(
        <Drawer
            workspace={workspace}
            pinned={false}
            autoFocus={false}
            onClose={vi.fn()}
            onPin={vi.fn()}
            {...props}
        />,
    );
}

it('renders the workspace label, sections and items in server order', () => {
    renderDrawer(helpdesk);

    const nav = screen.getByRole('navigation', { name: 'Helpdesk views' });

    expect(
        within(nav)
            .getAllByRole('link')
            .map((link) => link.textContent),
    ).toEqual(['My requests', 'Queue', 'Reports']);
    // The workspace name and the section label are deliberately not headings: they would duplicate
    // the page's own `<h1>` in the heading outline.
    expect(screen.queryByRole('heading')).toBeNull();
    expect(screen.getByText('Helpdesk')).toBeInTheDocument();
    expect(within(nav).getByRole('group', { name: 'Views' })).toBeInTheDocument();
});

it('marks the active contextual item as the current page, and only that one', () => {
    renderDrawer(tasks);

    expect(screen.getByRole('link', { name: 'My tasks' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'All tasks' })).not.toHaveAttribute('aria-current');
});

it('renders an action as an action, never as selected navigation', () => {
    renderDrawer(projects);

    // The label is the server's own words: the affordance is an icon, not a rewritten name.
    const action = screen.getByRole('link', { name: 'New project' });

    // WP3 never marks an action active (A1.10 requirement 2); the presentation refuses it too, so a
    // regression in either layer cannot put the selected strip on "New project".
    expect(action).not.toHaveAttribute('aria-current');
    expect(screen.getByRole('link', { name: 'All projects' })).toHaveAttribute(
        'aria-current',
        'page',
    );
});

it('projects context without needing presentation hints at all', () => {
    // §23.2: the drawer projects `context`; it does not own it. With `presentation` emptied it must
    // still render every section and item — only the open/collapsed default came from there, and
    // that is the caller's concern.
    renderDrawer({ ...helpdesk, presentation: {} });

    expect(
        within(screen.getByRole('navigation', { name: 'Helpdesk views' })).getAllByRole('link'),
    ).toHaveLength(3);
});

it('renders each contextual destination with its own visit mode', () => {
    renderDrawer(helpdesk);

    for (const name of ['My requests', 'Queue', 'Reports']) {
        expect(screen.getByRole('link', { name })).not.toHaveAttribute('data-inertia-link');
    }

    renderDrawer(tasks);
    expect(screen.getByRole('link', { name: 'My tasks' })).toHaveAttribute('data-inertia-link');
});

it('exposes the collapse control as an expanded disclosure of the panel', () => {
    renderDrawer();

    const collapse = screen.getByRole('button', { name: 'Collapse workspace views' });

    expect(collapse).toHaveAttribute('aria-expanded', 'true');
    expect(collapse).toHaveAttribute('aria-controls', 'shell-drawer');
});

it('offers the pin until it is pinned', () => {
    const { rerender } = renderDrawer();

    expect(screen.getByRole('button', { name: 'Pin workspace views' })).toBeInTheDocument();

    rerender(
        <Drawer workspace={projects} pinned autoFocus={false} onClose={vi.fn()} onPin={vi.fn()} />,
    );

    expect(screen.queryByRole('button', { name: 'Pin workspace views' })).toBeNull();
});

it('moves focus to the current item when the user opened it, and not otherwise', () => {
    renderDrawer(tasks, { autoFocus: true });

    expect(screen.getByRole('link', { name: 'My tasks' })).toHaveFocus();
});

it('renders no scrim of its own', () => {
    const { container } = renderDrawer();

    // Direction D §5.4: the overlay drawer has no scrim, so content stays visible behind it.
    expect(container.querySelector('.bg-scrim')).toBeNull();
});

it('renders no count slot while counts are reserved', () => {
    renderDrawer(helpdesk);

    // §12.3 rule 8: `count` is always null in this epic, so no count element may appear.
    expect(screen.getByRole('link', { name: 'Queue' }).querySelector('.font-mono')).toBeNull();
});
