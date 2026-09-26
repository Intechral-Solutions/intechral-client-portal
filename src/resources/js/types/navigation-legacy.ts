/**
 * TEMPORARY compatibility shape for the pre-WP4 shell. Deleted with it.
 *
 * `app-layout.tsx`'s sticky header has no drawer to project a workspace's `context` into, so the
 * server also sends the canonical model flattened into the two buckets it already renders (see
 * `App\Shared\Navigation\LegacyShellNavigation`). This is not the direction of travel: new shell code
 * reads `Navigation` from `./navigation` instead, and WP4 removes this file, the `navigationLegacy`
 * prop and `NavigationLink` together.
 */
import type { VisitMode } from './navigation';

export type LegacyNavigationItem = {
    key: string;
    label: string;
    href: string;
    visit: VisitMode;
    isActive: boolean;
};

export type LegacyNavigationGroup = {
    /**
     * `primary` is one entry per workspace. `overflow` is the contextual destinations a flat shell
     * cannot otherwise reach — not the retired "Manage" group: it carries no label and is not
     * audience-scoped.
     */
    key: 'primary' | 'overflow';
    label: null;
    items: LegacyNavigationItem[];
};
