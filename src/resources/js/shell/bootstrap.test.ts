import { resolvePanel } from '@/hooks/use-panel-state';
import type { PanelState } from '@/hooks/use-panel-state';

import source from './bootstrap.js?raw';

/**
 * EPIC-013 WP5 — the shared pre-paint bootstrap, executed exactly as both root views inline it.
 *
 * `bootstrap.js` is a classic script that nothing compiles, so the tests run the file's own text
 * rather than an import of it. The central case is parity: for every combination of inputs the script
 * must resolve `html[data-drawer]` to exactly what the React shell's `resolvePanel()` resolves, or a
 * Blade page and a React page would disagree about the panel across a renderer crossing.
 */

const panelKey = 'shell.operational.panel';
const pinKey = 'shell.operational.pin';
const root = document.documentElement;
const originalMatchMedia = window.matchMedia;

type Media = { xl: boolean | 'throws'; dark?: boolean };

function setMedia({ xl, dark = false }: Media) {
    window.matchMedia = (query: string) => {
        if (query.includes('1360px') && xl === 'throws') {
            throw new Error('matchMedia unavailable');
        }

        return {
            matches: query.includes('1360px') ? xl === true : query.includes('dark') && dark,
            media: query,
        } as MediaQueryList;
    };
}

function stamp(workspace: string | null, serverDefault: string | null) {
    delete root.dataset.drawer;

    if (workspace === null) {
        delete root.dataset.workspace;
    } else {
        root.dataset.workspace = workspace;
    }

    if (serverDefault === null) {
        delete root.dataset.drawerDefault;
    } else {
        root.dataset.drawerDefault = serverDefault;
    }
}

function runBootstrap() {
    new Function(source)();
}

beforeEach(() => setMedia({ xl: true }));

afterEach(() => {
    window.matchMedia = originalMatchMedia;
    vi.restoreAllMocks();
    delete root.dataset.drawer;
    delete root.dataset.workspace;
    delete root.dataset.drawerDefault;
});

describe('panel parity with the React shell', () => {
    const workspaces = ['projects', null] as const;
    const defaults = ['open', 'collapsed', null] as const;
    const stored = [undefined, 'open', 'collapsed', 'sideways'] as const;
    const pins = [false, true] as const;
    const widths = [true, false, 'throws'] as const;

    const cases = workspaces.flatMap((workspace) =>
        defaults.flatMap((serverDefault) =>
            stored.flatMap((remembered) =>
                pins.flatMap((pinned) =>
                    widths.map((xl) => ({ workspace, serverDefault, remembered, pinned, xl })),
                ),
            ),
        ),
    );

    it.each(cases)(
        'workspace=$workspace default=$serverDefault stored=$remembered pinned=$pinned xl=$xl',
        ({ workspace, serverDefault, remembered, pinned, xl }) => {
            setMedia({ xl });

            if (remembered !== undefined) {
                localStorage.setItem(panelKey, JSON.stringify({ projects: remembered }));
            }

            if (pinned) {
                localStorage.setItem(pinKey, JSON.stringify({ projects: true }));
            }

            stamp(workspace, serverDefault);
            runBootstrap();

            // The hook's own resolution for the same inputs. It reads the pin itself when mounted, so
            // the same stored truth is passed in here.
            const expected = resolvePanel(
                workspace,
                serverDefault as PanelState | null,
                pinned && workspace !== null,
            );

            expect(root.dataset.drawer).toBe(expected);
        },
    );
});

describe('hostile or missing storage', () => {
    it.each([
        ['malformed JSON', '{not json'],
        ['a JSON array', '["open"]'],
        ['a JSON string', '"open"'],
        ['JSON null', 'null'],
        ['an unknown value', '{"projects":"wide"}'],
        ['another workspace only', '{"tasks":"collapsed"}'],
    ])('falls through to the server default on %s', (_label, raw) => {
        localStorage.setItem(panelKey, raw);
        stamp('projects', 'open');

        runBootstrap();

        expect(root.dataset.drawer).toBe('open');
    });

    it('falls through to the server default when storage throws, and throws nothing itself', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new DOMException('blocked', 'SecurityError');
        });
        stamp('helpdesk', 'open');

        expect(runBootstrap).not.toThrow();
        expect(root.dataset.drawer).toBe('open');
        expect(root.dataset.theme).toBe('light');
    });

    it('resolves collapsed when the server stamped no workspace or default', () => {
        localStorage.setItem(panelKey, JSON.stringify({ projects: 'open' }));
        stamp(null, null);

        runBootstrap();

        expect(root.dataset.drawer).toBe('collapsed');
    });

    it('ignores a server default outside the closed set', () => {
        stamp('projects', 'sideways');

        runBootstrap();

        expect(root.dataset.drawer).toBe('collapsed');
    });

    it('never writes storage', () => {
        const setItem = vi.spyOn(Storage.prototype, 'setItem');
        stamp('projects', 'open');

        runBootstrap();

        expect(setItem).not.toHaveBeenCalled();
    });
});

describe('theme', () => {
    it('applies a stored light or dark choice', () => {
        localStorage.setItem('theme', 'dark');
        runBootstrap();
        expect(root.dataset.theme).toBe('dark');

        localStorage.setItem('theme', 'light');
        setMedia({ xl: true, dark: true });
        runBootstrap();
        expect(root.dataset.theme).toBe('light');
    });

    it('falls back to the colour-scheme preference when nothing valid is stored', () => {
        setMedia({ xl: true, dark: true });
        runBootstrap();
        expect(root.dataset.theme).toBe('dark');

        // A value outside the closed set is not applied verbatim to the root attribute.
        localStorage.setItem('theme', 'purple');
        setMedia({ xl: true, dark: false });
        runBootstrap();
        expect(root.dataset.theme).toBe('light');
    });
});
