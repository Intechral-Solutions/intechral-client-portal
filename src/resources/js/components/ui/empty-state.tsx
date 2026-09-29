import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * EPIC-013 WP7 — the empty state (§21.2).
 *
 * WP2 deferred this deliberately (A6.10): three ad hoc one-line empties did not justify a component,
 * and it named **Home** as the first structured consumer. Home is that consumer now, so this is the
 * primitive arriving with its consumer rather than ahead of one.
 *
 * §21.2 asks for "real empty copy distinguishing truly-empty from filtered-empty", which is a writing
 * requirement more than a rendering one: the component supplies the shape, and the caller supplies
 * copy that says which kind of empty this is. "No tickets yet" and "No tickets match this filter" are
 * different facts and lead to different next actions, so the component takes both a title and an
 * optional description rather than inventing a single generic sentence.
 *
 * Structure over boxes (L10): a rule-bounded band, not a card. It is a grouping, not a landmark, and
 * its icon is decorative — the title carries the meaning.
 *
 * Direction D §15.2: "Left-aligned inside the region, not centred illustrations." The icon, where
 * supplied, is the "optional small line glyph" §15.2 allows — a marker beside the copy, not an
 * illustration above it — so it sits inline to the left rather than centred over the title.
 */
export function EmptyState({
    title,
    description,
    icon: Icon,
    action,
    className,
}: {
    title: string;
    /** What would change this, or what kind of empty this is. Optional but usually worth saying. */
    description?: string;
    icon?: LucideIcon;
    /** One optional next step, as an already-authorized button or link. */
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('border-y border-rule px-4 py-10', className)}>
            <div className="flex items-start gap-3">
                {Icon ? (
                    <Icon className="mt-0.5 size-5 shrink-0 text-text-muted" aria-hidden="true" />
                ) : null}
                <div>
                    <p className="text-sm font-medium text-text">{title}</p>
                    {description ? (
                        <p className="mt-1 max-w-prose text-sm text-text-secondary">{description}</p>
                    ) : null}
                    {action ? <div className="mt-4">{action}</div> : null}
                </div>
            </div>
        </div>
    );
}
