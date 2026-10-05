import { resolvePanel } from '@/hooks/use-panel-state';

import { initBladeShell } from './blade-shell';

/**
 * EPIC-013 WP5 — the Blade shell's runtime behaviour.
 *
 * The fixture carries only the hooks `blade-shell.ts` reads; the real markup is Blade, asserted on the
 * rendered HTML in `tests/Feature/BladeShellTest.php` and in real Chromium in
 * `tests/Browser/blade-shell.spec.ts`. The native `<dialog>` sheet and the CSS-dependent `Ctrl+\`
 * control are browser-only behaviour and are covered there, not here (jsdom has neither).
 */

const panelKey = 'shell.operational.panel';
const root = document.documentElement;
const originalMatchMedia = window.matchMedia;

function mountShell() {
    document.body.innerHTML = `
        <div data-shell="operational" data-shell-renderer="blade" data-panel="true">
            <div data-shell-rail>
                <button type="button" data-shell-toggle aria-expanded="false">Show workspace views</button>
                <div data-shell-account-root>
                    <button type="button" data-shell-account aria-expanded="false">Account menu: Ada</button>
                    <div role="menu" data-shell-account-menu hidden>
                        <a role="menuitem" tabindex="-1" href="/profile">Profile</a>
                        <a role="menuitem" tabindex="-1" href="/profile#security">Security &amp; MFA</a>
                        <div role="group" aria-label="Appearance">
                            <button type="button" role="menuitemradio" tabindex="-1" aria-checked="false" data-shell-appearance="light">light</button>
                            <button type="button" role="menuitemradio" tabindex="-1" aria-checked="false" data-shell-appearance="dark">dark</button>
                        </div>
                        <div role="menuitem" aria-disabled="true">Notifications</div>
                        <button type="submit" role="menuitem" tabindex="-1">Sign out</button>
                    </div>
                </div>
            </div>
            <div data-shell-drawer>
                <button type="button" data-shell-collapse aria-expanded="true">Collapse workspace views</button>
                <nav aria-label="Helpdesk views">
                    <a href="/tickets">My requests</a>
                    <a href="/operator/tickets" aria-current="page">Queue</a>
                </nav>
            </div>
            <p id="outside">Page content</p>
        </div>`;

    initBladeShell();
}

const byText = (text: string) =>
    Array.from(document.querySelectorAll<HTMLElement>('a, button')).find(
        (element) => element.textContent?.trim() === text,
    )!;

beforeEach(() => {
    root.dataset.workspace = 'helpdesk';
    root.dataset.drawer = 'collapsed';
    // XL, so the hook's resolution below consults the stored map.
    window.matchMedia = (query: string) =>
        ({ matches: query.includes('1360px'), media: query }) as MediaQueryList;
});

afterEach(() => {
    window.matchMedia = originalMatchMedia;
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    delete root.dataset.workspace;
    delete root.dataset.drawer;
    delete root.dataset.drawerSurface;
});

