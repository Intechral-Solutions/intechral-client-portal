import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import type { TaskContext, TaskKind } from '@/types/tasks';

/**
 * How each task kind's own destination is reached (EPIC-011E §21, WP8). One explicit contract
 * per kind, not a guess from whether a URL happens to be present: a project task's detail page
 * is React (migrated in WP7), a ticket's page stays Blade until EPIC-011F, and a standalone task
 * has no destination at all (D3) — `null` there is absence, not a link ProjectPolicy denied.
 */
export const taskLinkModes: Record<TaskKind, 'inertia' | 'document'> = {
    project: 'inertia',
    ticket: 'document',
    standalone: 'document', // never reached: a standalone context.url is always null (D3)
};

type TaskContextLinkProps = {
    context: TaskContext;
    className?: string;
    children?: ReactNode;
};

/** Renders a row's context (project or ticket name, or "Standalone") as a link only when the
 * viewer's own policy allows the destination; otherwise plain text, never a dead link. */
export function TaskContextLink({ context, className, children }: TaskContextLinkProps) {
    const content = children ?? context.label;

    if (!context.url) {
        return <span className={className}>{content}</span>;
    }

    if (taskLinkModes[context.kind] === 'inertia') {
        return (
            <Link href={context.url} className={className}>
                {content}
            </Link>
        );
    }

    return (
        <a href={context.url} className={className}>
            {content}
        </a>
    );
}
