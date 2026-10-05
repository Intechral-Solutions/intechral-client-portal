import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { emitInertiaNavigate, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Navigation, Workspace } from '@/types';

import { AppShell, type BreadcrumbSegment } from './app-shell';
import {
    helpdesk,
    home,
    navigation,
    projects,
    resources,
    setWidthClass,
    tasks,
} from './shell-fixtures';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
// The pill has its own tests; this file only asserts the shell keeps mounting it and its provider.
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

const user = {
    id: 1,
    name: 'Dana Webb',
    email: 'dana@example.test',
    avatar: { initials: 'DW', url: null },
};

function mount(nav: Navigation, permissions: string[] = [], trail?: BreadcrumbSegment[]) {
    setPageProps({
        auth: { user, permissions },
        shell: { presentation: 'operational' },
        navigation: nav,
    });

    return render(
        <AppShell trail={trail}>
            <h1>Projects</h1>
        </AppShell>,
    );
}

// The panel's default only applies at XL (§5.2); the below-XL rule has its own case.
beforeEach(() => setWidthClass('xl'));
afterEach(resetInertiaMock);

it('renders the Direction D landmarks', () => {
    mount(navigation([home, projects], 'projects'));

    // Direction D §14.1: rail, drawer, utility bar, main.
    expect(screen.getByRole('navigation', { name: 'Workspaces' })).toBeInTheDocument();
    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveAttribute('id', 'main-content');
    expect(screen.getByRole('link', { name: 'Skip to content' })).toBeInTheDocument();
});

it('renders only the workspaces the server authorized, in order', () => {
    mount(navigation([home, projects, tasks, helpdesk, resources], 'projects'));

    const rail = screen.getByRole('navigation', { name: 'Workspaces' });

    expect(
        within(rail)
            .getAllByRole('link')
            .map((link) => link.textContent),
    ).toEqual(['Home', 'Projects', 'Tasks', 'Helpdesk', 'Resources']);
});

it('omits a workspace the server withheld', () => {
    // Capability filtering is server-side and complete before the payload arrives: there is nothing
    // for the shell to hide, so an absent workspace is simply absent from the DOM.
    mount(navigation([home, projects], 'projects'));

    const rail = screen.getByRole('navigation', { name: 'Workspaces' });

    expect(within(rail).queryByRole('link', { name: 'Resources' })).toBeNull();
    expect(within(rail).queryByRole('link', { name: 'Helpdesk' })).toBeNull();
});

