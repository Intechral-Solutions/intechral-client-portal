import { act } from '@testing-library/react';
import { renderHook } from '@testing-library/react';

import { setWidthClass } from '@/components/shell/shell-fixtures';

import { panelPreferenceKey, resolvePanel, usePanelState } from './use-panel-state';
import type { PanelState } from './use-panel-state';

// Most of these assert the remembered/default resolution, which only applies at XL (§5.2). The
// below-XL rule has its own cases at the bottom.
beforeEach(() => setWidthClass('xl'));

const key = 'shell.operational.panel';

it('prefers the remembered state, then the server default, then collapsed', () => {
    expect(resolvePanel('projects', 'open')).toBe('open');
    expect(resolvePanel('tasks', 'collapsed')).toBe('collapsed');
    // A workspace with no contextual panel has no state to resolve.
    expect(resolvePanel('home', null)).toBe('collapsed');

    localStorage.setItem(key, JSON.stringify({ projects: 'collapsed' }));

    expect(resolvePanel('projects', 'open')).toBe('collapsed');
    // A stored value for another workspace must not bleed across.
    expect(resolvePanel('tasks', 'open')).toBe('open');
});

it('stamps the shared attribute contract on the document element', () => {
    renderHook(() => usePanelState('projects', 'open'));

    // WP5's Blade pre-paint bootstrap reads the same two attributes, so one CSS contract serves
    // both renderers (§15.3).
    expect(document.documentElement.dataset.drawer).toBe('open');
    expect(document.documentElement.dataset.workspace).toBe('projects');
});

it('persists a toggle per workspace without disturbing the others', () => {
    localStorage.setItem(key, JSON.stringify({ tasks: 'open' }));

    const { result } = renderHook(() => usePanelState('projects', 'open'));

    act(() => result.current.toggle());

    expect(result.current.panel).toBe('collapsed');
    expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({
        tasks: 'open',
        projects: 'collapsed',
    });
});

it('re-resolves when an Inertia visit changes the workspace under a live shell', () => {
    localStorage.setItem(key, JSON.stringify({ projects: 'open', tasks: 'collapsed' }));

    type Props = { workspace: string; fallback: PanelState };

    const { result, rerender } = renderHook(
        ({ workspace, fallback }: Props) => usePanelState(workspace, fallback),
        { initialProps: { workspace: 'projects', fallback: 'open' } as Props },
    );

    expect(result.current.panel).toBe('open');

    // Resolved during render, not in a post-navigate listener, so the geometry change is atomic
    // with the content change (A1.7 implementation requirement 1).
    rerender({ workspace: 'tasks', fallback: 'collapsed' });

    expect(result.current.panel).toBe('collapsed');
    expect(document.documentElement.dataset.workspace).toBe('tasks');
});

it('remembers the L pin separately from the open/collapsed state', () => {
    const { result } = renderHook(() => usePanelState('finance', 'collapsed'));

    expect(result.current.pinned).toBe(false);

    act(() => result.current.pin());

    expect(result.current.pinned).toBe(true);
    expect(result.current.panel).toBe('open');
    expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({
        finance: true,
    });
});

it.each([
    ['malformed JSON', '{not json'],
    ['a JSON array', '["open"]'],
    ['a JSON string', '"open"'],
    ['null', 'null'],
    ['an unexpected value for this workspace', '{"projects":"maximised"}'],
    ['a boolean for this workspace', '{"projects":true}'],
])('falls back to the server default when storage holds %s', (_label, raw) => {
    localStorage.setItem(key, raw);

    expect(resolvePanel('projects', 'open')).toBe('open');
});

it('falls back to the server default when localStorage throws', () => {
    const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new DOMException('denied', 'SecurityError');
    });

    // A private-mode browser or a blocked origin must not throw inside render.
    expect(() => resolvePanel('projects', 'collapsed')).not.toThrow();
    expect(resolvePanel('projects', 'open')).toBe('open');

    getItem.mockRestore();
});

it('does not fail an interaction when the preference cannot be stored', () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new DOMException('quota', 'QuotaExceededError');
    });

    const { result } = renderHook(() => usePanelState('projects', 'open'));

    expect(() => act(() => result.current.toggle())).not.toThrow();
    expect(result.current.panel).toBe('collapsed');

    setItem.mockRestore();
});

