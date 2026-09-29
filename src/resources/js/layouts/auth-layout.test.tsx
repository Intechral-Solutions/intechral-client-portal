import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            app: { name: 'Test Portal' },
            auth: { user: null, permissions: [] },
            shell: { presentation: 'operational' },
            navigation: { currentWorkspace: null, workspaces: [] },
            flash: {
                success: null,
                error: null,
                status: 'Check your inbox.',
                warning: null,
            },
        },
    }),
}));

import { AuthLayout } from './auth-layout';

it('renders shared branding, one page heading, flash feedback, and theme control', async () => {
    const user = userEvent.setup();
    document.documentElement.dataset.theme = 'light';

    render(<AuthLayout title="Sign in">Form content</AuthLayout>);

    expect(screen.getByRole('link', { name: 'Test Portal home' })).toHaveAttribute('href', '/');
    expect(screen.getAllByRole('heading')).toHaveLength(1);
    expect(screen.getByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Check your inbox.');

    await user.click(screen.getByRole('button', { name: 'Switch to dark theme' }));
    expect(document.documentElement.dataset.theme).toBe('dark');
});
