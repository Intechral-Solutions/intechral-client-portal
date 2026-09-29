import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Clock3, FolderKanban, Plus, TicketCheck, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactElement, ReactNode } from 'react';

import { PageFrame } from '@/components/page-frame';
import { PageHeader } from '@/components/page-header';
import { Section } from '@/components/section';
import { AppShell } from '@/components/shell/app-shell';
import { Status, type StatusTone } from '@/components/ui/status';
import { buttonVariants } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { cn } from '@/lib/utils';

/**
 * EPIC-013 WP7 — Home (§21), the first intentional Direction D surface.
 *
 * Its job is to demonstrate the page grammar honestly, so what it does *not* show matters as much as
 * what it does. §21.1 enumerates exactly what the four existing props support, and everything the
 * mockups showed beyond them — a "my work" queue, approvals awaiting me, project health, Helpdesk
 * intelligence, SLA, watch lists, notifications — has **no** implementation behind it and is absent
 * rather than faked. A dashboard that invents a number is worse than one that omits a section.
 *
 * Accordingly the server is untouched: `DashboardController`'s four props keep their shape, the route
 * and its name stay `/dashboard`, and `crmSummary` keeps its key. Only the *label* is Home — which
 * WP3 already made true in the rail — and the Directory heading, which matches the IA rather than the
 * prop name. Renaming the route would touch NavigationBuilder, Wayfinder, the root redirect, four
 * Pest tests and two Playwright specs for no user-visible benefit (§21.2).
 *
 * Structure over boxes (L10): the four metrics are a **figure row**, not four KPI cards. They are a
 * description list of label-and-number pairs separated by rules, with mono numerals, because that is
 * what a console shows — and because four bordered boxes would be the single most obvious way to look
 * like every other dashboard rather than like this one.
 */

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

const actionIcons: Record<string, LucideIcon> = {
    'new-ticket': Plus,
    'log-time': Clock3,
    projects: FolderKanban,
    profile: UserRound,
};

/**
 * The server decides whether a destination is an Inertia visit or a document load, and has since
 * EPIC-011. The page honours that rather than guessing from the href.
 */
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
    // Optional: the metric figure's overlay link (below) carries its full accessible name via
    // `aria-label` and has no visible content of its own.
    children?: ReactNode;
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

/**
 * The metric row (§21.1): a figure in a section, never a KPI card. Each figure is its own link to the
 * surface the number came from, so the number is a way in rather than an ornament.
 *
 * `dt`/`dd` are direct children of the `dl > div` group, because that is the only content model an
 * `<a>` can legally sit in — wrapping `dt`/`dd` in an anchor is not valid HTML. The whole figure stays
 * one click target regardless: the link is a stretched overlay (`absolute inset-0`) rather than a
 * wrapper, sized to the group and carrying the figure's full accessible name (label, value and
 * supporting text) since it has no visible content of its own.
 */
function MetricRow({ metrics }: { metrics: Metric[] }) {
    return (
        // `gap-px` over a `rule` background draws hairlines *between* the figures: separation is a
        // rule, never a border around each one (§4.3, L10). No radius and no card surface, so the
        // row reads as one band of numbers rather than four boxes.
        <dl className="grid grid-cols-1 gap-px border-y border-rule bg-rule sm:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric) => (
                <div
                    key={metric.key}
                    className="relative flex flex-col gap-1 bg-canvas px-4 py-3 transition-colors duration-motion-fast hover:bg-surface-hover"
                >
                    <dt className="text-xs font-medium tracking-wide text-text-muted uppercase">
                        {metric.label}
                    </dt>
                    <dd className="font-mono text-2xl leading-none font-semibold text-text tabular-nums">
                        {metric.value}
                    </dd>
                    <p className="text-xs text-text-secondary">{metric.supportingText}</p>
                    <DestinationLink
                        href={metric.href}
                        visit={metric.visit}
                        className="absolute inset-0 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
                        aria-label={`${metric.label}: ${metric.value}. ${metric.supportingText}`}
                    />
                </div>
            ))}
        </dl>
    );
}

