import { Link } from '@inertiajs/react';

import { buttonVariants } from '@/components/ui/button';
import type { Paginated } from '@/types/pagination';

type PaginationProps = {
    paginator: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'prev_page_url' | 'next_page_url'
    >;
};

/**
 * Previous / next navigation for a Laravel paginator. The URLs come from the server
 * (`->withQueryString()`), so filters survive paging. Both links are `secondary` buttons; an
 * unavailable direction is omitted (an empty cell keeps the indicator centred) rather than rendered
 * as a dead control. There are no numbered page links, so there is no current-page link to mark
 * with `aria-current`; the indicator states the position in text.
 */
export function Pagination({ paginator }: PaginationProps) {
    const { current_page, last_page, prev_page_url, next_page_url } = paginator;

    return (
        <nav aria-label="Pagination" className="flex items-center justify-between">
            {prev_page_url ? (
                <Link
                    href={prev_page_url}
                    rel="prev"
                    className={buttonVariants({ variant: 'secondary' })}
                >
                    Previous
                </Link>
            ) : (
                <span />
            )}
            <span className="text-sm text-text-secondary tabular-nums">
                Page {current_page} of {last_page}
            </span>
            {next_page_url ? (
                <Link
                    href={next_page_url}
                    rel="next"
                    className={buttonVariants({ variant: 'secondary' })}
                >
                    Next
                </Link>
            ) : (
                <span />
            )}
        </nav>
    );
}
