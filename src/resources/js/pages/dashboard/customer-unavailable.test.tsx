import { render, screen } from '@testing-library/react';

import CustomerUnavailablePage from '@/pages/dashboard/customer-unavailable';
import { resetInertiaMock, setPageProps } from '@/test/inertia';

/** Release 0.9 (R0.9-CS1): the closed-mode dashboard says so and offers account management, nothing else. */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

it('says the portal is not open yet and links only to the profile', () => {
    render(<CustomerUnavailablePage />);

    expect(screen.getByRole('heading', { level: 1, name: 'Home' })).toBeInTheDocument();
    expect(screen.getByText('The portal is not open to your account yet.')).toBeInTheDocument();
    expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
        '/profile',
    ]);

    for (const absent of [/invoice/i, /project/i, /request/i, /ticket/i, /task/i]) {
        expect(screen.queryByText(absent)).not.toBeInTheDocument();
    }
});
