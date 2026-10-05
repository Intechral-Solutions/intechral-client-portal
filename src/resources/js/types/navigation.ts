/**
 * The canonical WP3 navigation contract (EPIC-013 §12.2). The server is authoritative for every
 * field here: the client renders what it is given and derives no visibility, destination, active
 * state or presentation default of its own (L14).
 *
 * The model is deliberately split. `context` is CONTENT — the authorized contextual navigation, read
 * by every presentation. `presentation` is HINTS — family-namespaced defaults, read only by the
 * matching shell. A hint may never add, remove, gate or reorder a destination, so dropping the whole
 * `presentation` key must leave the authorized model intact (§12.3 rule 4).
 */

/** Stable icon key, mapped to a `lucide-react` component in React and an SVG partial in Blade. */
export type NavigationIcon = string;

/** Whether a destination is reachable through an Inertia visit or needs a full document load. */
export type VisitMode = 'inertia' | 'document';

/**
 * The semantic projection hint on a contextual section. Operational renders `views` as drawer rows
 * and `actions` as a drawer action; a later Focused presentation renders the same data as a view
 * selector and a top-bar button. An unknown value must render as a plain section, never throw.
 */
export type ContextKind = 'views' | 'queues' | 'entities' | 'saved' | 'actions';

export type ContextItem = {
    key: string;
    label: string;
    href: string;
    visit: VisitMode;
    isActive: boolean;
    /** Reserved (§12.3 rule 8). Always null: no endpoint provides counts in this epic. */
    count: number | null;
};

export type ContextSection = {
    key: string;
    /** Null where the section needs no heading — a bare action group, for instance. */
    label: string | null;
    kind: ContextKind;
    items: ContextItem[];
};

/**
 * Family-namespaced presentation hints. A shell reads its own family and ignores every other key, so
 * adding `focused` later is additive.
 */
export type WorkspacePresentation = {
    operational?: {
        /**
         * The contextual-panel default; null when the workspace has no contextual panel at all. On a
         * surface with its own default (below) it is that surface's default.
         */
        panel: 'open' | 'collapsed' | null;
        /**
         * Direction D §5.3 (EPIC-015 WP5): the stable semantic key of the current page when it is a
         * surface with its own panel default (`projects.board`), on the active workspace only; null
         * everywhere else. A choice made there is remembered under this key. Optional so that a
         * payload without it reads as "no surface".
         */
        surface?: string | null;
    };
};

export type Workspace = {
    key: string;
    label: string;
    icon: NavigationIcon;
    href: string;
    visit: VisitMode;
    isActive: boolean;
    /** Empty means the workspace has no contextual navigation, for any presentation (rule 6). */
    context: ContextSection[];
    presentation: WorkspacePresentation;
};

export type Navigation = {
    /** The active workspace's key, or null when the route belongs to no workspace. */
    currentWorkspace: string | null;
    workspaces: Workspace[];
};

/** The presentation family the shell should render (§12.5). Names a presentation, never a role. */
export type ShellProps = {
    presentation: 'operational';
};