it('starts collapsed below XL whatever the workspace default says', () => {
    setWidthClass('l');
    localStorage.setItem(key, JSON.stringify({ projects: 'open' }));

    // At L and M the panel floats, so an unbidden `open` would lay a 248px overlay over the left of
    // the canvas on load. Direction D §5.2: collapsed by default below XL; the user opens an overlay.
    expect(resolvePanel('projects', 'open')).toBe('collapsed');
    expect(resolvePanel('projects', 'collapsed')).toBe('collapsed');
});

it('honours a pin at L, which is the one way the panel docks below XL', () => {
    setWidthClass('l');

    expect(resolvePanel('projects', 'open', true)).toBe('open');
    // The pin is a layout choice, not a remembered open/collapsed value, so it docks even where the
    // default would not (§5.3 rule 2; EPIC-015 WP5 owner ruling).
    expect(resolvePanel('projects', 'collapsed', true)).toBe('open');
});

it('ignores a pin and every remembered state at M and S', () => {
    setWidthClass('m');
    localStorage.setItem(key, JSON.stringify({ projects: 'open' }));

    expect(resolvePanel('projects', 'open', true)).toBe('collapsed');
    expect(resolvePanel('projects', 'open', false)).toBe('collapsed');
});

it('treats a browser that cannot answer the width query as XL', () => {
    // A silent collapse would be the worse failure: the documented default is the wide layout.
    vi.stubGlobal('matchMedia', () => {
        throw new Error('unavailable');
    });

    expect(resolvePanel('projects', 'open')).toBe('open');

    vi.unstubAllGlobals();
});

// ── EPIC-015 WP5: per-surface defaults (Direction D §5.3 rule 1) ───────────────

