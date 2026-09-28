import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import DashboardPage, { type DashboardPageProps } from '@/pages/dashboard/index';

// `data-inertia` is what makes an Inertia visit distinguishable from a document load in jsdom, and
// the pre-WP7 version of this file used it for exactly that. Kept, because which links stay in the
// SPA is behaviour, not presentation, and WP7 is a presentation change.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} data-inertia="true" {...rest}>
            {children}
        </a>
    ),
}));

/**
 * EPIC-013 WP7 — Home (§21, §25.2: "renders from the four existing props; renders **nothing** for
 * approvals, project health or Helpdesk intelligence; four metrics are not cards").
 *
 * The absence assertions are the important half. Home's whole discipline is that it shows only what
 * the four props support, and the failure mode this guards is a future change quietly adding a
 * plausible-looking figure with nothing behind it.
 */
const props: DashboardPageProps = {
    metrics: [
        {
            key: 'tickets',
            label: 'Open Tickets',
            value: 3,
            supportingText: 'Needs attention',
            href: '/tickets',
            visit: 'document',
        },
        {
            key: 'projects',
            label: 'Active Projects',
            value: 2,
            supportingText: 'In progress',
            href: '/projects',
            visit: 'inertia',
        },
        {
            key: 'time',
            label: 'Time This Month',
            value: '4.5h',
            supportingText: '1.0h unbilled',
            href: '/time',
            visit: 'inertia',
        },
        {
            key: 'billing',
            label: 'Outstanding Invoices',
            value: 1,
            supportingText: 'Awaiting payment',
            href: '/billing/invoices',
            visit: 'document',
        },
    ],
    recentTickets: [
        {
            id: 7,
            subject: 'Printer offline',
            status: 'open',
            statusLabel: 'Open',
            requesterName: 'Dana West',
            createdAtHuman: '2 hours ago',
            href: '/tickets/7',
            visit: 'document',
        },
    ],
    quickActions: [
        { key: 'new-ticket', label: 'New Ticket', href: '/tickets/create', visit: 'document' },
        { key: 'log-time', label: 'Log Time', href: '/time', visit: 'inertia' },
    ],
    crmSummary: null,
};

function renderHome(overrides: Partial<DashboardPageProps> = {}) {
    return render(<DashboardPage {...props} {...overrides} />);
}

