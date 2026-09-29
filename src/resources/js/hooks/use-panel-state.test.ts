import { act } from '@testing-library/react';
import { renderHook } from '@testing-library/react';

import { setWidthClass } from '@/components/shell/shell-fixtures';

import { resolvePanel, usePanelState } from './use-panel-state';
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
    setWidthClass('below-xl');
    localStorage.setItem(key, JSON.stringify({ projects: 'open' }));

    // At L and M the panel floats, so an unbidden `open` would lay a 248px overlay over the left of
    // the canvas on load. Direction D §5.2: collapsed by default below XL; the user opens an overlay.
    expect(resolvePanel('projects', 'open')).toBe('collapsed');
    expect(resolvePanel('projects', 'collapsed')).toBe('collapsed');
});

it('honours a pin below XL, which is the one way the panel docks at L', () => {
    setWidthClass('below-xl');

    expect(resolvePanel('projects', 'open', true)).toBe('open');
});

it('treats a browser that cannot answer the width query as XL', () => {
    // A silent collapse would be the worse failure: the documented default is the wide layout.
    vi.stubGlobal('matchMedia', () => {
        throw new Error('unavailable');
    });

    expect(resolvePanel('projects', 'open')).toBe('open');

    vi.unstubAllGlobals();
});
