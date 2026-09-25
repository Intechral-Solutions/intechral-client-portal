import type { ReactNode } from 'react';

type PageHeaderProps = {
    title: string;
    /** Short context line above the title (a section or record type). Plain text. */
    overline?: string;
    description?: string;
    /** Page-level actions. One `primary` (ink) button at most; everything else secondary or ghost. */
    actions?: ReactNode;
};

/**
 * Page title block (EPIC-013 WP2): optional overline, the page's one `h1`, an optional summary and
 * an actions slot. The heading stays a single `h1` so the future focus-on-navigation policy (WP4)
 * has one predictable target. This is not the entity header (strata, breadcrumb, status): that
 * belongs to the epics that own entity pages.
 */
export function PageHeader({ title, overline, description, actions }: PageHeaderProps) {
    return (
        <header className="flex flex-col justify-between gap-4 border-b border-rule pb-6 sm:flex-row sm:items-end">
            <div className="min-w-0">
                {overline ? (
                    <p className="mb-1 font-mono text-xs tracking-wide text-text-muted uppercase">
                        {overline}
                    </p>
                ) : null}
                <h1 className="text-2xl font-semibold tracking-normal text-text">{title}</h1>
                {description ? (
                    <p className="mt-1 max-w-2xl text-sm text-text-secondary">{description}</p>
                ) : null}
            </div>
            {actions ? (
                <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
            ) : null}
        </header>
    );
}
