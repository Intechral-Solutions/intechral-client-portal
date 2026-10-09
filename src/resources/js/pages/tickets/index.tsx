import { Head, Link, router } from '@inertiajs/react';
import { LifeBuoy, Plus } from 'lucide-react';
import type { ReactElement } from 'react';

import { TicketPriority, TicketStatus } from '@/components/helpdesk/ticket-status';
import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { AppShell } from '@/components/shell/app-shell';
import { Button, buttonVariants } from '@/components/ui/button';
import { FilterChip } from '@/components/ui/chip';
import { EmptyState } from '@/components/ui/empty-state';
import { FilterBar, FilterField, FilterSearch } from '@/components/ui/filter-bar';
import { NativeSelect } from '@/components/ui/native-select';
import { create, index } from '@/routes/tickets';
import type {
    RequesterTicketFilters,
    RequesterTicketPage,
    RequesterTicketRow,
    SelectOption,
    TicketStatusValue,
} from '@/types/helpdesk';

/**
 * The requester's own requests (EPIC-017 §19.1). A plain two-line list, not a table: customers do not
 * get dense data grids (Direction D §7).
 *
 * It renders from `filters`, the values the SERVER normalized (`RequesterTicketQuery::filters`), and
 * never from the address bar: `?search[]=x` reaches this page as an empty search, not as a value to
 * echo. Every visit, page link included, carries only those normalized values.
 *
 * Three different empty pages are three different facts, and an empty PAGE proves none of them:
 *  1. the requester has raised nothing (`hasRequests` is false);
 *  2. they have requests, but none match the filters (no results at all);
 *  3. they have matching requests, but this page is past the last one (results exist, the page is empty).
 */
export type TicketsIndexProps = {
    tickets: RequesterTicketPage;
    filters: RequesterTicketFilters;
    statusOptions: SelectOption<TicketStatusValue>[];
    hasRequests: boolean;
    canCreate: boolean;
};

function countText(total: number, filtered: boolean) {
    if (filtered) return total === 1 ? '1 request matches' : `${total} requests match`;

    return total === 1 ? '1 request' : `${total} requests`;
}

function NewRequestLink({ variant }: { variant?: 'secondary' }) {
    return (
        <Link href={create.url()} className={buttonVariants({ variant })}>
            <Plus aria-hidden="true" />
            New request
        </Link>
    );
}

function RequestRow({ ticket }: { ticket: RequesterTicketRow }) {
    return (
        <li className="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
            <div className="min-w-0">
                <Link
                    href={ticket.href}
                    className="font-medium break-words text-text hover:underline"
                >
                    {ticket.title}
                </Link>
                <p className="mt-1 text-sm text-text-secondary">
                    <span className="font-mono">{ticket.number}</span>
                    <span aria-hidden="true"> · </span>
                    <span className="sr-only">Opened </span>
                    <time dateTime={ticket.createdAt}>{ticket.createdAtHuman}</time>
                </p>
            </div>
            <div className="flex shrink-0 flex-wrap items-center gap-x-4 gap-y-1">
                <TicketStatus status={ticket.status} label={ticket.statusLabel} />
                <TicketPriority priority={ticket.priority} />
            </div>
        </li>
    );
}

export function TicketsIndexPage({
    tickets,
    filters,
    statusOptions,
    hasRequests,
    canCreate,
}: TicketsIndexProps) {
    const statusLabel = statusOptions.find((option) => option.value === filters.status)?.label;
    const filtered = Boolean(filters.search || filters.status);

    // Always built from the two normalized values, so a visit can never carry anything else.
    function visit(next: RequesterTicketFilters) {
        const query: Record<string, string> = {};
        if (next.search) query.search = next.search;
        if (next.status) query.status = next.status;

        router.get(index.url(), query, { preserveState: true, preserveScroll: true });
    }

    let empty: ReactElement | null = null;

    if (tickets.data.length === 0) {
        if (tickets.total > 0) {
            empty = (
                <EmptyState
                    icon={LifeBuoy}
                    title="There are no requests on this page"
                    description="This page is past the last of your matching requests."
                    action={
                        <Link
                            href={tickets.first_page_url}
                            className={buttonVariants({ variant: 'secondary' })}
                        >
                            Go to the first page
                        </Link>
                    }
                />
            );
        } else if (hasRequests) {
            empty = (
                <EmptyState
                    icon={LifeBuoy}
                    title="No requests match these filters."
                    description="Try a different search or status, or show all of your requests."
                    action={
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => visit({ search: null, status: null })}
                        >
                            Clear filters
                        </Button>
                    }
                />
            );
        } else {
            empty = (
                <EmptyState
                    icon={LifeBuoy}
                    title="You haven't raised any requests yet."
                    description="When you ask for help, your request and our replies will appear here."
                    action={canCreate ? <NewRequestLink variant="secondary" /> : undefined}
                />
            );
        }
    }

    const chips = [
        filters.search ? (
            <FilterChip
                key="search"
                label={`Search: ${filters.search}`}
                onRemove={() => visit({ ...filters, search: null })}
            />
        ) : null,
        statusLabel ? (
            <FilterChip
                key="status"
                label={`Status: ${statusLabel}`}
                onRemove={() => visit({ ...filters, status: null })}
            />
        ) : null,
    ].filter((chip): chip is ReactElement => chip !== null);

    return (
        <>
            <Head title="Your requests" />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <PageHeader
                    overline="Support"
                    title="Your requests"
                    description="Everything you have asked us for help with, and where it stands."
                    actions={canCreate ? <NewRequestLink /> : undefined}
                />

                {hasRequests || filtered ? (
                    <FilterBar
                        label="Filter requests"
                        chips={chips}
                        onClear={() => visit({ search: null, status: null })}
                    >
                        <FilterSearch
                            label="Search requests"
                            value={filters.search ?? ''}
                            maxLength={200}
                            onSearch={(search) => visit({ ...filters, search: search || null })}
                        />
                        <FilterField label="Status">
                            <NativeSelect
                                className="w-full"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    visit({
                                        ...filters,
                                        status: (event.target.value ||
                                            null) as TicketStatusValue | null,
                                    })
                                }
                            >
                                <option value="">All statuses</option>
                                {statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FilterField>
                    </FilterBar>
                ) : null}

                {empty ?? (
                    <>
                        <p className="text-sm text-text-secondary" aria-live="polite">
                            {countText(tickets.total, filtered)}
                        </p>
                        <ul
                            aria-label="Your requests"
                            className="divide-y divide-rule border-y border-rule"
                        >
                            {tickets.data.map((ticket) => (
                                <RequestRow key={ticket.id} ticket={ticket} />
                            ))}
                        </ul>
                    </>
                )}

                {tickets.last_page > 1 ? <Pagination paginator={tickets} /> : null}
            </PageFrame>
        </>
    );
}

TicketsIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default TicketsIndexPage;
