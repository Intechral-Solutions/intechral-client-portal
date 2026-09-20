import { render, screen } from '@testing-library/react';
import { vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} data-inertia="true" {...props}>
            {children}
        </a>
    ),
}));

import DashboardPage, { type DashboardPageProps } from './index';

const baseProps: DashboardPageProps = {
    metrics: [
        {
            key: 'tickets',
            label: 'Open Tickets',
            value: 2,
            supportingText: 'Needs attention',
            href: '/tickets',
            visit: 'document',
        },
    ],
    recentTickets: [
        {
            id: 9,
            subject: 'Unable to export',
            status: 'open',
            statusLabel: 'Open',
            requesterName: 'Ada Client',
            createdAtHuman: '2 hours ago',
            href: '/tickets/9',
            visit: 'document',
        },
    ],
    quickActions: [
        {
            key: 'profile',
            label: 'My Profile',
            href: '/profile',
            visit: 'inertia',
        },
    ],
    crmSummary: null,
};

it('renders scoped metrics and recent ticket details', () => {
    render(<DashboardPage {...baseProps} />);

    expect(screen.getByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(screen.getByLabelText('View Open Tickets')).toHaveAttribute('href', '/tickets');
    expect(screen.getByText('Unable to export')).toBeInTheDocument();
    expect(screen.getByText('Ada Client · 2 hours ago')).toBeInTheDocument();
    expect(screen.getByText('Open')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'My Profile' })).toHaveAttribute(
        'data-inertia',
        'true',
    );
});

it('renders a useful empty state without an unauthorized CRM section', () => {
    render(<DashboardPage {...baseProps} metrics={[]} recentTickets={[]} quickActions={[]} />);

    expect(screen.getByText('No recent tickets')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'CRM' })).not.toBeInTheDocument();
});

it('renders operator CRM totals only when supplied by the server', () => {
    render(
        <DashboardPage
            {...baseProps}
            crmSummary={{
                companies: 4,
                contacts: 7,
                orgs: 2,
                href: '/crm/companies',
                visit: 'document',
            }}
        />,
    );

    expect(screen.getByRole('heading', { name: 'CRM' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open CRM' })).not.toHaveAttribute('data-inertia');
    expect(screen.getByText('Companies').nextSibling).toHaveTextContent('4');
});
