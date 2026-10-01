import type { Navigation, ShellProps } from './navigation';
import type { TaskBulkResult } from './tasks';

/** Initials are derived server-side so both renderers agree; `url` is null (no photo storage). */
export type AuthAvatar = {
    initials: string;
    url: string | null;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    avatar: AuthAvatar;
};

export type AuthProps = {
    user: AuthUser | null;
    permissions: string[];
};

export type FlashProps = {
    success: string | null;
    error: string | null;
    status: string | null;
    warning: string | null;
    /** The per-task outcome of a bulk Complete/Reopen (EPIC-014 §15.2), present for one response. */
    bulk?: TaskBulkResult | null;
};

export type SharedPageProps = {
    app: {
        name: string;
    };
    auth: AuthProps;
    shell: ShellProps;
    /**
     * The canonical navigation contract, and the shell's only navigation input.
     *
     * The same payload reaches the Blade shell through `ShellComposer`. WP3's temporary flattened
     * compatibility shape is gone from both renderers (WP4 on React, WP5 on Blade).
     */
    navigation: Navigation;
    flash: FlashProps;
};
