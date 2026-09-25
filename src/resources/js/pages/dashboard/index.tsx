import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    Clock3,
    Contact,
    FileText,
    FolderKanban,
    Plus,
    TicketCheck,
    UserRound,
    UsersRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';

import { PageHeader } from '@/components/page-header';
import { Status, type StatusTone } from '@/components/ui/status';
import { buttonVariants } from '@/components/ui/button';
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

type VisitMode = 'inertia' | 'document';

type Metric = {
    key: string;
    label: string;
    value: number | string;
    supportingText: string;
    href: string;
    visit: VisitMode;
};

type Ticket = {
    id: number;
    subject: string;
    status: string;
    statusLabel: string;
    requesterName: string | null;
    createdAtHuman: string;
    href: string;
    visit: VisitMode;
};

type QuickAction = {
    key: string;
    label: string;
    href: string;
    visit: VisitMode;
};

type CrmSummary = {
    companies: number;
    contacts: number;
    orgs: number;
    href: string;
    visit: VisitMode;
};

export type DashboardPageProps = {
    metrics: Metric[];
    recentTickets: Ticket[];
    quickActions: QuickAction[];
    crmSummary: CrmSummary | null;
};

const metricIcons: Record<string, LucideIcon> = {
    tickets: TicketCheck,
    projects: FolderKanban,
    time: Clock3,
    billing: FileText,
};

const actionIcons: Record<string, LucideIcon> = {
    'new-ticket': Plus,
    'log-time': Clock3,
    projects: FolderKanban,
    profile: UserRound,
};

function DestinationLink({
    href,
    visit,
    className,
    children,
    'aria-label': ariaLabel,
}: {
    href: string;
    visit: VisitMode;
    className?: string;
    children: ReactNode;
    'aria-label'?: string;
}) {
    if (visit === 'inertia') {
        return (
            <Link href={href} className={className} aria-label={ariaLabel}>
                {children}
            </Link>
        );
    }

    return (
        <a href={href} className={className} aria-label={ariaLabel}>
            {children}
        </a>
    );
}

function statusTone(status: string): StatusTone {
    if (['resolved', 'closed', 'complete', 'completed'].includes(status)) return 'success';
    if (['urgent', 'overdue', 'rejected'].includes(status)) return 'danger';
    if (['pending', 'waiting', 'on_hold'].includes(status)) return 'warning';
    if (['open', 'in_progress'].includes(status)) return 'info';
    return 'neutral';
}

