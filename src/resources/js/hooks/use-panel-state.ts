import { useCallback, useLayoutEffect, useState } from 'react';

/**
 * The Operational presentation's contextual-panel state (EPIC-013 §15, spike S1 / A1.7).
 *
 * The server supplies the safe default per workspace (`presentation.operational.panel`); the
 * remembered choice is client-owned and lives in `localStorage`. There is deliberately **no
 * cookie**: S1 measured 0 CLS in every case because `ssr: false` means nothing of the shell paints
 * before JavaScript runs, so a synchronous read during the first render is already flash-free. The
 * key is namespaced to the family so a future Focused shell neither reads nor inherits it (§15.5).
 *
 * Everything here tolerates a hostile store. Private-mode browsers throw on access, another tab can
 * write anything, and a stale map can name workspaces that no longer exist — none of which may
 * produce invalid DOM state, so every read is guarded and every value is validated against the
 * closed set before it is used.
 */
export type PanelState = 'open' | 'collapsed';

const panelKey = 'shell.operational.panel';
/**
 * The L pin is separate from the XL open/collapsed state: at L, "open" means docked only if the
 * user pinned it there, otherwise the panel opens as an overlay (Direction D §5.3 rule 2). §15.5
 * enumerated only the panel map, so this second key is a WP4 addition in the same namespace.
 */
const pinKey = 'shell.operational.pin';