describe('per-surface defaults and overrides', () => {
    it('keys a choice by surface when the server names one, by workspace otherwise', () => {
        expect(panelPreferenceKey('projects')).toBe('projects');
        expect(panelPreferenceKey('projects', null)).toBe('projects');
        expect(panelPreferenceKey('projects', 'projects.board')).toBe('projects.board');
        // No workspace, no preference at all.
        expect(panelPreferenceKey(null, 'projects.board')).toBeNull();
    });

    it('applies the surface default rather than the workspace choice on a surface', () => {
        // The user opened the Projects drawer on the Overview; the Board still starts collapsed.
        localStorage.setItem(key, JSON.stringify({ projects: 'open' }));

        expect(resolvePanel('projects', 'collapsed', false, 'projects.board')).toBe('collapsed');
        expect(resolvePanel('projects', 'open')).toBe('open');
    });

    it('remembers a choice made on a surface for that surface only', () => {
        localStorage.setItem(key, JSON.stringify({ projects: 'collapsed', tasks: 'open' }));

        const { result } = renderHook(() =>
            usePanelState('projects', 'collapsed', 'projects.board'),
        );

        expect(result.current.panel).toBe('collapsed');
        act(() => result.current.toggle());

        expect(result.current.panel).toBe('open');
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({
            projects: 'collapsed',
            tasks: 'open',
            'projects.board': 'open',
        });
        // …and the Board's choice now wins over its default, while the Overview keeps its own.
        expect(resolvePanel('projects', 'collapsed', false, 'projects.board')).toBe('open');
        expect(resolvePanel('projects', 'open')).toBe('collapsed');
    });

    it('keeps a workspace choice off the surfaces: toggling on the Overview never writes a surface key', () => {
        const { result } = renderHook(() => usePanelState('projects', 'open'));

        act(() => result.current.toggle());

        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({ projects: 'collapsed' });
        expect(resolvePanel('projects', 'collapsed', false, 'projects.tasks')).toBe('collapsed');
    });

    it('re-resolves when an Inertia visit moves between surfaces of one workspace', () => {
        localStorage.setItem(key, JSON.stringify({ 'projects.board': 'open' }));

        type Props = { surface: string | null; fallback: PanelState };
        const { result, rerender } = renderHook(
            ({ surface, fallback }: Props) => usePanelState('projects', fallback, surface),
            { initialProps: { surface: null, fallback: 'open' } as Props },
        );

        expect(result.current.panel).toBe('open');
        expect(document.documentElement.dataset.drawerSurface).toBeUndefined();

        // Overview → Tasks tab: the Tasks surface's own default, not the Overview's open state.
        rerender({ surface: 'projects.tasks', fallback: 'collapsed' });
        expect(result.current.panel).toBe('collapsed');
        expect(document.documentElement.dataset.drawerSurface).toBe('projects.tasks');

        // Tasks → Board: the Board's remembered open.
        rerender({ surface: 'projects.board', fallback: 'collapsed' });
        expect(result.current.panel).toBe('open');

        // Board → Overview: back to the workspace default; nothing was written on the way.
        rerender({ surface: null, fallback: 'open' });
        expect(result.current.panel).toBe('open');
        expect(document.documentElement.dataset.drawerSurface).toBeUndefined();
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({ 'projects.board': 'open' });
    });

    it('moves across real Projects surfaces, applying each one its own default and remembered choice', () => {
        type Props = { surface: string | null; fallback: PanelState };
        const { result, rerender } = renderHook(
            ({ surface, fallback }: Props) => usePanelState('projects', fallback, surface),
            { initialProps: { surface: null, fallback: 'open' } as Props },
        );
        const overview: Props = { surface: null, fallback: 'open' };
        const board: Props = { surface: 'projects.board', fallback: 'collapsed' };
        const time: Props = { surface: 'projects.time', fallback: 'collapsed' };

        // Overview (open default) → Board (collapsed default) → Time (collapsed) → Overview.
        expect(result.current.panel).toBe('open');
        rerender(board);
        expect(result.current.panel).toBe('collapsed');
        rerender(time);
        expect(result.current.panel).toBe('collapsed');
        rerender(overview);
        expect(result.current.panel).toBe('open');
        expect(localStorage.getItem(key)).toBeNull();

        // The user opens the Board there: a surface choice, remembered for the Board only.
        rerender(board);
        act(() => result.current.open());
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({ 'projects.board': 'open' });

        rerender(time);
        expect(result.current.panel).toBe('collapsed');
        rerender(board);
        expect(result.current.panel).toBe('open');

        // A workspace-level collapse on the Overview is stored under the workspace key, and the
        // Board keeps its own choice on the way back.
        rerender(overview);
        act(() => result.current.close());
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({
            'projects.board': 'open',
            projects: 'collapsed',
        });
        rerender(board);
        expect(result.current.panel).toBe('open');
        rerender(overview);
        expect(result.current.panel).toBe('collapsed');
    });

    it('does not re-resolve between two pages that share the workspace preference', () => {
        // A write that cannot be stored leaves the in-memory choice and storage disagreeing, so a
        // spurious re-resolve (Overview → Milestones) would show as the default coming back.
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('quota', 'QuotaExceededError');
        });

        type Props = { page: string };
        const { result, rerender } = renderHook(
            ({ page }: Props) => {
                void page;

                return usePanelState('projects', 'open', null);
            },
            { initialProps: { page: 'overview' } as Props },
        );

        act(() => result.current.close());
        expect(result.current.panel).toBe('collapsed');

        rerender({ page: 'milestones' });
        expect(result.current.panel).toBe('collapsed');

        setItem.mockRestore();
    });

    it('keeps the L pin per workspace: pinning on Overview docks every Projects surface at L', () => {
        setWidthClass('l');

        type Props = { surface: string | null; fallback: PanelState };
        const overview: Props = { surface: null, fallback: 'open' };
        const board: Props = { surface: 'projects.board', fallback: 'collapsed' };
        const tasks: Props = { surface: 'projects.tasks', fallback: 'collapsed' };
        const time: Props = { surface: 'projects.time', fallback: 'collapsed' };

        // A Board choice made earlier at XL must not undo the pin either.
        localStorage.setItem(key, JSON.stringify({ 'projects.board': 'collapsed' }));

        const { result, rerender } = renderHook(
            ({ surface, fallback }: Props) => usePanelState('projects', fallback, surface),
            { initialProps: overview },
        );

        // 1-2. Overview at L, unpinned: collapsed (an overlay waits to be asked for). Pin it.
        expect(result.current.panel).toBe('collapsed');
        act(() => result.current.pin());
        expect(result.current.pinned).toBe(true);
        expect(result.current.panel).toBe('open');
        expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({
            projects: true,
        });

        // 3-5. Navigate across the surfaces: still pinned and docked on each.
        for (const page of [board, tasks, time, overview]) {
            rerender(page);
            expect(result.current.pinned, String(page.surface)).toBe(true);
            expect(result.current.panel, String(page.surface)).toBe('open');
        }

        // Pinning at L is not an XL choice: the open/collapsed map was not touched.
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({
            'projects.board': 'collapsed',
        });

        // 6-7. Collapsing the docked panel releases the pin; every surface is an overlay again.
        rerender(time);
        act(() => result.current.close());
        expect(result.current.pinned).toBe(false);
        expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({});

        for (const page of [board, tasks, overview]) {
            rerender(page);
            expect(result.current.pinned, String(page.surface)).toBe(false);
            expect(result.current.panel, String(page.surface)).toBe('collapsed');
        }
    });

    it('ignores the surface preference below XL; only the Projects-wide pin docks at L', () => {
        setWidthClass('l');
        localStorage.setItem(key, JSON.stringify({ 'projects.board': 'open' }));

        expect(resolvePanel('projects', 'collapsed', false, 'projects.board')).toBe('collapsed');
        expect(resolvePanel('projects', 'collapsed', true, 'projects.board')).toBe('open');
    });

    it('does not let a surface default or a surface choice made at XL undo the L pin', () => {
        setWidthClass('l');
        localStorage.setItem(key, JSON.stringify({ 'projects.board': 'collapsed' }));

        for (const surface of ['projects.board', 'projects.tasks', 'projects.time', null]) {
            expect(resolvePanel('projects', 'collapsed', true, surface), String(surface)).toBe(
                'open',
            );
        }
    });
});

