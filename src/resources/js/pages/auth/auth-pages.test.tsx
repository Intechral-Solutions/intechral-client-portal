import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

const { post, reset, setData } = vi.hoisted(() => ({
    post: vi.fn(),
    reset: vi.fn(),
    setData: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        props: {
            app: { name: 'Test Portal' },
            auth: { user: null, permissions: [] },
            navigation: [],
            flash: { success: null, error: null, status: null, warning: null },
        },
    }),
    useForm: <T extends Record<string, unknown>>(data: T) => ({
        data,
        errors: {},
        processing: false,
        setData,
        post,
        reset,
        clearErrors: vi.fn(),
        transform: vi.fn(),
    }),
}));
vi.mock('@/routes', () => ({ login: { url: () => '/login' } }));
vi.mock('@/routes/login', () => ({ store: { url: () => '/login' } }));
vi.mock('@/routes/password', () => ({
    request: { url: () => '/forgot-password' },
    email: { url: () => '/forgot-password' },
    update: { url: () => '/reset-password' },
}));
vi.mock('@/routes/password/confirm', () => ({
    store: { url: () => '/user/confirm-password' },
}));
vi.mock('@/routes/two-factor/login', () => ({
    store: { url: () => '/two-factor-challenge' },
}));
vi.mock('@/routes/invitation', () => ({
    register: { url: (token: string) => `/invitation/${token}` },
}));
vi.mock('@/routes/sso', () => ({
    redirect: {
        url: (provider: string, options?: { query?: { invitation?: string } }) =>
            `/auth/${provider}/redirect${options?.query?.invitation ? `?invitation=${options.query.invitation}` : ''}`,
    },
}));

import InvitationInvalidPage from './invitation-invalid';
import InvitationRegisterPage from './invitation-register';
import LoginPage from './login';
import ResetPasswordPage from './reset-password';
import TwoFactorChallengePage from './two-factor-challenge';

it('renders password-manager-friendly login fields and normal provider links', () => {
    render(<LoginPage />);

    expect(screen.getByLabelText('Email address')).toHaveAttribute('autocomplete', 'username');
    expect(screen.getByLabelText('Password')).toHaveAttribute('autocomplete', 'current-password');
    expect(screen.getByRole('checkbox', { name: 'Remember me' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Google' })).toHaveAttribute(
        'href',
        '/auth/google/redirect',
    );
});

it('keeps the reset token in the form boundary', () => {
    const { container } = render(
        <ResetPasswordPage token="reset-token" email="person@example.com" />,
    );

    expect(container.querySelector('input[name="token"]')).toHaveValue('reset-token');
    expect(screen.getByLabelText('Email address')).toHaveValue('person@example.com');
});

it('switches between authenticator and recovery inputs and moves focus', async () => {
    const user = userEvent.setup();
    render(<TwoFactorChallengePage />);

    expect(screen.getByLabelText('Authentication code')).toHaveFocus();
    await user.click(screen.getByRole('button', { name: 'Recovery code' }));
    expect(screen.getByLabelText('Recovery code')).toHaveFocus();
    expect(setData).toHaveBeenCalledWith('code', '');
});

it('renders an immutable invitation email and server-bound provider URLs', () => {
    render(
        <InvitationRegisterPage
            invitation={{ email: 'invited@example.com', expiresAt: '2026-09-20T00:00:00Z' }}
            token="invitation-token"
        />,
    );

    expect(screen.getByLabelText('Email address')).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Microsoft' })).toHaveAttribute(
        'href',
        '/auth/microsoft/redirect?invitation=invitation-token',
    );
});

it('renders one non-enumerating invitation failure state', () => {
    render(<InvitationInvalidPage />);

    expect(screen.getByRole('heading', { name: 'Invitation unavailable' })).toBeInTheDocument();
    expect(screen.queryByText(/token/i)).not.toBeInTheDocument();
});
