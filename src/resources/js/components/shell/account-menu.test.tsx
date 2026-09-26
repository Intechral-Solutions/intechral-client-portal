import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { inertiaSpies, resetInertiaMock } from '@/test/inertia';

import { AccountMenu } from './account-menu';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));

const user = {
    id: 1,
    name: 'Dana Webb',
    email: 'dana@example.test',
    avatar: { initials: 'DW', url: null },
};

afterEach(resetInertiaMock);

// Radix only opens on pointer-down for the first test in a jsdom file, so the menu is opened from
// the keyboard — which is the canonical path anyway (the repo's own dropdown-menu tests do the
// same). The trigger is the first focusable element here.
async function openMenu(actor: ReturnType<typeof userEvent.setup>) {
    await actor.tab();
    await actor.keyboard('{Enter}');

    return screen.findByRole('menu');
}

it('exposes the rail account trigger as a named menu button with a circular avatar', () => {
    render(<AccountMenu user={user} />);

    const trigger = screen.getByRole('button', { name: 'Account menu: Dana Webb' });

    expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    // Direction D §13.1 / L9: a rounded-square 40x40 control containing a circular avatar. The
    // avatar never encodes role, so the circle is not conditional.
    expect(trigger.className).toContain('rounded-[6px]');
    expect(trigger.querySelector('.rounded-full')).not.toBeNull();
});

it('derives no initials of its own: the avatar text comes from the person the server named', () => {
    render(<AccountMenu user={user} />);

    // `auth.user.avatar.initials` exists so Blade and React agree; the shell must not re-derive it.
    expect(screen.getByRole('button', { name: 'Account menu: Dana Webb' }).textContent).toBe('DW');
});

it('contains the personal items only', async () => {
    const actor = userEvent.setup();
    render(<AccountMenu user={user} />);

    await openMenu(actor);

    for (const name of [
        'Profile',
        'Security & MFA',
        'Connected accounts',
        'Sessions',
        'Sign out',
    ]) {
        expect(await screen.findByRole('menuitem', { name })).toBeInTheDocument();
    }

    // The disabled row carries its reason in its accessible name, which is the point: a disabled
    // item with a reason, never the mockups' FUTURE tag shipped as product chrome (§17.2).
    const notifications = screen.getByRole('menuitem', { name: /^Notifications/ });

    expect(notifications).toHaveTextContent('Not available yet');
    expect(notifications).toHaveAttribute('aria-disabled', 'true');
});

it('contains no administrative destination and no Manage grouping', async () => {
    const actor = userEvent.setup();
    render(<AccountMenu user={user} />);

    await openMenu(actor);
    await screen.findByRole('menuitem', { name: 'Profile' });

    // The inverse of the pre-WP4 assertion, which is the clearest record of the boundary change:
    // administration lives under the System workspace (L15), and this component receives no
    // navigation payload at all, so there is nothing for an administrative entry to arrive through.
    for (const absent of [
        'Manage',
        'Users',
        'Roles',
        'Organizations',
        'CMS Pages',
        'Ticket Queue',
        'Time Reports',
        'System Users',
    ]) {
        expect(screen.queryByText(absent)).toBeNull();
    }
});

it('anchors security, connected accounts and sessions into the existing profile page', async () => {
    const actor = userEvent.setup();
    render(<AccountMenu user={user} />);

    await openMenu(actor);

    // §17.2: splitting Profile into four routes is Fortify-adjacent product work for a later epic.
    expect(await screen.findByRole('menuitem', { name: 'Security & MFA' })).toHaveAttribute(
        'href',
        '/profile#security',
    );
    expect(screen.getByRole('menuitem', { name: 'Sessions' })).toHaveAttribute(
        'href',
        '/profile#sessions',
    );
});

it('absorbs the theme toggle as an Appearance radiogroup with Light and Dark only', async () => {
    const actor = userEvent.setup();
    render(<AccountMenu user={user} />);

    await openMenu(actor);

    const group = await screen.findByRole('radiogroup', { name: 'Appearance' });
    const options = screen.getAllByRole('radio');

    expect(options.map((option) => option.textContent)).toEqual(['light', 'dark']);
    expect(group).toBeInTheDocument();
    // L6: a System theme is NEXT and is not pulled into this foundation.
    expect(screen.queryByRole('radio', { name: 'system' })).toBeNull();

    expect(screen.getByRole('radio', { name: 'light' })).toHaveAttribute('aria-checked', 'true');

    await actor.click(screen.getByRole('radio', { name: 'dark' }));

    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(localStorage.getItem('theme')).toBe('dark');
});

it('signs out by posting, never by navigating', async () => {
    const actor = userEvent.setup();
    render(<AccountMenu user={user} />);

    await openMenu(actor);
    await actor.click(await screen.findByRole('menuitem', { name: 'Sign out' }));

    expect(inertiaSpies.router.post).toHaveBeenCalledWith('/logout');
});
