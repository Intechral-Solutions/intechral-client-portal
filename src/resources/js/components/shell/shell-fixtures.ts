import type { Navigation, Workspace } from '@/types';

/**
 * Payloads shaped exactly as `NavigationBuilder` serializes them, so the shell tests exercise the
 * real contract rather than a convenient invention. Keys, order, icon keys, visit modes and the
 * `presentation.operational.panel` hints all match what the server emits for these workspaces.
 */
export const home: Workspace = {
    key: 'home',
    label: 'Home',
    icon: 'house',
    href: '/dashboard',
    visit: 'inertia',
    isActive: false,
    // A single surface with no views: no contextual navigation for any presentation, and the panel
    // hint agrees by being null (§12.3 rule 6).
    context: [],
    presentation: { operational: { panel: null } },
};

export const projects: Workspace = {
    key: 'projects',
    label: 'Projects',
    icon: 'folder-kanban',
    href: '/projects',
    visit: 'inertia',
    isActive: true,
    context: [
        {
            key: 'views',
            label: 'Views',
            kind: 'views',
            items: [
                {
                    key: 'projects.all',
                    label: 'All projects',
                    href: '/projects',
                    visit: 'inertia',
                    isActive: true,
                    count: null,
                },
            ],
        },
        {
            key: 'actions',
            label: null,
            kind: 'actions',
            items: [
                {
                    key: 'projects.create',
                    label: 'New project',
                    href: '/projects/create',
                    visit: 'inertia',
                    // WP3 guarantees false; the fixture keeps it false so the presentation is not
                    // tested against an impossible payload.
                    isActive: false,
                    count: null,
                },
            ],
        },
    ],
    presentation: { operational: { panel: 'open' } },
};

/** Helpdesk is document-mode throughout, which is what makes it the visit-mode fixture. */
export const helpdesk: Workspace = {
    key: 'helpdesk',
    label: 'Helpdesk',
    icon: 'life-buoy',
    href: '/tickets',
    visit: 'document',
    isActive: false,
    context: [
        {
            key: 'views',
            label: 'Views',
            kind: 'views',
            items: [
                {
                    key: 'helpdesk.requests',
                    label: 'My requests',
                    href: '/tickets',
                    visit: 'document',
                    isActive: false,
                    count: null,
                },
                {
                    key: 'helpdesk.queue',
                    label: 'Queue',
                    href: '/operator/tickets',
                    visit: 'document',
                    isActive: false,
                    count: null,
                },
                {
                    key: 'helpdesk.reports',
                    label: 'Reports',
                    href: '/operator/tickets/reports',
                    visit: 'document',
                    isActive: false,
                    count: null,
                },
            ],
        },
    ],
    presentation: { operational: { panel: 'open' } },
};

/** Tasks defaults to a collapsed panel (Direction D §5.3: the table is the product). */
export const tasks: Workspace = {
    key: 'tasks',
    label: 'Tasks',
    icon: 'list-checks',
    href: '/tasks',
    visit: 'inertia',
    isActive: false,
    context: [
        {
            key: 'views',
            label: 'Views',
            kind: 'views',
            items: [
                {
                    key: 'tasks.mine',
                    label: 'My tasks',
                    href: '/tasks',
                    visit: 'inertia',
                    isActive: true,
                    count: null,
                },
                {
                    key: 'tasks.all',
                    label: 'All tasks',
                    href: '/tasks?view=all',
                    visit: 'inertia',
                    isActive: false,
                    count: null,
                },
            ],
        },
    ],
    presentation: { operational: { panel: 'collapsed' } },
};

/** The transitional G3 viewer workspace: `cms.view AND NOT cms.edit`, document-mode, no context. */
export const resources: Workspace = {
    key: 'resources',
    label: 'Resources',
    icon: 'library',
    href: '/pages',
    visit: 'document',
    isActive: false,
    context: [],
    presentation: { operational: { panel: null } },
};

export function navigation(
    workspaces: Workspace[],
    currentWorkspace: string | null = null,
): Navigation {
    return { currentWorkspace, workspaces };
}

/**
 * jsdom has no layout, and `test/setup.ts` stubs `matchMedia` as "no preference matches anything".
 * The panel's initial state depends on the XL width class (Direction D §5.2), so a test that cares
 * has to say which width class it is standing in.
 */
export function setWidthClass(widthClass: 'xl' | 'below-xl') {
    window.matchMedia = (query: string) =>
        ({
            matches: widthClass === 'xl' && query.includes('1360px'),
            media: query,
            onchange: null,
            addListener: () => {},
            removeListener: () => {},
            addEventListener: () => {},
            removeEventListener: () => {},
            dispatchEvent: () => false,
        }) as MediaQueryList;
}
