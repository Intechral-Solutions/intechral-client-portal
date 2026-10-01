import { ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils';
import type { Workspace } from '@/types';

import { ShellLink } from './shell-link';
import { ViewSwitcher } from './view-switcher';

/**
 * One page-supplied segment of the trail (Direction D §13.2). A segment with an `href` is a link (an
 * Inertia visit: a page only ever links to another page of this application); the last one is the
 * current page and has none.
 */
export type BreadcrumbSegment = { label: string; href?: string };

const linkClass =
    'truncate rounded-control px-1 py-0.5 text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

/**
 * The utility bar's breadcrumb (Direction D §8).
 *
 * It is always present and always ends with the current page, and it is the **only** breadcrumb
 * landmark in the application: a page never draws one (A13.12). Its trail is the server's truth first:
 * the active workspace, then the active contextual item. A record page then extends it with a
 * page-supplied `trail` (Direction D §13.2): the segments between the active view and the record, ending
 * on the record itself, which is `aria-current="page"`. Without a `trail` the trail ends at the active
 * view, which is the current page.
 *
 * While the drawer is collapsed the active view segment becomes the `ViewSwitcher`, so the current view
 * stays both visible and switchable without the panel (§5.4); with a trail it still is, and the trail
 * follows it. With the drawer open the view is a link back to it.
 *
 * At S the 48px bar also carries the timer pill, so four segments would each be truncated to nothing. A
 * page that supplies a trail therefore shows its **parent and the current page** there: the workspace is
 * hidden, and the view too once the trail has a parent of its own (a project above a task). They are
 * only hidden visually at S (`display: none`, so absent from the accessibility tree there too, which
 * leaves a valid shorter trail ending on the current page); every segment is present from M up, and a
 * long record name always truncates rather than wrapping the bar.
 */
export function Breadcrumb({
    workspace,
    collapsed,
    trail = [],
}: {
    workspace: Workspace | null;
    collapsed: boolean;
    trail?: BreadcrumbSegment[];
}) {
    if (!workspace) {
        return <p className="truncate text-sm text-text-muted">Intechral</p>;
    }

    const active =
        workspace.context
            .filter((section) => section.kind !== 'actions')
            .flatMap((section) => section.items)
            .find((item) => item.isActive) ?? null;
    // The page is current only when nothing follows the view it is on.
    const viewIsCurrent = trail.length === 0;
    // Which leading segments are hidden at S, so a separator is never left dangling at the front.
    const workspaceHiddenAtS = trail.length > 0;
    const viewHiddenAtS = trail.length > 1;
    const firstTrailSeparatorHidden = workspaceHiddenAtS && (active === null || viewHiddenAtS);

    return (
        <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1 text-sm">
            <ShellLink
                href={workspace.href}
                visit={workspace.visit}
                className={cn(linkClass, workspaceHiddenAtS && 'max-md:hidden')}
                aria-current={active || !viewIsCurrent ? undefined : 'page'}
            >
                {workspace.label}
            </ShellLink>

            {active ? (
                <>
                    {separator(workspaceHiddenAtS)}
                    <span
                        className={cn(
                            'flex min-w-0 items-center',
                            viewHiddenAtS && 'max-md:hidden',
                        )}
                    >
                        {collapsed ? (
                            <ViewSwitcher workspace={workspace} active={active} />
                        ) : viewIsCurrent ? (
                            <span
                                className="truncate px-1 font-medium text-text"
                                aria-current="page"
                            >
                                {active.label}
                            </span>
                        ) : (
                            <ShellLink
                                href={active.href}
                                visit={active.visit}
                                className={linkClass}
                            >
                                {active.label}
                            </ShellLink>
                        )}
                    </span>
                </>
            ) : null}

            {trail.map((segment, index) => {
                const last = index === trail.length - 1;

                return (
                    <span
                        key={`${index}-${segment.label}`}
                        className={cn(
                            'flex items-center gap-1',
                            last ? 'min-w-0 flex-1' : 'min-w-0 max-md:max-w-[40%]',
                        )}
                    >
                        {separator(index === 0 && firstTrailSeparatorHidden)}
                        {last || !segment.href ? (
                            <span
                                className="truncate px-1 font-medium text-text"
                                aria-current={last ? 'page' : undefined}
                            >
                                {segment.label}
                            </span>
                        ) : (
                            <ShellLink href={segment.href} visit="inertia" className={linkClass}>
                                {segment.label}
                            </ShellLink>
                        )}
                    </span>
                );
            })}
        </nav>
    );
}

function separator(hiddenAtS: boolean) {
    return (
        <ChevronRight
            className={cn('h-3.5 w-3.5 shrink-0 text-text-faint', hiddenAtS && 'max-md:hidden')}
            aria-hidden="true"
        />
    );
}
