import type { LegacyNavigationGroup } from './navigation-legacy';
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
    /** The canonical WP3 contract. New shell code reads this. */
    navigation: Navigation;
    /** TEMPORARY: the pre-WP4 shell's flat shape. Removed in WP4 with `app-layout.tsx`'s header. */
    navigationLegacy: LegacyNavigationGroup[];
    flash: FlashProps;
};
