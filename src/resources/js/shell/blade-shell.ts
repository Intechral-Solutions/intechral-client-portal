/**
 * EPIC-013 WP5 — runtime behaviour for the Blade Direction D shell (§14).
 *
 * Imported by the Blade entry (`app.js`); the React shell never loads it. It owns only interaction:
 * the server rendered every destination, label, active state and authorization result, and CSS owns
 * every geometry decision. Nothing here reads the URL, filters navigation or infers a permission.
 *
 *  - **Panel.** The shared pre-paint bootstrap already resolved `html[data-drawer]`. The rail toggle
 *    and the panel's collapse button flip that attribute and remember the choice in
 *    `localStorage['shell.operational.panel']` — the same key, shape and write rule as the React hook
 *    (`hooks/use-panel-state.ts`), so a choice made on one renderer is honoured by the other. Blade's
 *    panel is docked or hidden, never an overlay (§14.2), so there is no Esc/outside-click dismissal;
 *    `Ctrl+\` toggles it, inert in text fields, as in React.
 *  - **Account menu.** A real menu: arrow keys, Home/End, Escape with focus return, Tab and outside
 *    pointer close it. Appearance writes `localStorage['theme']` + `html[data-theme]`, exactly what
 *    React's `useAppearance` writes and what the bootstrap reads.
 *  - **Nav sheet.** A native modal `<dialog>`: the browser supplies containment, Escape and focus
 *    return. This only opens and closes it and keeps `aria-expanded` truthful.
 *
 * Storage is hostile-tolerant exactly like the React hook: unreadable, unparseable or non-object state
 * is treated as empty, and a write that fails is dropped rather than failing the interaction.
 */

const panelKey = 'shell.operational.panel';
const themeKey = 'theme';

type PanelState = 'open' | 'collapsed';

function readMap(key: string): Record<string, unknown> {
    try {
        const raw = window.localStorage.getItem(key);

        if (!raw) {
            return {};
        }

        const parsed: unknown = JSON.parse(raw);

        return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
            ? (parsed as Record<string, unknown>)
            : {};
    } catch {
        return {};
    }
}

function writeMap(key: string, map: Record<string, unknown>): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(map));
    } catch {
        // A preference that cannot be stored is not worth failing an interaction over.
    }
}

/** True at XL (>= 1360px); a browser that cannot answer is treated as XL, as everywhere else. */
function atExtraLarge(): boolean {
    try {
        return window.matchMedia('(min-width: 1360px)').matches;
    } catch {
        return true;
    }
}

/** True when CSS currently renders the element — the width classes decide, not this file. */
function rendered(element: Element | null): element is HTMLElement {
    return element instanceof HTMLElement && element.getClientRects().length > 0;
}

function isTextEntry(target: EventTarget | null): boolean {
    const element = target as HTMLElement | null;

    return (
        !!element?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(element?.tagName ?? '')
    );
}

function initPanel(shell: HTMLElement): void {
    const root = document.documentElement;
    const toggle = shell.querySelector<HTMLElement>('[data-shell-toggle]');
    const collapse = shell.querySelector<HTMLElement>('[data-shell-collapse]');
    const drawer = shell.querySelector<HTMLElement>('[data-shell-drawer]');

    if (!toggle || !collapse || !drawer) {
        // The workspace has no contextual panel (§12.3 rule 6): nothing to control.
        return;
    }

    function setPanel(next: PanelState): void {
        root.dataset.drawer = next;

        // The same key the React hook writes (`panelPreferenceKey`): the server-stamped surface on a
        // surface with its own default, the workspace otherwise (Direction D §5.3, EPIC-015 WP5).
        const workspace = root.dataset.workspace;
        const preferenceKey = workspace ? (root.dataset.drawerSurface ?? workspace) : null;

        // Only an interaction at XL is the XL preference (same rule as the React hook): below XL the
        // pre-paint bootstrap ignores the remembered state, so a write there could only change what
        // the user finds when they next open the portal wide.
        if (preferenceKey && atExtraLarge()) {
            writeMap(panelKey, { ...readMap(panelKey), [preferenceKey]: next });
        }
    }

    toggle.addEventListener('click', () => {
        setPanel('open');
        // A deliberate open moves focus in, as React does (Direction D §14.2).
        const target =
            drawer.querySelector<HTMLElement>('[aria-current="page"]') ??
            drawer.querySelector<HTMLElement>('a, button');

        target?.focus();
    });

    collapse.addEventListener('click', () => {
        setPanel('collapsed');
        // The toggle is rendered again as soon as the attribute flips; focus returns to it.
        toggle.focus();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== '\\' || !(event.ctrlKey || event.metaKey) || isTextEntry(event.target)) {
            return;
        }

        // Whichever control CSS is showing at this width is the one a pointer user would press.
        const control = [collapse, toggle].find(rendered);

        if (control) {
            event.preventDefault();
            control.click();
        }
    });
}

