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
import { create, index } from '@/routes/support';
import type {
    RequesterTicketFilters,
    SelectOption,
    SupportTicketPage,
    SupportTicketRow,
    TicketStatusValue,
} from '@/types/helpdesk';

/**
 * The requests a partner support user may read and reply to (Release 1.0, R1-4). The server builds the list from the operator-issued grant and the
 * customers the partner manages RIGHT NOW (`PartnerSupportQuery`); nothing here chooses an organization. Like the requester's list it renders from
 * the filters the SERVER normalized and carries only those on every visit.
 */
export type SupportIndexProps = {
    tickets: SupportTicketPage;
    filters: RequesterTicketFilters;
    statusOptions: SelectOption<TicketStatusValue>[];
};

function RequestRow({ ticket }: { ticket: SupportTicketRow }) {
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
                    <span className="sr-only">Organization: </span>
                    {ticket.organization}
                    <span aria-hidden="true"> · </span>
                    <span className="sr-only">Requested by </span>
                    {ticket.requester}
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

export function SupportIndexPage({ tickets, filters, statusOptions }: SupportIndexProps) {
    const statusLabel = statusOptions.find((option) => option.value === filters.status)?.label;
    const filtered = Boolean(filters.search || filters.status);

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
                    description="This page is past the last of the matching requests."
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
        } else if (filtered) {
            empty = (
                <EmptyState
                    icon={LifeBuoy}
                    title="No requests match these filters."
                    description="Try a different search or status, or show every supported request."
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
                    title="There are no supported requests yet."
                    description="Requests raised by the organizations you support will appear here."
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
            <Head title="Supported requests" />
            <PageFrame width="canvas" className="flex flex-col gap-5">
                <PageHeader
                    overline="Support"
                    title="Supported requests"
                    description="Requests from the organizations you support. You can read each conversation and reply to it."
                    actions={
                        <Link href={create.url()} className={buttonVariants()}>
                            <Plus aria-hidden="true" />
                            New request
                        </Link>
                    }
                />

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

                {empty ?? (
                    <>
                        <p className="text-sm text-text-secondary" aria-live="polite">
                            {tickets.total === 1 ? '1 request' : `${tickets.total} requests`}
                        </p>
                        <ul
                            aria-label="Supported requests"
                            className="divide-y divide-rule border-y border-rule"
                        >
                            {tickets.data.map((ticket) => (
                                <RequestRow key={ticket.number} ticket={ticket} />
                            ))}
                        </ul>
                    </>
                )}

                {tickets.last_page > 1 ? <Pagination paginator={tickets} /> : null}
            </PageFrame>
        </>
    );
}

SupportIndexPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default SupportIndexPage;
