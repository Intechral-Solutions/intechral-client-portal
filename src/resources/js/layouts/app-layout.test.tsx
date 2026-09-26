import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

const { post, props } = vi.hoisted(() => ({
    post: vi.fn(),
    props: {
        app: { name: 'Test Portal' },
        auth: {
            user: {
                id: 1,
                name: 'Test User',
                email: 'test@example.com',
                avatar: { initials: 'TU', url: null },
            },
            permissions: ['projects.view'],
        },
        shell: { presentation: 'operational' as const },
        navigation: {
            currentWorkspace: 'projects',
            workspaces: [
                {
                    key: 'projects',
                    label: 'Projects',
                    icon: 'folder-kanban',
                    href: '/projects',
                    visit: 'inertia' as const,
                    isActive: true,
                    context: [
                        {
                            key: 'views',
                            label: 'Views',
                            kind: 'views' as const,
                            items: [
                                {
                                    key: 'projects.all',
                                    label: 'All projects',
                                    href: '/projects',
                                    visit: 'inertia' as const,
                                    isActive: true,
                                    count: null,
                                },
                            ],
                        },
                    ],
                    presentation: { operational: { panel: 'open' as const } },
                },
            ],
        },
        // The flat compatibility projection the pre-WP4 header still renders. `overflow` carries
        // no label: the "Manage" grouping is retired.
        navigationLegacy: [
            {
                key: 'primary' as const,
                label: null,
                items: [
                    {
                        key: 'projects',
                        label: 'Projects',
                        href: '/projects',
                        visit: 'document' as const,
                        isActive: false,
                    },
                ],
            },
            {
                key: 'overflow' as const,
                label: null,
                items: [
                    {
                        key: 'system.users',
                        label: 'System Users',
                        href: '/admin/users',
                        visit: 'document' as const,
                        isActive: false,
                    },
                ],
            },
        ],
        flash: { success: null, error: null, status: null, warning: null },
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: { post },
    usePage: () => ({ props }),
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));
vi.mock('@/routes', () => ({
    dashboard: { url: () => '/dashboard' },
    logout: { url: () => '/logout' },
}));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));

import { AppLayout } from './app-layout';

it('renders shared navigation and exposes the user menu commands', async () => {
    const user = userEvent.setup();
    render(<AppLayout>Page body</AppLayout>);

    expect(screen.getByRole('navigation', { name: 'Primary navigation' })).toHaveTextContent(
        'Projects',
    );
    expect(screen.getByText('Page body')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Open user menu' }));
    expect(await screen.findByRole('menuitem', { name: 'System Users' })).toHaveAttribute(
        'href',
        '/admin/users',
    );
    // The retired "Manage" grouping must not come back as a heading in the account menu.
    expect(screen.queryByText('Manage')).not.toBeInTheDocument();
    await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));
    expect(post).toHaveBeenCalledWith('/logout');
});

it('opens the mobile navigation dialog', async () => {
    const user = userEvent.setup();
    render(<AppLayout>Page body</AppLayout>);

    await user.click(screen.getByRole('button', { name: 'Open navigation menu' }));
    expect(await screen.findByRole('navigation', { name: 'Mobile navigation' })).toHaveTextContent(
        'Projects',
    );
});
