import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { vi } from 'vitest';

const { router } = vi.hoisted(() => ({
    router: { post: vi.fn(), delete: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router,
    useForm: (data: Record<string, string>) => ({
        data,
        errors: 'name' in data ? { email: 'The email address is invalid.' } : {},
        processing: false,
        setData: vi.fn(),
        put: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        reset: vi.fn(),
    }),
}));
vi.mock('@/routes/profile/sessions', () => ({
    destroy: { url: () => '/profile/sessions' },
    default: {},
}));
vi.mock('@/routes/profile/social', () => ({
    unlink: { url: (provider: string) => `/profile/social/${provider}` },
    default: {},
}));
vi.mock('@/routes/profile/password', () => ({
    confirm: { url: () => '/profile/confirm-password' },
    default: {},
}));
vi.mock('@/routes/password', () => ({
    confirmation: { url: () => '/user/confirmed-password-status' },
    default: {},
}));
vi.mock('@/routes/sso', () => ({
    redirect: { url: (provider: string) => `/auth/${provider}/redirect` },
}));
vi.mock('@/routes/two-factor', () => ({
    confirm: { url: () => '/user/confirmed-two-factor-authentication' },
    disable: { url: () => '/user/two-factor-authentication' },
    enable: { url: () => '/user/two-factor-authentication' },
    qrCode: { url: () => '/user/two-factor-qr-code' },
    recoveryCodes: { url: () => '/user/two-factor-recovery-codes' },
    regenerateRecoveryCodes: { url: () => '/user/two-factor-recovery-codes' },
    secretKey: { url: () => '/user/two-factor-secret-key' },
}));
vi.mock('@/routes/user-password', () => ({ update: { url: () => '/user/password' } }));
vi.mock('@/routes/user-profile-information', () => ({
    update: { url: () => '/user/profile-information' },
}));

import ProfilePage, { type ProfilePageProps } from './show';

const baseProps: ProfilePageProps = {
    profile: { name: 'Ada Client', email: 'ada@example.com', hasPassword: true },
    twoFactor: { enabled: false, confirmed: false },
    connectedAccounts: [
        { provider: 'google', connected: true },
        { provider: 'microsoft', connected: false },
    ],
    sessions: [
        {
            isCurrent: true,
            ipAddress: '127.0.0.1',
            userAgent: 'Test Browser',
            lastActiveAt: '2026-09-19T12:00:00Z',
        },
    ],
};

it('renders the complete local-password profile and associated field errors', () => {
    render(<ProfilePage {...baseProps} />);

    expect(screen.getByRole('heading', { name: 'Profile' })).toBeInTheDocument();
    expect(screen.getByLabelText('Email address')).toHaveAccessibleDescription(
        'The email address is invalid.',
    );
    expect(
        screen.getByLabelText('Current password', { selector: '#current-password' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Test Browser')).toBeInTheDocument();
    expect(screen.getByText('Current')).toBeInTheDocument();
});

it('does not offer unsupported password or session forms to passwordless users', () => {
    render(
        <ProfilePage
            {...baseProps}
            profile={{ ...baseProps.profile, hasPassword: false }}
            sessions={[]}
        />,
    );

    expect(screen.getByText('Local password sign-in is not configured')).toBeInTheDocument();
    expect(screen.getByText(/Session revocation requires a local password/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Update password' })).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Sign out other browser sessions' }),
    ).not.toBeInTheDocument();
});

it('shows the pending two-factor confirmation flow without exposing secrets initially', () => {
    render(<ProfilePage {...baseProps} twoFactor={{ enabled: true, confirmed: false }} />);

    expect(screen.getByText('Setup is not complete')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Show setup details' })).toBeInTheDocument();
    expect(screen.getByLabelText('Authentication code')).toBeInTheDocument();
    expect(screen.queryByText('Setup key')).not.toBeInTheDocument();
});

it('keeps recovery codes concealed until explicitly requested', async () => {
    const user = userEvent.setup();
    vi.stubGlobal(
        'fetch',
        vi.fn().mockImplementation((url: string) =>
            Promise.resolve({
                ok: true,
                redirected: false,
                json: () =>
                    Promise.resolve(
                        url.includes('confirmed-password-status')
                            ? { confirmed: true }
                            : ['recovery-one', 'recovery-two'],
                    ),
            }),
        ),
    );
    render(<ProfilePage {...baseProps} twoFactor={{ enabled: true, confirmed: true }} />);

    expect(screen.queryByText('recovery-one')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Show recovery codes' }));
    expect(await screen.findByText('recovery-one')).toBeInTheDocument();
});

it('asks for confirmation before disconnecting a sign-in provider', async () => {
    const user = userEvent.setup();
    render(<ProfilePage {...baseProps} />);

    await user.click(screen.getByRole('button', { name: 'Disconnect' }));
    expect(await screen.findByRole('dialog')).toHaveAccessibleName('Disconnect Google?');
    expect(router.delete).not.toHaveBeenCalled();
});