function readMap(key: string): Record<string, unknown> {
    try {
        const raw = window.localStorage.getItem(key);

        if (!raw) {
            return {};
        }

        const parsed: unknown = JSON.parse(raw);

        // A JSON array, string or null all parse successfully and are all invalid here.
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
            return {};
        }

        return parsed as Record<string, unknown>;
    } catch {
        // Blocked storage, a quota error or malformed JSON: fall through to the server default
        // rather than throwing inside render.
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

/**
 * Where a choice is remembered (Direction D §5.3 rule 1, EPIC-015 WP5): under the server-named
 * **surface** key (`projects.board`) on a surface that declares its own default, otherwise under the
 * workspace key, exactly as before. Both live in the one `shell.operational.panel` map; workspace keys
 * carry no dot and surface keys always do, so the two can never collide. A surface key names a kind
 * of page, never a URL or a record, so one Board choice serves every project.
 */
export function panelPreferenceKey(
    workspace: string | null,
    surface: string | null = null,
): string | null {
    return workspace ? (surface ?? workspace) : null;
}

/** Only the two documented values are honoured; anything else is treated as absent. */
function storedPanel(preferenceKey: string | null): PanelState | null {
    if (!preferenceKey) {
        return null;
    }

    const value = readMap(panelKey)[preferenceKey];

    return value === 'open' || value === 'collapsed' ? value : null;
}

function storedPin(workspace: string | null): boolean {
    return workspace ? readMap(pinKey)[workspace] === true : false;
}

/**
 * True at the XL width class, where the remembered panel state applies (Direction D §5.2).
 *
 * Read once per resolution — at mount and when an Inertia visit changes the workspace — never from a
 * resize listener, so there is no resize race (§16). A browser that cannot answer is treated as XL,
 * which is the documented default rather than a silent collapse.
 */
function atExtraLarge(): boolean {
    try {
        return window.matchMedia('(min-width: 1360px)').matches;
    } catch {
        return true;
    }
}

/**
 * True at L and above (>= 1024px), the only widths where the panel can dock at all (the pin is hidden
 * at M and the panel is a sheet at S, Direction D §5.2). Same unanswerable-browser rule as XL.
 */
function atLarge(): boolean {
    try {
        return window.matchMedia('(min-width: 1024px)').matches;
    } catch {
        return true;
    }
}

/**
 * Resolution order: the remembered value for this workspace (or for this surface, when the server
 * names one), then the server's default, then collapsed. A workspace with no contextual panel at all
 * has no state to resolve.
 *
 * On a surface with its own default (`surface` set, `serverDefault` already the surface's), only a
 * choice made ON that surface counts: the workspace's remembered state belongs to the workspace's
 * other pages and does not override the surface default (§5.3 rule 1).
 *
 * Below XL the remembered open/collapsed state does not apply at all (§5.3 rules 2 and 3). The panel
 * starts **collapsed** whatever the default says, except at L when the user pinned it: the pin is a
 * Projects-wide (workspace) layout choice, so a pinned workspace docks on every one of its pages and
 * neither a surface default nor a surface choice made at XL can undo it (EPIC-015 WP5 owner ruling).
 * M and S ignore both the pin and the remembered state. That is a statement about initial state, not
 * geometry — geometry stays in CSS. Skipping it is not cosmetic: at L and M the panel floats, so
 * honouring a default of `open` laid a 248px overlay over the left of the canvas on first load, and
 * the browser suite caught it as clicks and drags landing on the drawer instead of the page beneath.
 */
export function resolvePanel(
    workspace: string | null,
    serverDefault: PanelState | null,
    pinned = false,
    surface: string | null = null,
): PanelState {
    if (serverDefault === null) {
        return 'collapsed';
    }

    if (atExtraLarge()) {
        return storedPanel(panelPreferenceKey(workspace, surface)) ?? serverDefault;
    }

    return pinned && atLarge() ? 'open' : 'collapsed';
}

export type PanelController = {
    panel: PanelState;
    pinned: boolean;
    toggle: () => void;
    open: () => void;
    close: () => void;
    /**
     * Docks the overlay at L and remembers it for the workspace (§5.3 rule 2). Collapsing a docked
     * panel at L is its inverse and releases the pin.
     */
    pin: () => void;
};

export function usePanelState(
    workspace: string | null,
    serverDefault: PanelState | null,
    surface: string | null = null,
): PanelController {
    const preferenceKey = panelPreferenceKey(workspace, surface);

    // Resolved during the first render, not in an effect: on an Inertia page nothing has painted
    // yet, so the first frame already carries the correct geometry (A1.7).
    const [pinned, setPinned] = useState<boolean>(() => storedPin(workspace));
    const [panel, setPanel] = useState<PanelState>(() =>
        resolvePanel(workspace, serverDefault, storedPin(workspace), surface),
    );

    // An Inertia visit can change the workspace, or the surface inside it (Overview → Board), without
    // remounting the shell, so the state is re-resolved during render of the new page rather than in
    // a post-navigate listener (A1.7 implementation requirement 1). Keyed on where the preference
    // lives, so moving between two pages that share it (Overview → Milestones) changes nothing.
    const [resolvedFor, setResolvedFor] = useState<string | null>(preferenceKey);

    if (resolvedFor !== preferenceKey) {
        const workspacePinned = storedPin(workspace);

        setResolvedFor(preferenceKey);
        setPinned(workspacePinned);
        setPanel(resolvePanel(workspace, serverDefault, workspacePinned, surface));
    }

    // The width classes are CSS-only (§16) but they read `html[data-drawer]`, and the Blade shell
    // will read the same attribute from its own pre-paint bootstrap in WP5, so both renderers share
    // one contract. This must be a LAYOUT effect: it runs after the DOM is mutated but before paint,
    // so the first painted frame already has the right number of grid columns. In a plain effect the
    // grid would paint once without the drawer column and then shift.
    useLayoutEffect(() => {
        const root = document.documentElement;

        root.dataset.drawer = panel;

        if (workspace) {
            root.dataset.workspace = workspace;
        } else {
            delete root.dataset.workspace;
        }

        // Kept in step with the server-stamped pre-paint input, so the attribute always names the
        // surface the page on screen belongs to.
        if (workspace && surface) {
            root.dataset.drawerSurface = surface;
        } else {
            delete root.dataset.drawerSurface;
        }
    }, [panel, workspace, surface]);

    const unpin = useCallback(() => {
        setPinned(false);

        if (workspace) {
            const pins = readMap(pinKey);

            delete pins[workspace];
            writeMap(pinKey, pins);
        }
    }, [workspace]);

    /**
     * Applies a user's open/collapse and decides whether it is remembered (§5.3 rules 2 and 3, EPIC-015
     * WP5 owner ruling). The remembered open/collapsed state is the **XL** preference, so only an
     * interaction at XL writes it. Below XL an open or close is an overlay or sheet being used, not a
     * layout choice: opening, Esc, outside click and following a link all stay in memory, and an XL
     * preference the user set earlier is still there when they come back. The one deliberate layout
     * choice below XL is docking, so collapsing a panel that is docked (pinned) at L releases the pin.
     */
    const persist = useCallback(
        (next: PanelState) => {
            setPanel(next);

            if (atExtraLarge()) {
                if (preferenceKey) {
                    writeMap(panelKey, { ...readMap(panelKey), [preferenceKey]: next });
                }
            } else if (next === 'collapsed' && pinned && atLarge()) {
                unpin();
            }
        },
        [preferenceKey, pinned, unpin],
    );

    return {
        panel,
        pinned,
        toggle: useCallback(
            () => persist(panel === 'open' ? 'collapsed' : 'open'),
            [panel, persist],
        ),
        open: useCallback(() => persist('open'), [persist]),
        close: useCallback(() => persist('collapsed'), [persist]),
        pin: useCallback(() => {
            setPinned(true);
            setPanel('open');

            if (workspace) {
                writeMap(pinKey, { ...readMap(pinKey), [workspace]: true });
            }

            // Pinning is a layout choice at XL too, where it is simply the open panel kept open.
            if (atExtraLarge() && preferenceKey) {
                writeMap(panelKey, { ...readMap(panelKey), [preferenceKey]: 'open' });
            }
        }, [preferenceKey, workspace]),
    };
}
