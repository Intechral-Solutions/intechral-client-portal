import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

const { post, props } = vi.hoisted(() => ({
    post: vi.fn(),
    props: {
        app: { name: 'Test Portal' },
        auth: {
            user: { id: 1, name: 'Test User', email: 'test@example.com' },
            permissions: ['projects.view'],
        },
        navigation: [
            {
                key: 'primary',
                label: null,
                items: [
                    {
                        key: 'projects',
                        label: 'Projects',
                        href: '/projects',
                        method: 'get',
                        visit: 'document',
                        activePatterns: ['projects.*'],
                        isActive: false,
                        children: [],
                    },
                ],
            },
            {
                key: 'management',
                label: 'Manage',
                items: [
                    {
                        key: 'users',
                        label: 'Users',
                        href: '/users',
                        method: 'get',
                        visit: 'document',
                        activePatterns: ['users.*'],
                        isActive: false,
                        children: [],
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
    expect(await screen.findByRole('menuitem', { name: 'Users' })).toHaveAttribute(
        'href',
        '/users',
    );
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