function DashboardPage({ metrics, recentTickets, quickActions, crmSummary }: DashboardPageProps) {
    const ticketsMetric = metrics.find((metric) => metric.key === 'tickets');

    // Direction D §6: an operator page's overline is a date or an id. Home has no id, and the date is
    // the one piece of context that is true without asking the server for anything.
    const today = new Date().toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    const aside = (
        <div className="space-y-8">
            <Section title="Quick actions">
                <div className="grid gap-2">
                    {quickActions.map((action) => {
                        const Icon = actionIcons[action.key] ?? ArrowRight;

                        return (
                            <DestinationLink
                                key={action.key}
                                href={action.href}
                                visit={action.visit}
                                // Every one of these is secondary: §21.1 allows at most one ink
                                // primary in the region, and none of the four earns it over the
                                // others.
                                className={cn(
                                    buttonVariants({ variant: 'secondary' }),
                                    'h-10 justify-start',
                                )}
                            >
                                <Icon className="size-4" aria-hidden="true" />
                                {action.label}
                            </DestinationLink>
                        );
                    })}
                </div>
            </Section>

            {/* Relabelled Directory to match the IA; the prop keeps its `crmSummary` key, because
                renaming it would be a breaking change for no benefit (§21.2). */}
            {crmSummary ? (
                <Section
                    title="Directory"
                    actions={
                        <DestinationLink
                            href={crmSummary.href}
                            visit={crmSummary.visit}
                            className="rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                        >
                            Open Directory
                        </DestinationLink>
                    }
                >
                    <dl className="flex flex-wrap gap-x-8 gap-y-3">
                        {[
                            { label: 'Companies', value: crmSummary.companies },
                            { label: 'Contacts', value: crmSummary.contacts },
                            { label: 'Organizations', value: crmSummary.orgs },
                        ].map((figure) => (
                            <div key={figure.label}>
                                <dt className="text-xs tracking-wide text-text-muted uppercase">
                                    {figure.label}
                                </dt>
                                <dd className="font-mono text-lg font-semibold text-text tabular-nums">
                                    {figure.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Section>
            ) : null}
        </div>
    );

    return (
        <>
            <Head title="Home" />

            <PageFrame
                width="grid"
                asideLabel="Shortcuts and directory"
                aside={aside}
                header={
                    <PageHeader
                        overline={today}
                        title="Home"
                        description="Your support, projects, time and billing work, as it stands now."
                    />
                }
            >
                <div className="space-y-8">
                    {metrics.length ? (
                        <Section title="Overview">
                            <MetricRow metrics={metrics} />
                        </Section>
                    ) : null}

                    <Section
                        title="Recent tickets"
                        actions={
                            ticketsMetric ? (
                                <DestinationLink
                                    href={ticketsMetric.href}
                                    visit={ticketsMetric.visit}
                                    className="rounded-control text-sm font-medium text-text-secondary hover:text-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                >
                                    View all
                                </DestinationLink>
                            ) : null
                        }
                    >
                        {recentTickets.length ? (
                            <ul className="divide-y divide-rule border-b border-rule">
                                {recentTickets.map((ticket) => (
                                    <li key={ticket.id}>
                                        <DestinationLink
                                            href={ticket.href}
                                            visit={ticket.visit}
                                            className="flex items-center justify-between gap-4 px-1 py-3 transition-colors duration-motion-fast hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-focus"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium text-text">
                                                    {ticket.subject}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-text-muted">
                                                    {ticket.requesterName
                                                        ? `${ticket.requesterName} · ${ticket.createdAtHuman}`
                                                        : ticket.createdAtHuman}
                                                </p>
                                            </div>
                                            <Status tone={statusTone(ticket.status)}>
                                                {ticket.statusLabel}
                                            </Status>
                                        </DestinationLink>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            // Truly empty, not filtered empty: Home has no filter to clear, so the
                            // copy says what would put something here instead (§21.2). Neutral on
                            // purpose: the operator query is every open ticket and the customer query
                            // is that actor's own, so the copy must not imply personal assignment
                            // ("waiting on you") that only one of the two audiences actually has.
                            <EmptyState
                                icon={TicketCheck}
                                title="No open tickets"
                                description="New support activity appears in this list as it arrives."
                            />
                        )}
                    </Section>
                </div>
            </PageFrame>
        </>
    );
}

DashboardPage.layout = (page: ReactElement) => <AppShell>{page}</AppShell>;

export default DashboardPage;