it('renders no drawer for a workspace with no contextual navigation', () => {
    mount(navigation([home, projects], 'home'));

    // §12.3 rule 6: `context: []` means no panel for any presentation, and the rail item links
    // straight to the surface.
    expect(screen.queryByRole('navigation', { name: 'Home views' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Show workspace views' })).toBeNull();
    expect(screen.getByRole('main')).toBeInTheDocument();
});

it('opens the drawer by the server default and collapses it by the server default', () => {
    const { unmount } = mount(navigation([projects], 'projects'));

    // Projects defaults to open.
    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
    unmount();

    mount(navigation([tasks], 'tasks'));

    // Tasks defaults to collapsed, so the rail offers the toggle instead.
    expect(screen.queryByRole('navigation', { name: 'Tasks views' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Show workspace views' })).toBeInTheDocument();
});

it('honours a remembered collapse over the server default', () => {
    localStorage.setItem('shell.operational.panel', JSON.stringify({ projects: 'collapsed' }));

    mount(navigation([projects], 'projects'));

    expect(screen.queryByRole('navigation', { name: 'Projects views' })).toBeNull();
});

it('ignores a malformed remembered state and uses the server default', () => {
    localStorage.setItem('shell.operational.panel', 'not json at all');

    mount(navigation([projects], 'projects'));

    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
});

it('toggles the drawer from the rail and remembers the choice', async () => {
    const actor = userEvent.setup();
    mount(navigation([tasks], 'tasks'));

    await actor.click(screen.getByRole('button', { name: 'Show workspace views' }));

    expect(screen.getByRole('navigation', { name: 'Tasks views' })).toBeInTheDocument();
    expect(JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}')).toEqual({
        tasks: 'open',
    });

    await actor.click(screen.getByRole('button', { name: 'Collapse workspace views' }));

    expect(screen.queryByRole('navigation', { name: 'Tasks views' })).toBeNull();
});

/**
 * Dismissal is overlay-only, and the shell asks the CSS whether the panel floats rather than
 * re-deriving breakpoints. jsdom loads no stylesheet, so these tests set the position the width
 * classes would have applied — which also states the dependency out loud.
 */
function setPanelFloating(floating: boolean) {
    const drawer = document.querySelector<HTMLElement>('[data-shell-drawer]');

    if (drawer) {
        drawer.style.position = floating ? 'fixed' : 'sticky';
    }
}

it('closes a floating drawer on Escape and returns focus to the rail toggle', async () => {
    const actor = userEvent.setup();
    mount(navigation([tasks], 'tasks'));

    await actor.click(screen.getByRole('button', { name: 'Show workspace views' }));
    setPanelFloating(true);
    await actor.keyboard('{Escape}');

    expect(screen.queryByRole('navigation', { name: 'Tasks views' })).toBeNull();
    // Direction D §14.2: focus returns to the control that opened it.
    expect(screen.getByRole('button', { name: 'Show workspace views' })).toHaveFocus();
});

it('leaves a docked drawer alone on Escape and on a click into the content', async () => {
    const actor = userEvent.setup();
    mount(navigation([projects], 'projects'));

    setPanelFloating(false);

    // A docked panel is part of the layout. Dismissing it on either gesture would collapse a grid
    // column under the user's cursor, which the browser suite caught as clicks and drags landing in
    // the wrong place after the shift. `Ctrl+\` stays the way to collapse it.
    await actor.keyboard('{Escape}');
    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();

    await actor.click(screen.getByRole('heading', { name: 'Projects' }));
    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
});

it('dismisses a floating drawer on a click into the content', async () => {
    const actor = userEvent.setup();
    mount(navigation([projects], 'projects'));

    setPanelFloating(true);
    await actor.click(screen.getByRole('heading', { name: 'Projects' }));

    expect(screen.queryByRole('navigation', { name: 'Projects views' })).toBeNull();
});

describe('following a link inside the drawer', () => {
    // jsdom does not navigate; stop the default so only the shell's own handling is observed.
    function holdNavigation(event: Event) {
        event.preventDefault();
    }

    beforeEach(() => document.addEventListener('click', holdNavigation, true));
    afterEach(() => document.removeEventListener('click', holdNavigation, true));

    it('dismisses a floating drawer, so it never covers the page it opened (§16)', async () => {
        const actor = userEvent.setup();
        mount(navigation([tasks], 'tasks'));

        await actor.click(screen.getByRole('button', { name: 'Show workspace views' }));
        setPanelFloating(true);
        await actor.click(screen.getByRole('link', { name: 'All tasks' }));

        expect(screen.queryByRole('navigation', { name: 'Tasks views' })).toBeNull();
    });

    it('leaves a docked drawer where it is', async () => {
        const actor = userEvent.setup();
        mount(navigation([projects], 'projects'));

        setPanelFloating(false);
        await actor.click(screen.getByRole('link', { name: 'New project' }));

        expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
    });

    it('ignores a modified click, which opens a new tab and leaves this page in place', async () => {
        const actor = userEvent.setup();
        mount(navigation([tasks], 'tasks'));

        await actor.click(screen.getByRole('button', { name: 'Show workspace views' }));
        setPanelFloating(true);
        await actor.keyboard('{Control>}');
        await actor.click(screen.getByRole('link', { name: 'All tasks' }));
        await actor.keyboard('{/Control}');

        expect(screen.getByRole('navigation', { name: 'Tasks views' })).toBeInTheDocument();
    });
});

it('toggles the drawer with Ctrl+backslash but never while a field has focus', async () => {
    const actor = userEvent.setup();
    mount(navigation([tasks], 'tasks'));

    await actor.keyboard('{Control>}\\{/Control}');
    expect(screen.getByRole('navigation', { name: 'Tasks views' })).toBeInTheDocument();

    // WCAG 2.1.4: a single-key-adjacent shortcut must not swallow typing.
    const field = document.createElement('input');
    document.body.append(field);
    field.focus();
    await actor.keyboard('{Control>}\\{/Control}');

    expect(screen.getByRole('navigation', { name: 'Tasks views' })).toBeInTheDocument();
    field.remove();
});

it('renders the breadcrumb from the server trail, ending with the current page', () => {
    mount(navigation([projects], 'projects'));

    const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });

    expect(crumbs).toHaveTextContent('Projects');
    expect(crumbs).toHaveTextContent('All projects');
    expect(within(crumbs).getByText('All projects')).toHaveAttribute('aria-current', 'page');
});

describe('a page-supplied trail (EPIC-013 §13.2; EPIC-014 WP5, A13.12)', () => {
    const trail: BreadcrumbSegment[] = [
        { label: 'Portal rebuild', href: '/projects/7/board' },
        { label: 'Ship it' },
    ];

    it("extends the one breadcrumb with the page's segments and ends on the current page", () => {
        mount(navigation([projects], 'projects'), [], trail);

        const crumbs = screen.getAllByRole('navigation', { name: 'Breadcrumb' });
        expect(crumbs).toHaveLength(1);

        const [landmark] = crumbs;
        expect(within(landmark!).getByRole('link', { name: 'Projects' })).toHaveAttribute(
            'href',
            '/projects',
        );
        // The view the server marked active stays in the trail, now as a link back to it.
        expect(within(landmark!).getByRole('link', { name: 'All projects' })).toHaveAttribute(
            'href',
            '/projects',
        );
        expect(within(landmark!).getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
            'href',
            '/projects/7/board',
        );
        const current = within(landmark!).getByText('Ship it');
        expect(current).toHaveAttribute('aria-current', 'page');
        expect(
            within(landmark!).getAllByText(/./, { selector: '[aria-current="page"]' }),
        ).toHaveLength(1);
    });

    it('links segments with Inertia visits, never document navigations', () => {
        mount(navigation([projects], 'projects'), [], trail);

        expect(screen.getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
            'data-inertia-link',
            'true',
        );
    });

    it('keeps the view switcher while the drawer is collapsed, so the view stays switchable', async () => {
        mount(navigation([tasks], 'tasks'), [], [{ label: 'Pack the van' }]);

        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });
        expect(
            within(crumbs).getByRole('button', { name: 'Tasks views: My tasks' }),
        ).toBeInTheDocument();
        expect(within(crumbs).getByText('Pack the van')).toHaveAttribute('aria-current', 'page');
    });

    it('renders the long last segment truncated, not wrapping the bar', () => {
        mount(navigation([projects], 'projects'), [], [{ label: 'A'.repeat(300) }]);

        expect(screen.getByText('A'.repeat(300))).toHaveClass('truncate');
    });

    it('keeps only the parent and the current page at S: the workspace and the view hide, with their separators', () => {
        mount(navigation([projects], 'projects'), [], trail);
        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });

        // trail = [project, task]: both leading segments hide at S.
        expect(within(crumbs).getByRole('link', { name: 'Projects' })).toHaveClass('max-md:hidden');
        expect(
            within(crumbs).getByRole('link', { name: 'All projects' }).parentElement,
        ).toHaveClass('max-md:hidden');
        expect(within(crumbs).getByRole('link', { name: 'Portal rebuild' })).not.toHaveClass(
            'max-md:hidden',
        );
        // The first separator that would sit at the front of the shortened trail hides too; later ones stay.
        const separators = crumbs.querySelectorAll('svg');
        expect(separators).toHaveLength(3);
        expect(separators[0]).toHaveClass('max-md:hidden'); // workspace › view
        expect(separators[1]).toHaveClass('max-md:hidden'); // view › project (project is first visible)
        expect(separators[2]).not.toHaveClass('max-md:hidden'); // project › task
    });

    it('keeps the view at S when the trail is only the record (a standalone task), hiding just the workspace', () => {
        mount(navigation([tasks], 'tasks'), [], [{ label: 'Pack the van' }]);
        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });

        expect(within(crumbs).getByRole('link', { name: 'Tasks' })).toHaveClass('max-md:hidden');
        const separators = crumbs.querySelectorAll('svg');
        expect(separators[0]).toHaveClass('max-md:hidden'); // workspace › view
        expect(separators[1]).not.toHaveClass('max-md:hidden'); // view › task
    });

    it('hides nothing at S for a page with no trail', () => {
        mount(navigation([projects], 'projects'));
        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });

        expect(within(crumbs).getByRole('link', { name: 'Projects' })).not.toHaveClass(
            'max-md:hidden',
        );
    });

    it('leaves a page with no trail exactly as before: the workspace and the active view', () => {
        mount(navigation([projects], 'projects'));

        const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });
        expect(within(crumbs).getByText('All projects')).toHaveAttribute('aria-current', 'page');
    });
});

