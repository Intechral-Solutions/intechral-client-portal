import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { emitInertiaNavigate, resetInertiaMock, setPageProps } from '@/test/inertia';
import type { Navigation } from '@/types';

import { AppShell } from './app-shell';
import { focusedNavigation, shellUser } from './shell-fixtures';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes', () => ({ logout: { url: () => '/logout' } }));
vi.mock('@/routes/profile', () => ({ show: { url: () => '/profile' } }));
// The pill has its own tests; this file asserts the Focused shell never mounts one.
vi.mock('@/components/time/timer-pill', () => ({
    TimerPill: () => <span data-testid="timer-pill" />,
}));

const trailSegments = [{ label: 'Acme rollout', href: '/projects/1' }, { label: 'Task 7' }];

/** The Focused shell, entered the way production enters it: through `AppShell`. */
function mount(
    nav: Navigation = focusedNavigation('home'),
    options: {
        permissions?: string[];
        flash?: Record<string, string | null>;
        trail?: boolean;
    } = {},
) {
    setPageProps({
        auth: { user: shellUser, permissions: options.permissions ?? [] },
        shell: { presentation: 'focused' },
        navigation: nav,
        ...(options.flash
            ? {
                  flash: {
                      success: null,
                      error: null,
                      status: null,
                      warning: null,
                      ...options.flash,
                  },
              }
            : {}),
    });

    return render(
        <AppShell trail={options.trail ? trailSegments : undefined}>
            <h1>Home</h1>
        </AppShell>,
    );
}

beforeEach(() => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => [] }),
    );
});

afterEach(() => {
    resetInertiaMock();
    vi.unstubAllGlobals();
});

it('renders the Direction D customer landmarks: skip link, then a header holding the Main nav, then main', async () => {
    const actor = userEvent.setup();
    mount();

    const header = screen.getByRole('banner');
    const nav = within(header).getByRole('navigation', { name: 'Main' });
    const main = screen.getByRole('main');

    expect(document.querySelector('[data-shell="focused"]')).not.toBeNull();
    expect(nav).toBeInTheDocument();
    // header precedes main in the document, and main is the skip link's focusable target.
    expect(header.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(main).toHaveAttribute('id', 'main-content');
    expect(main).toHaveAttribute('tabindex', '-1');
    expect(screen.getAllByRole('main')).toHaveLength(1);

    // The skip link is the first focusable element and points at main.
    await actor.tab();
    expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveFocus();
    expect(screen.getByRole('link', { name: 'Skip to content' })).toHaveAttribute(
        'href',
        '#main-content',
    );
});

it('keeps the account control in the header but outside the Main nav', () => {
    mount();

    const header = screen.getByRole('banner');
    const account = within(header).getByRole('button', { name: 'Account menu: Dana Webb' });

    expect(
        within(screen.getByRole('navigation', { name: 'Main' })).queryByRole('button', {
            name: /Account menu/,
        }),
    ).toBeNull();
    expect(account).toHaveAttribute('aria-haspopup', 'menu');
    expect(account).toHaveAttribute('aria-expanded', 'false');
    // The circular avatar IS the trigger (Direction D §13.1): no rounded-square tile.
    expect(account.className).toContain('rounded-full');
    expect(account.className).not.toContain('rounded-md');
});

it('renders the server-projected links in order with the server-owned current item', () => {
    mount(focusedNavigation('helpdesk'));

    const links = within(screen.getByRole('navigation', { name: 'Main' })).getAllByRole('link');

    expect(links.map((link) => link.textContent)).toEqual([
        'Home',
        'Support',
        'Projects',
        'Invoices',
        'Resources',
    ]);
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
        '/dashboard',
        '/tickets',
        '/projects',
        '/my/invoices',
        '/pages',
    ]);
    // Exactly the server's current entry carries aria-current and the accent-line underline.
    expect(
        links
            .filter((link) => link.getAttribute('aria-current') === 'page')
            .map((l) => l.textContent),
    ).toEqual(['Support']);
    expect(links[1]?.className).toContain('border-accent-line');
    expect(links[0]?.className).not.toContain('border-accent-line');
});