function applyTheme(theme: 'light' | 'dark'): void {
    document.documentElement.dataset.theme = theme;

    try {
        window.localStorage.setItem(themeKey, theme);
    } catch {
        // The attribute still flips for this page.
    }
}

function initAccountMenu(shell: HTMLElement): void {
    const container = shell.querySelector<HTMLElement>('[data-shell-account-root]');
    const trigger = container?.querySelector<HTMLElement>('[data-shell-account]');
    const menu = container?.querySelector<HTMLElement>('[data-shell-account-menu]');

    if (!container || !trigger || !menu) {
        return;
    }

    const items = () =>
        Array.from(
            menu.querySelectorAll<HTMLElement>(
                '[role="menuitem"]:not([aria-disabled="true"]), [role="menuitemradio"]',
            ),
        );

    function syncAppearance(): void {
        const current = document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';

        menu!.querySelectorAll<HTMLElement>('[data-shell-appearance]').forEach((option) => {
            option.setAttribute(
                'aria-checked',
                option.dataset.shellAppearance === current ? 'true' : 'false',
            );
        });
    }

    function open(): void {
        syncAppearance();
        menu!.hidden = false;
        trigger!.setAttribute('aria-expanded', 'true');
        items()[0]?.focus();
    }

    function close(returnFocus: boolean): void {
        if (menu!.hidden) {
            return;
        }

        menu!.hidden = true;
        trigger!.setAttribute('aria-expanded', 'false');

        if (returnFocus) {
            trigger!.focus();
        }
    }

    trigger.addEventListener('click', () => (menu.hidden ? open() : close(true)));

    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && menu.hidden) {
            event.preventDefault();
            open();
        }
    });

    menu.addEventListener('keydown', (event) => {
        const list = items();
        const index = list.indexOf(document.activeElement as HTMLElement);
        const move = (next: number) => {
            event.preventDefault();
            list[(next + list.length) % list.length]?.focus();
        };

        switch (event.key) {
            case 'ArrowDown':
                return move(index + 1);
            case 'ArrowUp':
                return move(index - 1);
            case 'Home':
                return move(0);
            case 'End':
                return move(list.length - 1);
            case 'Escape':
                event.preventDefault();

                return close(true);
            case 'Tab':
                // Tab leaves the menu for the next control; the menu does not linger behind it.
                return close(false);
        }
    });

    menu.querySelectorAll<HTMLElement>('[data-shell-appearance]').forEach((option) => {
        option.addEventListener('click', () => {
            applyTheme(option.dataset.shellAppearance === 'dark' ? 'dark' : 'light');
            syncAppearance();
        });
    });

    document.addEventListener('pointerdown', (event) => {
        if (!container.contains(event.target as Node)) {
            // The user chose somewhere else to be; taking focus back would fight them.
            close(false);
        }
    });
}

function initNavSheet(shell: HTMLElement): void {
    const trigger = shell.querySelector<HTMLElement>('[data-shell-sheet-trigger]');
    const sheet = shell.querySelector<HTMLDialogElement>('dialog[data-shell-sheet]');

    if (!trigger || !sheet) {
        return;
    }

    trigger.addEventListener('click', () => {
        sheet.showModal();
        trigger.setAttribute('aria-expanded', 'true');
    });

    sheet.querySelector('[data-shell-sheet-close]')?.addEventListener('click', () => sheet.close());

    // The sheet's content fills it, so a click on the <dialog> element itself is on the backdrop.
    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) {
            sheet.close();
        }
    });

    // Fires for Escape as well as for close(): one place keeps the trigger truthful and restores
    // focus to it.
    sheet.addEventListener('close', () => {
        trigger.setAttribute('aria-expanded', 'false');
        trigger.focus();
    });
}

export function initBladeShell(): void {
    const shell = document.querySelector<HTMLElement>(
        '[data-shell="operational"][data-shell-renderer="blade"]',
    );

    if (!shell) {
        return;
    }

    initPanel(shell);
    initAccountMenu(shell);
    initNavSheet(shell);
}