describe('Home', () => {
    it('is titled Home and sits on a grid frame with a labelled supporting column', () => {
        const { container } = renderHome();

        expect(screen.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
        expect(container.querySelector('[data-page-frame]')).toHaveAttribute(
            'data-page-frame',
            'grid',
        );
        expect(
            screen.getByRole('complementary', { name: 'Shortcuts and directory' }),
        ).toBeVisible();
    });

    it('renders the four metrics as a figure row, not as cards', () => {
        const { container } = renderHome();

        // A description list of label/number pairs is the contract (§21.1: "a figure in a section,
        // not a KPI card"). Four separate bordered containers would be the regression, so the shape
        // is what is asserted rather than any particular class.
        const list = container.querySelector('dl')!;
        expect(within(list as HTMLElement).getAllByRole('term')).toHaveLength(4);
        expect(within(list as HTMLElement).getAllByRole('definition')).toHaveLength(4);

        expect(screen.getByText('Open Tickets')).toBeVisible();
        expect(screen.getByText('4.5h')).toBeVisible();

        // Numbers are mono and tabular — a console reads columns of figures, not prose.
        const value = screen.getByText('4.5h');
        expect(value.className).toMatch(/font-mono/);
        expect(value.className).toMatch(/tabular-nums/);
    });

    it('keeps dt/dd as direct children of the group, never nested inside the link', () => {
        // The HTML description-list content model does not allow an `<a>` between `dl` and `dt`/`dd`.
        // The figure's link is a stretched overlay instead of a wrapper (M1), so this proves the fix:
        // `dt`/`dd` sit directly under the group `div`, and no anchor is an ancestor of either.
        const { container } = renderHome();

        const list = container.querySelector('dl')!;
        const terms = within(list).getAllByRole('term');
        expect(terms).toHaveLength(4);

        for (const term of terms) {
            const group = term.parentElement!;
            expect(group.tagName).toBe('DIV');
            expect(group.parentElement).toBe(list);

            const dd = group.querySelector(':scope > dd')!;
            expect(dd).not.toBeNull();
            expect(term.closest('a')).toBeNull();
            expect(dd.closest('a')).toBeNull();

            // The overlay link is still there, covering the same group, honouring the same href.
            expect(group.querySelector('a')).not.toBeNull();
        }
    });

    it('sends every destination the way the server said to', () => {
        renderHome();

        // Every link must honour its `visit`: an Inertia destination stays in the SPA, a document
        // destination does a real navigation. Guessing from the href would break both, and the
        // composition change must not quietly flip any of them.
        const projects = screen.getByRole('link', { name: /Active Projects/ });
        expect(projects).toHaveAttribute('href', '/projects');
        expect(projects).toHaveAttribute('data-inertia', 'true');

        const tickets = screen.getByRole('link', { name: /Open Tickets/ });
        expect(tickets).toHaveAttribute('href', '/tickets');
        expect(tickets).not.toHaveAttribute('data-inertia');

        expect(screen.getByRole('link', { name: 'Log Time' })).toHaveAttribute(
            'data-inertia',
            'true',
        );
        expect(screen.getByRole('link', { name: 'New Ticket' })).not.toHaveAttribute(
            'data-inertia',
        );
    });

    it('names each metric link by its figure, so the number is announced with it', () => {
        renderHome();

        // The accessible name carries label, value and supporting text: a link called only
        // "Open Tickets" would make a screen reader user open the page to learn the number.
        expect(
            screen.getByRole('link', { name: 'Open Tickets: 3. Needs attention' }),
        ).toBeVisible();
    });

    it('lists recent tickets with a status glyph and label, never colour alone', () => {
        renderHome();

        const ticket = screen.getByRole('link', { name: /Printer offline/ });
        expect(ticket).toHaveAttribute('href', '/tickets/7');
        expect(within(ticket).getByText('Open')).toBeVisible();
        expect(within(ticket).getByText(/Dana West/)).toBeVisible();
    });

    it('distinguishes a truly-empty ticket list rather than printing a bare dash', () => {
        renderHome({ recentTickets: [] });

        // §21.2 asks for real copy: Home has no filter to clear, so the empty state says what would
        // put something here instead of implying something was filtered away.
        expect(screen.getByText('No open tickets')).toBeVisible();
        expect(screen.getByText(/New support activity appears in this list/)).toBeVisible();
    });

    it('shows the Directory section only for an actor the server gave the summary to', () => {
        renderHome();
        expect(screen.queryByRole('heading', { name: 'Directory' })).not.toBeInTheDocument();

        renderHome({
            crmSummary: {
                companies: 4,
                contacts: 9,
                orgs: 2,
                href: '/crm/companies',
                visit: 'document',
            },
        });

        // Relabelled to match the IA; the prop keeps its `crmSummary` key (§21.2).
        expect(screen.getByRole('heading', { name: 'Directory' })).toBeVisible();
        expect(screen.getByText('Companies').nextSibling).toHaveTextContent('4');
        expect(screen.getByRole('link', { name: 'Open Directory' })).not.toHaveAttribute(
            'data-inertia',
        );
        expect(screen.queryByRole('heading', { name: 'CRM' })).not.toBeInTheDocument();
    });

    it('omits the metric row entirely when the actor may see no metric', () => {
        const { container } = renderHome({ metrics: [] });

        expect(container.querySelector('dl')).toBeNull();
        expect(screen.queryByRole('heading', { name: 'Overview' })).not.toBeInTheDocument();
        // The page still stands up: it does not render an empty band where the numbers would be.
        expect(screen.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    });

    it('fabricates nothing the four props do not support', () => {
        renderHome();

        // §21.1 marks each of these Future precisely because no data exists behind them. If one ever
        // appears on Home, it is invented, and this is where that gets caught.
        for (const absent of [
            /approval/i,
            /awaiting (?:you|me)/i,
            /project health/i,
            /\bSLA\b/,
            /my work/i,
            /watch list/i,
            /notification/i,
        ]) {
            expect(screen.queryByText(absent)).not.toBeInTheDocument();
        }
    });
});
