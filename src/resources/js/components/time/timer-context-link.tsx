import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import type { ContextKind, TimerContext } from '@/types/time';

type LinkMode = 'inertia' | 'document';

/**
 * How each context destination is reached. Inertia `Link` may only point at a page that is
 * already a React page; pointing it at a Blade route would make Inertia show its error modal
 * (EPIC-011E §21). Flip an entry in the work package that migrates that destination:
 * `project` (the board) flipped in WP5, `task` (the task detail page) in WP7. `ticket` stays
 * `document` until EPIC-011F.
 */
export const contextLinkModes: Record<ContextKind, LinkMode> = {
    project: 'inertia',
    task: 'inertia',
    ticket: 'document',
};

type TimerContextLinkProps = {
    /** Timer DTOs say `'Project'`; time entries say `'project'`. Both are accepted. */
    kind: ContextKind | TimerContext['type'];
    /** `null` when the viewer may not open the destination: rendered as plain text. */
    url: string | null;
    label: string;
    className?: string;
    children?: ReactNode;
};

export function TimerContextLink({ kind, url, label, className, children }: TimerContextLinkProps) {
    const content = children ?? label;

    if (!url) {
        return (
            <span className={className} title={label}>
                {content}
            </span>
        );
    }

    if (contextLinkModes[kind.toLowerCase() as ContextKind] === 'inertia') {
        return (
            <Link href={url} className={className} title={label}>
                {content}
            </Link>
        );
    }

    return (
        <a href={url} className={className} title={label}>
            {content}
        </a>
    );
}
