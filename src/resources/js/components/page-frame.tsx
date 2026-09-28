import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * EPIC-013 WP7 — page geometry (§19, Direction D §9).
 *
 * The shell answers *where am I*; the frame answers *how does this page's content sit in the canvas*.
 * Those are separate jobs and this component deliberately does only the second one. It owns the page
 * gutters, the vertical rhythm, the content width and — for `grid` — the optional supporting column.
 * It owns **nothing** else: no navigation, no utility bar, no breadcrumb, no timer, no authorization,
 * no route matching, no data fetching. Those belong to the shell (WP4/WP5) or to the page.
 *
 * `<main>` stays unpadded, so page padding lives here (§19.2). That is what lets a canvas page
 * reclaim the full viewport rather than inheriting a container it did not ask for.
 *
 * The three widths come straight from Direction D §9 and are not extensible on a whim:
 *
 * - `canvas`  — full width minus the page padding. Tables, boards, queues. **No max-width at all.**
 * - `grid`    — full width with a `1fr + 340px` split at XL for an optional supporting column.
 * - `reading` — a centred column at the measure its variant names (640 forms · 700 conversation ·
 *               720 account · 760 knowledge).
 *
 * The geometry itself is in `app.css`, keyed to the same width classes that own every other piece of
 * geometry in this product — one breakpoint vocabulary, no JS width query, and XL (1360px), which is
 * not a Tailwind screen, expressed in the same place as the rest. This component's job is to name
 * which geometry applies; `page-frame.test.tsx` asserts the naming, and the browser suite measures
 * the result, because a jsdom test cannot see a stylesheet.
 *
 * Additive by design: the ten pages §19.3 leaves alone keep their own `mx-auto max-w-*` containers
 * and are untouched, because nothing in `app.css` applies without `data-page-frame`.
 */

/** Direction D §9's reading measures. `account` (720px) is the default. */
export type ReadingMeasure = 'forms' | 'conversation' | 'account' | 'knowledge';

type Common = {
    children: ReactNode;
    className?: string;
};

type GridCommon = {
    width: 'grid';
    /**
     * The page header, which spans both columns as Direction D §6 draws it. Only `grid` offers the
     * slot: a single-column frame needs no help, because its first child already spans. It exists
     * because Home needs the header's rule to run the full width of the content region rather than
     * stopping at the supporting column.
     */
    header?: ReactNode;
};

/**
 * The supporting column (Direction D §9's `340–360px`) becomes a labelled `<aside>`, so it is a
 * complementary landmark rather than an unnamed region — which is why supplying `aside` requires
 * `asideLabel` at the type level rather than merely by convention. The alternative arm forbids both
 * props together, so a `grid` frame with no supporting column cannot pass a stray label either.
 */
type GridProps =
    | (GridCommon & { aside: ReactNode; asideLabel: string })
    | (GridCommon & { aside?: never; asideLabel?: never });

type PageFrameProps = Common &
    ({ width: 'canvas' } | GridProps | { width: 'reading'; measure?: ReadingMeasure });

export function PageFrame(props: PageFrameProps) {
    const { width, children, className } = props;

    // A union rather than one wide prop bag: `aside` is meaningless on a reading column and
    // `measure` is meaningless on a board, so neither is offered where it does not apply. The API
    // grows when a real consumer needs it to, not in anticipation of one.
    const header = width === 'grid' ? props.header : undefined;
    const aside = width === 'grid' ? props.aside : undefined;
    const asideLabel = width === 'grid' ? props.asideLabel : undefined;

    return (
        <div
            data-page-frame={width}
            data-page-measure={width === 'reading' ? (props.measure ?? 'account') : undefined}
            data-page-split={aside ? '' : undefined}
            className={cn(className)}
        >
            {header ? <div data-page-header>{header}</div> : null}

            {aside ? (
                <>
                    <div className="min-w-0">{children}</div>
                    <aside aria-label={asideLabel} data-page-aside className="min-w-0">
                        {aside}
                    </aside>
                </>
            ) : (
                children
            )}
        </div>
    );
}
