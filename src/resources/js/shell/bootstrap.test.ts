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

// XL, L, M/S, and a browser that cannot answer either width query (treated as XL).
const widthClasses = [
    ['xl', { xl: true }],
    ['l', { xl: false, large: true }],
    ['m', { xl: false, large: false }],
    ['unanswerable', { xl: 'throws', large: 'throws' }],
] as const satisfies readonly (readonly [string, Media])[];

type Media = { xl: boolean | 'throws'; large?: boolean | 'throws'; dark?: boolean };

// `large` is the 1024px query; it defaults to "true when XL", as a real viewport does.
function setMedia({ xl, large = xl === true, dark = false }: Media) {
    window.matchMedia = (query: string) => {
        if (
            (query.includes('1360px') && xl === 'throws') ||
            (query.includes('1024px') && large === 'throws')
        ) {
            throw new Error('matchMedia unavailable');
        }

        const is1024 = query.includes('1024px');

        return {
            matches: query.includes('1360px')
                ? xl === true
                : is1024
                  ? large === true
                  : query.includes('dark') && dark,
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
    delete root.dataset.drawerSurface;
});

describe('panel parity with the React shell', () => {
    const workspaces = ['projects', null] as const;
    const defaults = ['open', 'collapsed', null] as const;
    const stored = [undefined, 'open', 'collapsed', 'sideways'] as const;
    const pins = [false, true] as const;

    const cases = workspaces.flatMap((workspace) =>
        defaults.flatMap((serverDefault) =>
            stored.flatMap((remembered) =>
                pins.flatMap((pinned) =>
                    widthClasses.map(([width, media]) => ({
                        workspace,
                        serverDefault,
                        remembered,
                        pinned,
                        width,
                        media,
                    })),
                ),
            ),
        ),
    );

    it.each(cases)(
        'workspace=$workspace default=$serverDefault stored=$remembered pinned=$pinned width=$width',
        ({ workspace, serverDefault, remembered, pinned, media }) => {
            setMedia(media);

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

/**
 * EPIC-015 WP5 (Direction D §5.3): on a surface with its own default the server also stamps
 * `data-drawer-surface`, and the remembered value is read under that key instead of the workspace's.
 * The same parity rule: the script and `resolvePanel()` must agree for every combination, with a
 * workspace choice and a surface choice stored side by side so reading the wrong key shows.
 */
describe('per-surface parity with the React shell (EPIC-015 WP5)', () => {
    const surfaces = [null, 'projects.board'] as const;
    const defaults = ['open', 'collapsed'] as const;
    const workspaceStored = [undefined, 'open', 'collapsed'] as const;
    const surfaceStored = [undefined, 'open', 'collapsed', 'sideways'] as const;
    const pins = [false, true] as const;

    const cases = surfaces.flatMap((surface) =>
        defaults.flatMap((serverDefault) =>
            workspaceStored.flatMap((onWorkspace) =>
                surfaceStored.flatMap((onSurface) =>
                    pins.flatMap((pinned) =>
                        widthClasses.map(([width, media]) => ({
                            surface,
                            serverDefault,
                            onWorkspace,
                            onSurface,
                            pinned,
                            width,
                            media,
                        })),
                    ),
                ),
            ),
        ),
    );

    it.each(cases)(
        'surface=$surface default=$serverDefault workspace=$onWorkspace surfaceStored=$onSurface pinned=$pinned width=$width',
        ({ surface, serverDefault, onWorkspace, onSurface, pinned, media }) => {
            setMedia(media);

            const map: Record<string, string> = {};
            if (onWorkspace !== undefined) map.projects = onWorkspace;
            if (onSurface !== undefined) map['projects.board'] = onSurface;
            localStorage.setItem(panelKey, JSON.stringify(map));

            if (pinned) {
                localStorage.setItem(pinKey, JSON.stringify({ projects: true }));
            }

            stamp('projects', serverDefault);
            if (surface !== null) root.dataset.drawerSurface = surface;
            runBootstrap();

            expect(root.dataset.drawer).toBe(
                resolvePanel('projects', serverDefault, pinned, surface),
            );
        },
    );

    it('reads the surface choice, not the workspace one, on a surface', () => {
        localStorage.setItem(
            panelKey,
            JSON.stringify({ projects: 'open', 'projects.board': 'collapsed' }),
        );
        stamp('projects', 'collapsed');
        root.dataset.drawerSurface = 'projects.board';

        runBootstrap();
        expect(root.dataset.drawer).toBe('collapsed');

        // With no surface choice yet, the surface default wins over the workspace's remembered open.
        localStorage.setItem(panelKey, JSON.stringify({ projects: 'open' }));
        runBootstrap();
        expect(root.dataset.drawer).toBe('collapsed');
    });

    it('docks a pinned workspace at L on every surface, whatever the surface says', () => {
        setMedia({ xl: false, large: true });
        localStorage.setItem(pinKey, JSON.stringify({ projects: true }));
        // The Board's own default is collapsed and the user once collapsed it at XL.
        localStorage.setItem(panelKey, JSON.stringify({ 'projects.board': 'collapsed' }));
        stamp('projects', 'collapsed');
        root.dataset.drawerSurface = 'projects.board';

        runBootstrap();
        expect(root.dataset.drawer).toBe('open');

        // Unpinned, the same page starts collapsed at L.
        localStorage.removeItem(pinKey);
        runBootstrap();
        expect(root.dataset.drawer).toBe('collapsed');
    });

    it('ignores the pin and the remembered state at M and S', () => {
        setMedia({ xl: false, large: false });
        localStorage.setItem(pinKey, JSON.stringify({ projects: true }));
        localStorage.setItem(panelKey, JSON.stringify({ projects: 'open' }));
        stamp('projects', 'open');

        runBootstrap();

        expect(root.dataset.drawer).toBe('collapsed');
    });

    it('treats an empty surface stamp as a surface, exactly as the hook does (`??`, not `||`)', () => {
        localStorage.setItem(panelKey, JSON.stringify({ projects: 'collapsed' }));
        stamp('projects', 'open');
        root.dataset.drawerSurface = '';

        runBootstrap();

        // Neither the script nor the hook falls back to the workspace key for an empty surface.
        expect(root.dataset.drawer).toBe(resolvePanel('projects', 'open', false, ''));
        expect(root.dataset.drawer).toBe('open');
    });

    it('ignores a surface stamped without a workspace', () => {
        localStorage.setItem(panelKey, JSON.stringify({ 'projects.board': 'open' }));
        stamp(null, 'collapsed');
        root.dataset.drawerSurface = 'projects.board';

        runBootstrap();

        expect(root.dataset.drawer).toBe('collapsed');
    });
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
