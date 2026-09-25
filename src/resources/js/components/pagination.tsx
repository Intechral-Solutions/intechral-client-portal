import { Link } from '@inertiajs/react';

import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/pagination';

type PaginationProps = {
    paginator: Pick<
        Paginated<unknown>,
        'current_page' | 'last_page' | 'prev_page_url' | 'next_page_url'
    >;
};

/**
 * Previous / next navigation for a Laravel paginator. The URLs come from the server
 * (`->withQueryString()`), so filters survive paging.
 */
export function Pagination({ paginator }: PaginationProps) {
    const { current_page, last_page, prev_page_url, next_page_url } = paginator;

    return (
        <nav aria-label="Pagination" className="flex items-center justify-between">
            {prev_page_url ? (
                <Link href={prev_page_url} className={cn(buttonVariants({ variant: 'outline' }))}>
                    Previous
                </Link>
            ) : (
                <span />
            )}
            <span className="text-sm text-muted-foreground">
                Page {current_page} of {last_page}
            </span>
            {next_page_url ? (
                <Link href={next_page_url} className={cn(buttonVariants({ variant: 'outline' }))}>
                    Next
                </Link>
            ) : (
                <span />
            )}
        </nav>
    );
}