describe('panel', () => {
    it('opens with the toggle, remembers it, and moves focus to the current view', () => {
        localStorage.setItem(panelKey, JSON.stringify({ projects: 'collapsed' }));
        mountShell();

        byText('Show workspace views').click();

        expect(root.dataset.drawer).toBe('open');
        expect(JSON.parse(localStorage.getItem(panelKey) ?? '{}')).toEqual({
            projects: 'collapsed',
            helpdesk: 'open',
        });
        expect(document.activeElement).toBe(byText('Queue'));
    });

    it('collapses, remembers it, and returns focus to the rail toggle', () => {
        root.dataset.drawer = 'open';
        mountShell();

        byText('Collapse workspace views').click();

        expect(root.dataset.drawer).toBe('collapsed');
        expect(document.activeElement).toBe(byText('Show workspace views'));
    });

    it('writes a choice the React shell reads back for the same workspace', () => {
        mountShell();

        byText('Show workspace views').click();
        expect(resolvePanel('helpdesk', 'collapsed')).toBe('open');

        byText('Collapse workspace views').click();
        expect(resolvePanel('helpdesk', 'open')).toBe('collapsed');
    });

    it('replaces an unreadable stored map rather than failing', () => {
        localStorage.setItem(panelKey, '["not", "a", "map"]');
        mountShell();

        byText('Show workspace views').click();

        expect(JSON.parse(localStorage.getItem(panelKey) ?? '{}')).toEqual({ helpdesk: 'open' });
    });

    it('still toggles when storage refuses the write', () => {
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('full', 'QuotaExceededError');
        });
        mountShell();

        expect(() => byText('Show workspace views').click()).not.toThrow();
        expect(root.dataset.drawer).toBe('open');
    });

    it('writes under the stamped surface key, the one the React shell reads (EPIC-015 WP5)', () => {
        // No Blade page is a declared surface today; the writer still follows the shared contract so
        // the two renderers can never disagree about where a choice lives.
        root.dataset.drawerSurface = 'helpdesk.example';
        mountShell();

        byText('Show workspace views').click();

        expect(JSON.parse(localStorage.getItem(panelKey) ?? '{}')).toEqual({
            'helpdesk.example': 'open',
        });
        expect(resolvePanel('helpdesk', 'collapsed', false, 'helpdesk.example')).toBe('open');
        expect(resolvePanel('helpdesk', 'collapsed')).toBe('collapsed');
    });

    it('does not write the XL preference from a toggle below XL', () => {
        window.matchMedia = (query: string) =>
            ({ matches: query.includes('1024px'), media: query }) as MediaQueryList;
        localStorage.setItem(panelKey, JSON.stringify({ helpdesk: 'collapsed' }));
        mountShell();

        byText('Show workspace views').click();

        // The panel still opens for this page view, but the remembered XL value is untouched.
        expect(root.dataset.drawer).toBe('open');
        expect(JSON.parse(localStorage.getItem(panelKey) ?? '{}')).toEqual({
            helpdesk: 'collapsed',
        });
    });

    it('writes nothing when the server stamped no workspace', () => {
        delete root.dataset.workspace;
        mountShell();

        byText('Show workspace views').click();

        expect(localStorage.getItem(panelKey)).toBeNull();
    });
});

describe('account menu', () => {
    const trigger = () => byText('Account menu: Ada');
    const menu = () => document.querySelector<HTMLElement>('[data-shell-account-menu]')!;
    const key = (target: Element, name: string) =>
        target.dispatchEvent(new KeyboardEvent('keydown', { key: name, bubbles: true }));

    it('opens onto its first item and closes on Escape, returning focus to the trigger', () => {
        mountShell();

        trigger().click();

        expect(menu().hidden).toBe(false);
        expect(trigger()).toHaveAttribute('aria-expanded', 'true');
        expect(document.activeElement).toBe(byText('Profile'));

        key(document.activeElement!, 'Escape');

        expect(menu().hidden).toBe(true);
        expect(trigger()).toHaveAttribute('aria-expanded', 'false');
        expect(document.activeElement).toBe(trigger());
    });

    it('moves between enabled items with the arrow keys, Home and End, and wraps', () => {
        mountShell();
        trigger().click();

        key(document.activeElement!, 'ArrowDown');
        expect(document.activeElement).toBe(byText('Security & MFA'));

        key(document.activeElement!, 'End');
        expect(document.activeElement).toBe(byText('Sign out'));

        // The disabled Notifications row is skipped; ArrowUp from the last item reaches Dark.
        key(document.activeElement!, 'ArrowUp');
        expect(document.activeElement).toBe(byText('dark'));

        key(document.activeElement!, 'Home');
        key(document.activeElement!, 'ArrowUp');
        expect(document.activeElement).toBe(byText('Sign out'));
    });

    it('closes without taking focus back when the pointer goes elsewhere', () => {
        mountShell();
        trigger().click();

        document
            .getElementById('outside')!
            .dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));

        expect(menu().hidden).toBe(true);
        expect(document.activeElement).not.toBe(trigger());
    });

    it('reflects the current theme and switches it through the shared contract', () => {
        root.dataset.theme = 'dark';
        mountShell();
        trigger().click();

        expect(byText('dark')).toHaveAttribute('aria-checked', 'true');
        expect(byText('light')).toHaveAttribute('aria-checked', 'false');

        byText('light').click();

        expect(root.dataset.theme).toBe('light');
        expect(localStorage.getItem('theme')).toBe('light');
        expect(byText('light')).toHaveAttribute('aria-checked', 'true');
        // Choosing an appearance keeps the menu open, as the React control does.
        expect(menu().hidden).toBe(false);
    });
});

it('does nothing on a page without the Blade shell', () => {
    document.body.innerHTML = '<div data-shell="operational"></div>';

    expect(() => initBladeShell()).not.toThrow();
});
