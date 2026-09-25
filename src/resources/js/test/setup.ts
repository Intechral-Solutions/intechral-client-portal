import '@testing-library/jest-dom/vitest';

// jsdom does not implement `window.matchMedia`. Every real browser this board ships to does
// (WP0 verified `prefers-reduced-motion` against real Chromium), so this is a test-environment
// gap only: a default "no preference" stub so components that read it (board-dnd.tsx) don't
// crash under jsdom. A test that needs to simulate a preference overrides `window.matchMedia`
// itself with `vi.stubGlobal`, the same way `board.test.tsx` stubs `window.location`.
if (typeof window.matchMedia !== 'function') {
    window.matchMedia = (query: string): MediaQueryList =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addListener: () => {},
            removeListener: () => {},
            addEventListener: () => {},
            removeEventListener: () => {},
            dispatchEvent: () => false,
        }) as MediaQueryList;
}

afterEach(() => {
    localStorage.clear();
    document.documentElement.dataset.theme = 'light';
});