it('marks nothing current when the server named no current entry', () => {
    mount(focusedNavigation(null));

    const links = within(screen.getByRole('navigation', { name: 'Main' })).getAllByRole('link');

    expect(links.some((link) => link.hasAttribute('aria-current'))).toBe(false);
});

it('omits an entry the server withheld and shows no placeholder for it', () => {
    const nav = focusedNavigation('home');
    nav.workspaces = nav.workspaces.filter((workspace) => workspace.key !== 'finance');
    mount(nav);

    const labels = within(screen.getByRole('navigation', { name: 'Main' }))
        .getAllByRole('link')
        .map((link) => link.textContent);

    expect(labels).toEqual(['Home', 'Support', 'Projects', 'Resources']);
    expect(screen.queryByText(/Invoices/)).toBeNull();
    expect(screen.queryByText(/soon|next|future/i)).toBeNull();
});

it('draws no operator chrome: no rail, drawer, utility bar, breadcrumb or timer', () => {
    // The actor may log time, so a timer would show here if the shell ever drew one.
    mount(focusedNavigation('home'), { permissions: ['time.log'], trail: true });

    for (const hook of [
        '[data-shell-rail]',
        '[data-shell-drawer]',
        '[data-shell-utility]',
        '[data-shell-toggle]',
        '[data-shell-timer]',
        '[data-shell="operational"]',
    ]) {
        expect(document.querySelector(hook), hook).toBeNull();
    }

    expect(screen.queryByRole('navigation', { name: 'Breadcrumb' })).toBeNull();
    expect(screen.queryByRole('navigation', { name: 'Workspaces' })).toBeNull();
    expect(screen.queryByTestId('timer-pill')).toBeNull();
    expect(screen.queryByRole('dialog', { name: 'Running timers' })).toBeNull();
    // A page's trail is accepted and ignored.
    expect(screen.queryByText('Acme rollout')).toBeNull();
});

it('offers the same personal account menu as the rail, below the trigger', async () => {
    const actor = userEvent.setup();
    mount();

    screen.getByRole('button', { name: 'Account menu: Dana Webb' }).focus();
    await actor.keyboard('{Enter}');

    const menu = await screen.findByRole('menu');
    const names = within(menu)
        .getAllByRole('menuitem')
        .map((item) => item.textContent?.replace(/\s+/g, ' ').trim());

    expect(names).toEqual([
        'Profile',
        'Security & MFA',
        'Connected accounts',
        'Sessions',
        'NotificationsNot available yet',
        'Sign out',
    ]);
    expect(within(menu).queryByText(/admin|users|roles|settings|shortcut/i)).toBeNull();
    expect(menu).toHaveAttribute('data-side', 'bottom');
});

it('renders the shell flash region at the top of the column', () => {
    mount(focusedNavigation('home'), { flash: { success: 'Saved.', error: 'Nope.' } });

    expect(screen.getByRole('status')).toHaveTextContent('Saved.');
    expect(screen.getByRole('alert')).toHaveTextContent('Nope.');
});

it('announces each page change politely and repairs focus only when a visit destroyed it', () => {
    mount();

    const region = document.querySelector('[data-shell-announcer]');

    expect(region).toHaveAttribute('aria-live', 'polite');

    // The first navigate is the initial render: nothing was destroyed, so nothing is repaired.
    (document.activeElement as HTMLElement | null)?.blur();
    emitInertiaNavigate();
    expect(screen.getByRole('main')).not.toHaveFocus();

    emitInertiaNavigate();
    expect(screen.getByRole('main')).toHaveFocus();

    // A live control keeps focus.
    const link = within(screen.getByRole('navigation', { name: 'Main' })).getByRole('link', {
        name: 'Projects',
    });
    link.focus();
    emitInertiaNavigate();
    expect(link).toHaveFocus();
});

it('leaves the page its own h1: the shell adds no heading', () => {
    mount();

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('banner').querySelectorAll('h1, h2, h3')).toHaveLength(0);
});