// ── EPIC-015 WP5 owner ruling: overlay and sheet use is transient ──────────────

describe('transient drawer interaction below XL', () => {
    const seed = { projects: 'collapsed', 'projects.board': 'open' };

    function seedAtXl() {
        setWidthClass('xl');
        localStorage.setItem(key, JSON.stringify(seed));
        localStorage.setItem('shell.operational.pin', JSON.stringify({}));
    }

    it.each([
        ['L (unpinned overlay)', 'l'],
        ['M and S (sheet)', 'm'],
    ] as const)(
        'never writes the XL preference from opening and closing at %s',
        (_label, width) => {
            seedAtXl();
            setWidthClass(width);

            const { result } = renderHook(() =>
                usePanelState('projects', 'collapsed', 'projects.board'),
            );

            // Starts collapsed whatever the Board remembers; then used as an overlay in every way.
            expect(result.current.panel).toBe('collapsed');
            act(() => result.current.open());
            expect(result.current.panel).toBe('open');
            act(() => result.current.close());
            expect(result.current.panel).toBe('collapsed');
            act(() => result.current.toggle());
            act(() => result.current.toggle());

            expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual(seed);
            expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({});

            // Back at XL, the remembered Board choice is intact and applies.
            setWidthClass('xl');
            const wide = renderHook(() => usePanelState('projects', 'collapsed', 'projects.board'));

            expect(wide.result.current.panel).toBe('open');
        },
    );

    it('does not let M or S pin anything or release an existing pin', () => {
        seedAtXl();
        localStorage.setItem('shell.operational.pin', JSON.stringify({ projects: true }));
        setWidthClass('m');

        const { result } = renderHook(() => usePanelState('projects', 'open', null));

        // The pin is ignored for rendering at M…
        expect(result.current.panel).toBe('collapsed');
        act(() => result.current.open());
        act(() => result.current.close());

        // …and sheet use does not release it.
        expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({
            projects: true,
        });
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual(seed);
    });

    it('still persists an explicit choice at XL, and an explicit Pin at L', () => {
        setWidthClass('xl');
        const wide = renderHook(() => usePanelState('projects', 'collapsed', 'projects.board'));

        act(() => wide.result.current.open());
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({ 'projects.board': 'open' });
        wide.unmount();

        setWidthClass('l');
        const narrow = renderHook(() => usePanelState('projects', 'open', null));

        act(() => narrow.result.current.pin());
        expect(JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}')).toEqual({
            projects: true,
        });
        // Pinning stored no XL value of its own.
        expect(JSON.parse(localStorage.getItem(key) ?? '{}')).toEqual({ 'projects.board': 'open' });
    });
});
