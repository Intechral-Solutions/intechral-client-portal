import type { Navigation, ShellProps } from './navigation';

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
     * WP3's temporary `navigationLegacy` companion prop is gone from the Inertia payload: the
     * Direction D shell projects `context` into the drawer, so nothing on the React side needs the
     * flattened shape. `ShellComposer` still supplies it to `layouts/partials/nav.blade.php`, which
     * WP5 replaces.
     */
    navigation: Navigation;
    flash: FlashProps;
};