it('moves the current view into a switcher while the drawer is collapsed', async () => {
    const actor = userEvent.setup();
    mount(navigation([tasks], 'tasks'));

    const crumbs = screen.getByRole('navigation', { name: 'Breadcrumb' });
    const switcher = within(crumbs).getByRole('button', { name: 'Tasks views: My tasks' });

    await actor.tab();
    await actor.click(switcher);

    // A real menu, so Radix's arrow keys are correct here and L11 is not contradicted.
    for (const name of ['My tasks', 'All tasks']) {
        expect(await screen.findByRole('menuitem', { name })).toBeInTheDocument();
    }
});

it('exposes workspaces and the current views in the narrow-width nav sheet', async () => {
    const actor = userEvent.setup();
    mount(navigation([home, projects, helpdesk], 'projects'));

    await actor.click(screen.getByRole('button', { name: 'Open navigation' }));

    const sheet = await screen.findByRole('dialog');

    // At S the rail's own list and the drawer are removed from the tree by CSS, so this is the
    // single set of workspace links at that width.
    expect(within(sheet).getByRole('link', { name: 'Home' })).toBeInTheDocument();
    expect(within(sheet).getByRole('link', { name: 'Helpdesk' })).toHaveAttribute(
        'href',
        '/tickets',
    );
    expect(within(sheet).getByRole('link', { name: 'All projects' })).toBeInTheDocument();
    expect(within(sheet).getByRole('link', { name: 'Projects' })).toHaveAttribute(
        'aria-current',
        'page',
    );
});