function DashboardPage({ metrics, recentTickets, quickActions, crmSummary }: DashboardPageProps) {
    const ticketsMetric = metrics.find((metric) => metric.key === 'tickets');

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <PageHeader
                    title="Dashboard"
                    description="A current view of your support, projects, time, and billing work."
                />

                {metrics.length ? (
                    <section
                        className="grid gap-4 py-8 sm:grid-cols-2 xl:grid-cols-4"
                        aria-label="Account overview"
                    >
                        {metrics.map((metric) => {
                            const Icon = metricIcons[metric.key] ?? FileText;
                            return (
                                <DestinationLink
                                    key={metric.key}
                                    href={metric.href}
                                    visit={metric.visit}
                                    className="group rounded-md border border-border bg-card p-5 shadow-sm transition-colors hover:border-accent-line focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    aria-label={`View ${metric.label}`}
                                >
                                    <div className="flex items-start justify-between gap-4">
                                        <div>
                                            <p className="text-sm font-medium text-muted-foreground">
                                                {metric.label}
                                            </p>
                                            <p className="mt-2 text-3xl font-semibold text-foreground">
                                                {metric.value}
                                            </p>
                                        </div>
                                        <Icon
                                            className="h-5 w-5 text-muted-foreground transition-colors group-hover:text-accent"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        {metric.supportingText}
                                    </p>
                                </DestinationLink>
                            );
                        })}
                    </section>
                ) : null}

                <div className="grid gap-10 border-t border-border py-8 lg:grid-cols-[minmax(0,2fr)_minmax(16rem,1fr)]">
                    <section aria-labelledby="recent-tickets-heading">
                        <div className="flex items-center justify-between gap-4">
                            <h2 id="recent-tickets-heading" className="text-lg font-semibold">
                                Recent tickets
                            </h2>
                            {ticketsMetric ? (
                                <DestinationLink
                                    href={ticketsMetric.href}
                                    visit="document"
                                    className="legacy-text-primary text-sm font-medium hover:underline"
                                >
                                    View all
                                </DestinationLink>
                            ) : null}
                        </div>

                        {recentTickets.length ? (
                            <ul className="mt-4 divide-y divide-border border-y border-border">
                                {recentTickets.map((ticket) => (
                                    <li key={ticket.id}>
                                        <DestinationLink
                                            href={ticket.href}
                                            visit={ticket.visit}
                                            className="flex items-center justify-between gap-4 py-4 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-foreground">
                                                    {ticket.subject}
                                                </p>
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {ticket.requesterName
                                                        ? `${ticket.requesterName} · ${ticket.createdAtHuman}`
                                                        : ticket.createdAtHuman}
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-3">
                                                <Status tone={statusTone(ticket.status)}>
                                                    {ticket.statusLabel}
                                                </Status>
                                                <ArrowRight
                                                    className="hidden h-4 w-4 text-muted-foreground sm:block"
                                                    aria-hidden="true"
                                                />
                                            </div>
                                        </DestinationLink>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <div className="mt-4 border-y border-border py-10 text-center">
                                <TicketCheck
                                    className="mx-auto h-6 w-6 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <p className="mt-3 text-sm font-medium">No recent tickets</p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    New support activity will appear here.
                                </p>
                            </div>
                        )}
                    </section>

                    <aside className="space-y-8">
                        <section aria-labelledby="quick-actions-heading">
                            <h2 id="quick-actions-heading" className="text-lg font-semibold">
                                Quick actions
                            </h2>
                            <div className="mt-4 grid gap-2">
                                {quickActions.map((action) => {
                                    const Icon = actionIcons[action.key] ?? ArrowRight;
                                    return (
                                        <DestinationLink
                                            key={action.key}
                                            href={action.href}
                                            visit={action.visit}
                                            className={cn(
                                                buttonVariants({ variant: 'outline' }),
                                                'h-11 justify-start',
                                            )}
                                        >
                                            <Icon className="h-4 w-4" aria-hidden="true" />
                                            {action.label}
                                        </DestinationLink>
                                    );
                                })}
                            </div>
                        </section>

                        {crmSummary ? (
                            <section aria-labelledby="crm-heading">
                                <div className="flex items-center justify-between gap-4">
                                    <h2 id="crm-heading" className="text-lg font-semibold">
                                        CRM
                                    </h2>
                                    <DestinationLink
                                        href={crmSummary.href}
                                        visit={crmSummary.visit}
                                        className="legacy-text-primary text-sm font-medium hover:underline"
                                    >
                                        Open CRM
                                    </DestinationLink>
                                </div>
                                <dl className="mt-4 grid grid-cols-3 divide-x divide-border border-y border-border py-4 text-center">
                                    <div>
                                        <Building2
                                            className="mx-auto h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <dt className="mt-1 text-xs text-muted-foreground">
                                            Companies
                                        </dt>
                                        <dd className="text-lg font-semibold">
                                            {crmSummary.companies}
                                        </dd>
                                    </div>
                                    <div>
                                        <Contact
                                            className="mx-auto h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <dt className="mt-1 text-xs text-muted-foreground">
                                            Contacts
                                        </dt>
                                        <dd className="text-lg font-semibold">
                                            {crmSummary.contacts}
                                        </dd>
                                    </div>
                                    <div>
                                        <UsersRound
                                            className="mx-auto h-4 w-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <dt className="mt-1 text-xs text-muted-foreground">
                                            Organizations
                                        </dt>
                                        <dd className="text-lg font-semibold">{crmSummary.orgs}</dd>
                                    </div>
                                </dl>
                            </section>
                        ) : null}
                    </aside>
                </div>
            </div>
        </>
    );
}

DashboardPage.layout = (page: ReactElement) => <AppLayout>{page}</AppLayout>;

export default DashboardPage;
