import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';

/**
 * EPIC-015 WP2 — routed page tabs (EPIC-015 §11.4, Direction D §6 and §8).
 *
 * Each tab is a **separate page**, so this is page navigation, not an ARIA tab widget: a labelled
 * `nav` of ordinary links, with `aria-current="page"` on the page you are on. No `role="tablist"`,
 * `role="tab"` or `aria-selected` — those describe panels swapped within one page, and promise arrow-key
 * behaviour a set of page links does not have. This is the recorded EPIC-015 deviation from Direction D
 * §8's tab wording (P3); the visual treatment is §8's: a 2px ink underline and 600 weight on the current
 * tab, sitting directly on the entity header's strata.
 *
 * Domain-free: the caller supplies already-resolved links, and offers only destinations that exist and
 * that the viewer may open (navigation is presentation, never authorization, INV-P14). At narrow widths
 * the strip scrolls horizontally rather than wrapping (§11.4).
 */

export type PageTab = { key: string; label: string; href: string };

export function PageTabs({
    label,
    tabs,
    current,
    className,
}: {
    /** The navigation landmark's accessible name, e.g. "Project". */
    label: string;
    tabs: PageTab[];
    /** The `key` of the page being viewed. */
    current: string;
    className?: string;
}) {
    return (
        <nav aria-label={label} className={cn('-mb-px overflow-x-auto', className)}>
            <ul role="list" className="flex gap-5 whitespace-nowrap">
                {tabs.map((tab) => {
                    const active = tab.key === current;

                    return (
                        <li key={tab.key} className="shrink-0">
                            <Link
                                href={tab.href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-9 items-center border-b-2 px-0.5 text-sm transition-colors duration-motion-fast',
                                    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus',
                                    active
                                        ? 'border-text font-semibold text-text'
                                        : 'border-transparent font-medium text-text-secondary hover:text-text',
                                )}
                            >
                                {tab.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