it('keeps document destinations out of the Inertia visit path everywhere it renders them', async () => {
    const actor = userEvent.setup();
    mount(navigation([home, helpdesk], 'helpdesk'));

    const rail = screen.getByRole('navigation', { name: 'Workspaces' });
    const drawer = screen.getByRole('navigation', { name: 'Helpdesk views' });

    expect(within(rail).getByRole('link', { name: 'Helpdesk' })).not.toHaveAttribute(
        'data-inertia-link',
    );
    for (const name of ['My requests', 'Queue', 'Reports']) {
        expect(within(drawer).getByRole('link', { name })).not.toHaveAttribute('data-inertia-link');
    }

    // Home is Inertia in the same payload, so the distinction is per destination, not per shell.
    expect(within(rail).getByRole('link', { name: 'Home' })).toHaveAttribute('data-inertia-link');

    await actor.click(screen.getByRole('button', { name: 'Open navigation' }));
    const sheet = await screen.findByRole('dialog');

    expect(within(sheet).getByRole('link', { name: 'Queue' })).not.toHaveAttribute(
        'data-inertia-link',
    );
});

it.each([
    [
        'operator ticket reports',
        {
            ...helpdesk,
            isActive: true,
            context: [
                {
                    ...helpdesk.context[0]!,
                    items: helpdesk.context[0]!.items.map((item) => ({
                        ...item,
                        isActive: item.key === 'helpdesk.reports',
                    })),
                },
            ],
        },
        'Reports',
    ],
    [
        'the tasks all view',
        {
            ...tasks,
            isActive: true,
            presentation: { operational: { panel: 'open' as const } },
            context: [
                {
                    ...tasks.context[0]!,
                    items: tasks.context[0]!.items.map((item) => ({
                        ...item,
                        isActive: item.key === 'tasks.all',
                    })),
                },
            ],
        },
        'All tasks',
    ],
])('renders only the canonical active item on the %s route', (_label, workspace, expected) => {
    mount(navigation([workspace as Workspace], (workspace as Workspace).key));

    const drawer = screen.getByRole('navigation', {
        name: `${(workspace as Workspace).label} views`,
    });
    const current = within(drawer)
        .getAllByRole('link')
        .filter((link) => link.getAttribute('aria-current') === 'page');

    // WP3 solved these collisions server-side; the shell must render the single result rather than
    // reintroduce ambiguity (A1.10).
    expect(current).toHaveLength(1);
    expect(current[0]).toHaveAccessibleName(expected);
});

