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

/** Only the two documented values are honoured; anything else is treated as absent. */
function storedPanel(workspace: string | null): PanelState | null {
    if (!workspace) {
        return null;
    }

    const value = readMap(panelKey)[workspace];

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
 * Resolution order: the remembered value for this workspace, then the server's default, then
 * collapsed. A workspace with no contextual panel at all has no state to resolve.
 *
 * Below XL the panel starts **collapsed** whatever the default says, unless the user pinned it at L
 * (§5.2: "drawer collapsed by default, opens as an overlay; pin it to dock"). That is a statement
 * about initial state, not geometry — geometry stays in CSS. Skipping it is not cosmetic: at L and M
 * the panel floats, so honouring a default of `open` laid a 248px overlay over the left of the canvas
 * on first load, and the browser suite caught it as clicks and drags landing on the drawer instead of
 * the page beneath it.
 */
export function resolvePanel(
    workspace: string | null,
    serverDefault: PanelState | null,
    pinned = false,
): PanelState {
    if (serverDefault === null) {
        return 'collapsed';
    }

    if (!pinned && !atExtraLarge()) {
        return 'collapsed';
    }

    return storedPanel(workspace) ?? serverDefault;
}

export type PanelController = {
    panel: PanelState;
    pinned: boolean;
    toggle: () => void;
    open: () => void;
    close: () => void;
    /** Docks the overlay at L and remembers it there (§5.3 rule 2). */
    pin: () => void;
};

export function usePanelState(
    workspace: string | null,
    serverDefault: PanelState | null,
): PanelController {
    // Resolved during the first render, not in an effect: on an Inertia page nothing has painted
    // yet, so the first frame already carries the correct geometry (A1.7).
    const [pinned, setPinned] = useState<boolean>(() => storedPin(workspace));
    const [panel, setPanel] = useState<PanelState>(() =>
        resolvePanel(workspace, serverDefault, storedPin(workspace)),
    );

    // An Inertia visit can change the workspace without remounting the shell, so the state is
    // re-resolved during render of the new page rather than in a post-navigate listener (A1.7
    // implementation requirement 1).
    const [resolvedFor, setResolvedFor] = useState<string | null>(workspace);

    if (resolvedFor !== workspace) {
        const workspacePinned = storedPin(workspace);

        setResolvedFor(workspace);
        setPinned(workspacePinned);
        setPanel(resolvePanel(workspace, serverDefault, workspacePinned));
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
    }, [panel, workspace]);

    const persist = useCallback(
        (next: PanelState) => {
            setPanel(next);

            if (workspace) {
                writeMap(panelKey, { ...readMap(panelKey), [workspace]: next });
            }
        },
        [workspace],
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
            persist('open');

            if (workspace) {
                writeMap(pinKey, { ...readMap(pinKey), [workspace]: true });
            }
        }, [persist, workspace]),
    };
}
