import type { ReactNode } from 'react';

import { Strata } from '@/components/strata';

/**
 * EPIC-013 WP7 — the entity header (Direction D §6, §17).
 *
 * `PageHeader` says what this *page* is; `EntityHeader` says what this *record* is. Direction D draws
 * the difference explicitly: an operator page gets an overline, a title, a summary and actions, while
 * an entity page gets an overline, the entity name, a status line of key facts, actions, and then the
 * strata rule closing the block. This is that second grammar, and the strata is part of it rather than
 * something a page can sprinkle elsewhere — it is drawn here and nowhere else.
 *
 * **It is an identity block, not a hero.** Direction D is an operator console: the name, its status
 * and what you can do with it, in one compact band, on one line where the width allows. No oversized
 * type, no vertical padding spent on atmosphere, nothing that pushes the record's actual content
 * below the fold.
 *
 * **It knows nothing about any domain.** No project, task, ticket or customer concept appears here;
 * the caller supplies already-resolved strings and already-authorized action elements. A control the
 * viewer may not use is one the caller does not pass — this component renders what it is given and
 * makes no capability decision of its own, because it has no basis to make one.
 *
 * It also does **not** draw a breadcrumb. The shell's utility bar owns that trail (WP4/WP5) and a
 * second copy inside the page would be both a duplicate landmark and a second source of truth.
 */
export function EntityHeader({
    title,
    overline,
    status,
    meta,
    actions,
}: {
    /** The record's name. The page's one `h1`. */
    title: string;
    /** What kind of record this is, or its id — Direction D's "ID · owner org". Plain text. */
    overline?: string;
    /** The record's state, as a `Status` or `Badge` element. Never colour alone (§10). */
    status?: ReactNode;
    /** Key facts beside the status: a short, factual line. Not a description. */
    meta?: ReactNode;
    /** Already-authorized record actions. At most one ink primary (§6). */
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-col gap-3">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    {overline ? (
                        <p className="mb-0.5 font-mono text-xs tracking-wide text-text-muted uppercase">
                            {overline}
                        </p>
                    ) : null}

                    {/* The name wraps rather than truncates: a record's identity is not something to
                        hide behind an ellipsis, and `min-w-0` above keeps it from forcing the row
                        wider than the frame. */}
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <h1 className="text-xl font-semibold tracking-normal text-text">{title}</h1>
                        {status}
                    </div>

                    {meta ? <div className="mt-1 text-sm text-text-secondary">{meta}</div> : null}
                </div>

                {actions ? (
                    <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>
                ) : null}
            </div>

            {/* Direction D §17: the strata closes an entity header and appears nowhere else. */}
            <Strata />
        </header>
    );
}