it('announces each page change politely and repairs focus only when a visit destroyed it', () => {
    mount(navigation([projects], 'projects'));

    const region = screen.getByRole('main').parentElement?.querySelector('[aria-live="polite"]');

    expect(region).toBeInTheDocument();

    // The FIRST navigate is the initial page render: nothing was destroyed, focus is at the start of
    // the document, and stealing it would swallow the skip link on the user's first Tab. Chromium
    // caught this when jsdom did not.
    (document.activeElement as HTMLElement | null)?.blur();
    emitInertiaNavigate();

    expect(screen.getByRole('main')).not.toHaveFocus();

    // A later visit that destroyed focus (an in-page control inside the replaced subtree) is
    // repaired.
    emitInertiaNavigate();

    expect(screen.getByRole('main')).toHaveFocus();

    // Focus on a live control (the rail link just activated): leave it alone, which is the whole
    // point of the S2 decision.
    const railLink = within(screen.getByRole('navigation', { name: 'Workspaces' })).getByRole(
        'link',
        { name: 'Projects' },
    );
    railLink.focus();
    emitInertiaNavigate();

    expect(railLink).toHaveFocus();
});

it('resolves the presentation family in one place', () => {
    mount(navigation([projects], 'projects'));

    // §23.2 seam 2: AppShell is the only component that reads `shell.presentation`. The static
    // guard in the Pest suite proves no page reads it; this proves the shell honours it.
    expect(screen.getByRole('main')).toBeInTheDocument();
    expect(document.querySelector('[data-shell="operational"]')).not.toBeNull();
});

it('starts the panel collapsed below XL so an overlay never covers the canvas on load', () => {
    setWidthClass('l');
    mount(navigation([projects], 'projects'));

    // Projects defaults to open, but below XL the panel floats, so it waits to be asked for.
    expect(screen.queryByRole('navigation', { name: 'Projects views' })).toBeNull();
    expect(screen.getByRole('button', { name: 'Show workspace views' })).toBeInTheDocument();
});

it('leaves the stored XL preference alone when the overlay is opened and closed at L', async () => {
    const stored = { projects: 'collapsed' };
    localStorage.setItem('shell.operational.panel', JSON.stringify(stored));
    setWidthClass('l');
    mount(navigation([projects], 'projects'));
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: 'Show workspace views' }));
    expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Collapse workspace views' }));
    expect(screen.queryByRole('navigation', { name: 'Projects views' })).toBeNull();

    expect(JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}')).toEqual(stored);
});

// ── EPIC-015 WP5: per-surface drawer defaults (Direction D §5.3) ─────────────

describe('a Projects surface with its own default', () => {
    const board = {
        ...projects,
        presentation: {
            operational: { panel: 'collapsed' as const, surface: 'projects.board' },
        },
    };

    it('starts collapsed on the Board although the workspace choice is open', () => {
        localStorage.setItem('shell.operational.panel', JSON.stringify({ projects: 'open' }));

        mount(navigation([board], 'projects'));

        expect(screen.queryByRole('navigation', { name: 'Projects views' })).toBeNull();
        expect(document.documentElement.dataset.drawer).toBe('collapsed');
        expect(document.documentElement.dataset.drawerSurface).toBe('projects.board');
    });

    it('remembers a choice made on the Board under the Board key only', async () => {
        mount(navigation([board], 'projects'));

        await userEvent.setup().click(screen.getByRole('button', { name: 'Show workspace views' }));

        expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
        expect(JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}')).toEqual({
            'projects.board': 'open',
        });
    });

    it('leaves a payload without a surface on the workspace default and key', () => {
        mount(navigation([projects], 'projects'));

        expect(screen.getByRole('navigation', { name: 'Projects views' })).toBeInTheDocument();
        expect(document.documentElement.dataset.drawerSurface).toBeUndefined();
    });
});
