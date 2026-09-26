import { ChevronRight } from 'lucide-react';

import type { Workspace } from '@/types';

import { ShellLink } from './shell-link';
import { ViewSwitcher } from './view-switcher';

/**
 * The utility bar's breadcrumb (Direction D §8).
 *
 * It is always present and always ends with the current page. Its trail is the server's truth: the
 * active workspace, then the active contextual item. Direction D §13.2 also allows a page-supplied
 * trail segment; no page supplies one yet (page bodies are untouched in WP4), so the trail ends at
 * the active view — which is the current page — rather than inventing a segment.
 *
 * While the drawer is collapsed the final view segment becomes the `ViewSwitcher`, so the current
 * view stays both visible and switchable without the panel (§5.4).
 */
export function Breadcrumb({
    workspace,
    collapsed,
}: {
    workspace: Workspace | null;
    collapsed: boolean;
}) {
    if (!workspace) {
        return <p className="truncate text-sm text-text-muted">Intechral</p>;
    }

    const active =
        workspace.context
            .filter((section) => section.kind !== 'actions')
            .flatMap((section) => section.items)
            .find((item) => item.isActive) ?? null;

    return (
        <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1 text-sm">
            <ShellLink
                href={workspace.href}
                visit={workspace.visit}
                className="truncate rounded-control px-1 py-0.5 text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                aria-current={active ? undefined : 'page'}
            >
                {workspace.label}
            </ShellLink>

            {active ? (
                <>
                    <ChevronRight
                        className="h-3.5 w-3.5 shrink-0 text-text-faint"
                        aria-hidden="true"
                    />
                    {collapsed ? (
                        <ViewSwitcher workspace={workspace} active={active} />
                    ) : (
                        <span className="truncate px-1 font-medium text-text" aria-current="page">
                            {active.label}
                        </span>
                    )}
                </>
            ) : null}
        </nav>
    );
}
