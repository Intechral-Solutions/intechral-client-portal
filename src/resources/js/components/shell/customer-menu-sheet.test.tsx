import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { resetInertiaMock } from '@/test/inertia';

import { CustomerMenuSheet } from './customer-menu-sheet';
import { focusedNavigation } from './shell-fixtures';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

function mount(current: string | null = 'projects') {
    return render(
        <>
            <button type="button">Before</button>
            <CustomerMenuSheet workspaces={focusedNavigation(current).workspaces} />
            <button type="button">After</button>
        </>,
    );
}

// Radix opens on pointer-down only for the first test in a jsdom file, so the sheet is opened from the
// keyboard — which is the canonical path anyway.
async function open(actor: ReturnType<typeof userEvent.setup>) {
    screen.getByRole('button', { name: 'Open navigation' }).focus();
    await actor.keyboard('{Enter}');

    return screen.findByRole('dialog', { name: 'Navigation' });
}

it('is a named, collapsed dialog trigger until opened', () => {
    mount();

    const trigger = screen.getByRole('button', { name: 'Open navigation' });

    expect(trigger).toHaveAttribute('aria-haspopup', 'dialog');
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(trigger).toHaveAttribute('data-shell-sheet-trigger');
    expect(screen.queryByRole('dialog')).toBeNull();
});

it('opens a titled dialog holding the same links, with the current one marked', async () => {
    const actor = userEvent.setup();
    mount('projects');

    const dialog = await open(actor);
    const links = within(within(dialog).getByRole('navigation', { name: 'Main' })).getAllByRole(
        'link',
    );

    expect(screen.getByRole('button', { name: 'Close navigation' })).toBeInTheDocument();
    expect(links.map((link) => link.textContent)).toEqual([
        'Home',
        'Support',
        'Projects',
        'Invoices',
        'Resources',
    ]);
    expect(
        links.filter((link) => link.hasAttribute('aria-current')).map((l) => l.textContent),
    ).toEqual(['Projects']);
    // The width is a CSS rule on this hook (the portal puts it outside the shell root); it is not a
    // utility class, and the content is a focused-sheet, not the operator's.
    expect(dialog).toHaveAttribute('data-shell-sheet', 'focused');
    expect(dialog.className).not.toMatch(/w-\[/);
    // Radix hides everything outside an open modal from the accessibility tree, the trigger included.
    expect(screen.getByRole('button', { name: 'Open navigation', hidden: true })).toHaveAttribute(
        'aria-expanded',
        'true',
    );
});

it('traps focus inside the sheet', async () => {
    const actor = userEvent.setup();
    mount();

    const dialog = await open(actor);

    for (let press = 0; press < 12; press += 1) {
        await actor.tab();
        expect(dialog.contains(document.activeElement), `tab ${press + 1}`).toBe(true);
    }
});

it('closes on Escape and returns focus to the trigger', async () => {
    const actor = userEvent.setup();
    mount();

    await open(actor);
    await actor.keyboard('{Escape}');

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveAttribute(
        'aria-expanded',
        'false',
    );
});

it('closes from its close button and returns focus to the trigger', async () => {
    const actor = userEvent.setup();
    mount();

    await open(actor);
    await actor.click(screen.getByRole('button', { name: 'Close navigation' }));

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('button', { name: 'Open navigation' })).toHaveFocus();
});

it('closes when a link is followed, so the sheet never covers the page it opened', async () => {
    const actor = userEvent.setup();
    mount();

    const dialog = await open(actor);
    const link = within(dialog).getByRole('link', { name: 'Support' });
    // jsdom cannot navigate; the click handler still runs, which is what closes the sheet.
    link.addEventListener('click', (event) => event.preventDefault());
    await actor.click(link);

    expect(screen.queryByRole('dialog')).toBeNull();
});

it('holds navigation links only: no account trigger, no timer, no contextual section', async () => {
    const actor = userEvent.setup();
    mount();

    const dialog = await open(actor);

    expect(within(dialog).queryByRole('button', { name: /Account menu/ })).toBeNull();
    expect(within(dialog).getAllByRole('navigation')).toHaveLength(1);
    expect(within(dialog).queryByText(/timer|views/i)).toBeNull();
});
