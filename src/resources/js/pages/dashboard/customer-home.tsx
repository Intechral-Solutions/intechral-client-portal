import { Head, Link } from '@inertiajs/react';
import { FileText, FolderKanban, Hourglass, LifeBuoy } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { buttonVariants } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Status } from '@/components/ui/status';

/**
 * The Focused customer Home (EPIC-017 §5): a calm page, in the reading order Direction D §7 gives a
 * customer — what is waiting on them, their open requests, their invoices, their projects.
 *
 * Every section is optional. The server sends `null` for the sections whose capability the actor lacks
 * (no `tickets.view`, no `billing.view`, no `projects.view`), and the page draws nothing for them: it
 * has no way to fetch, infer or show a section it was not given. It carries no operator vocabulary —
 * no SLA, queue, requester or priority — because the server never sends any.
 *
 * Chrome-agnostic like every page: it is laid out by `AppShell` alone and reads no presentation.
 */

type VisitMode = 'inertia' | 'document';

type Destination = { href: string; visit: VisitMode };

type RequestRow = {
    id: number;
    number: string;
    title: string;
    createdAt: string;
    createdAtHuman: string;
    href: string;
    visit: VisitMode;
};

type OpenRequestRow = RequestRow & { status: string; statusLabel: string };

export type CustomerHomeProps = {
    waitingOnYou: RequestRow[] | null;
    openRequests: OpenRequestRow[] | null;
    invoices: (Destination & { outstanding: number }) | null;
    projects: (Destination & { active: number }) | null;
    support: Destination | null;
    newRequest: Destination | null;
};

/** The server decides how a destination navigates; the page honours it rather than guessing. */
function DestinationLink({
    destination,
    className,
    children,
}: {
    destination: Destination;
    className?: string;
    children: ReactNode;
}) {
    if (destination.visit === 'inertia') {
        return (
            <Link href={destination.href} className={className}>
                {children}
            </Link>
        );
    }

    return (
        <a href={destination.href} className={className}>
            {children}
        </a>
    );
}

const quietLink =
    'rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus';

function RequestList({ rows }: { rows: (RequestRow | OpenRequestRow)[] }) {
    return (
        <ul className="divide-y divide-rule border-b border-rule">
            {rows.map((row) => (
                <li key={row.id}>
                    <a
                        href={row.href}
                        className="flex items-center justify-between gap-4 px-1 py-3 transition-colors duration-motion-fast hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
                    >
                        <div className="min-w-0">
                            <p className="truncate text-sm font-medium text-text">{row.title}</p>
                            <p className="mt-0.5 truncate text-xs text-text-muted">
                                {row.number} ·{' '}
                                <time dateTime={row.createdAt}>{row.createdAtHuman}</time>
                            </p>
                        </div>
                        {'statusLabel' in row ? (
                            <Status tone="info">{row.statusLabel}</Status>
                        ) : null}
                    </a>
                </li>
            ))}
        </ul>
    );
}

function CustomerHomePage({
    waitingOnYou,
    openRequests,
    invoices,
    projects,
    support,
    newRequest,
}: CustomerHomeProps) {
    return (
        <>
            <Head title="Home" />

            <PageFrame width="canvas">
                <PageHeader
                    title="Home"
                    description="Your requests, invoices and projects, in one place."
                    actions={
                        newRequest ? (
                            <DestinationLink
                                destination={newRequest}
                                className={buttonVariants({ variant: 'primary' })}
                            >
                                New request
                            </DestinationLink>
                        ) : null
                    }
                />

                <div className="mt-8 space-y-10">
                    {waitingOnYou ? (
                        <Section
                            title="Waiting on you"
                            description="These requests need a reply from you."
                        >
                            {waitingOnYou.length ? (
                                <RequestList rows={waitingOnYou} />
                            ) : (
                                <EmptyState
                                    icon={Hourglass}
                                    title="Nothing is waiting on you."
                                    description="When we need a reply from you, the request appears here."
                                />
                            )}
                        </Section>
                    ) : null}

                    {openRequests ? (
                        <Section
                            title="Your open requests"
                            actions={
                                support ? (
                                    <DestinationLink destination={support} className={quietLink}>
                                        View all requests
                                    </DestinationLink>
                                ) : null
                            }
                        >
                            {openRequests.length ? (
                                <RequestList rows={openRequests} />
                            ) : (
                                <EmptyState
                                    icon={LifeBuoy}
                                    title="You have no open requests."
                                    description="Raise a request and it will appear here."
                                />
                            )}
                        </Section>
                    ) : null}

                    {invoices ? (
                        <Section
                            title="Invoices"
                            actions={
                                <DestinationLink destination={invoices} className={quietLink}>
                                    View invoices
                                </DestinationLink>
                            }
                        >
                            <p className="flex items-center gap-2 text-sm text-text">
                                <FileText
                                    className="size-4 shrink-0 text-text-muted"
                                    aria-hidden="true"
                                />
                                {invoices.outstanding
                                    ? `${invoices.outstanding} ${
                                          invoices.outstanding === 1 ? 'invoice is' : 'invoices are'
                                      } awaiting payment.`
                                    : 'All of your invoices are settled.'}
                            </p>
                        </Section>
                    ) : null}

                    {projects ? (
                        <Section
                            title="Your projects"
                            actions={
                                <DestinationLink destination={projects} className={quietLink}>
                                    View projects
                                </DestinationLink>
                            }
                        >
                            <p className="flex items-center gap-2 text-sm text-text">
                                <FolderKanban
                                    className="size-4 shrink-0 text-text-muted"
                                    aria-hidden="true"
                                />
                                {projects.active
                                    ? `You are on ${projects.active} active ${
                                          projects.active === 1 ? 'project' : 'projects'
                                      }.`
                                    : 'You are not on any active projects yet.'}
                            </p>
                        </Section>
                    ) : null}
                </div>
            </PageFrame>
        </>
    );
}

CustomerHomePage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default CustomerHomePage;
