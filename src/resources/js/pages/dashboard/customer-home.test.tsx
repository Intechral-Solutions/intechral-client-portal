import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import CustomerHomePage, { type CustomerHomeProps } from '@/pages/dashboard/customer-home';

// `data-inertia` marks an Inertia visit in jsdom, so which links stay in the SPA is assertable.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: React.ComponentProps<'a'>) => (
        <a href={href} data-inertia="true" {...rest}>
            {children}
        </a>
    ),
}));

/**
 * EPIC-017 WP1 (§5) — the Focused customer Home. It renders only what the server sends and draws
 * nothing for a section the server withheld; it has no operator vocabulary because it is never sent
 * any.
 */
const row = (id: number, title: string) => ({
    id,
    number: `TKT-000${id}`,
    title,
    createdAt: '2026-10-07T09:00:00+00:00',
    createdAtHuman: '1 day ago',
    href: `/tickets/${id}`,
    visit: 'document' as const,
});

const full: CustomerHomeProps = {
    waitingOnYou: [row(1, 'Which printer model?')],
    openRequests: [
        { ...row(2, 'VPN will not connect'), status: 'open', statusLabel: 'Open' },
        { ...row(3, 'Invoice question'), status: 'in_progress', statusLabel: 'In progress' },
    ],
    invoices: { outstanding: 2, href: '/my/invoices', visit: 'document' },
    projects: { active: 1, href: '/projects', visit: 'inertia' },
    support: { href: '/tickets', visit: 'document' },
    newRequest: { href: '/tickets/create', visit: 'document' },
};

const empty: CustomerHomeProps = {
    waitingOnYou: [],
    openRequests: [],
    invoices: { outstanding: 0, href: '/my/invoices', visit: 'document' },
    projects: { active: 0, href: '/projects', visit: 'inertia' },
    support: { href: '/tickets', visit: 'document' },
    newRequest: { href: '/tickets/create', visit: 'document' },
};

const none: CustomerHomeProps = {
    waitingOnYou: null,
    openRequests: null,
    invoices: null,
    projects: null,
    support: null,
    newRequest: null,
};

describe('the customer Home', () => {
    it('has one h1, "Home", and the four sections in the customer reading order', () => {
        render(<CustomerHomePage {...full} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Home');
        expect(screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent)).toEqual([
            'Waiting on you',
            'Your open requests',
            'Invoices',
            'Your projects',
        ]);
    });

    it('lists each request as a link to its own page, with its number and age', () => {
        render(<CustomerHomePage {...full} />);

        const waiting = screen.getByRole('region', { name: 'Waiting on you' });
        const link = within(waiting).getByRole('link', { name: /Which printer model/ });

        expect(link).toHaveAttribute('href', '/tickets/1');
        expect(link).toHaveTextContent('TKT-0001');
        expect(within(link).getByText('1 day ago')).toHaveAttribute(
            'datetime',
            '2026-10-07T09:00:00+00:00',
        );

        const open = screen.getByRole('region', { name: 'Your open requests' });

        expect(within(open).getAllByRole('link', { name: /TKT-/ })).toHaveLength(2);
        expect(within(open).getByText('In progress')).toBeInTheDocument();
        // A waiting request is a call to action in its own section: it carries no status chip.
        expect(within(waiting).queryByText('Open')).toBeNull();
    });

    it('offers New request as the single primary action, and view-all and module links', () => {
        render(<CustomerHomePage {...full} />);

        const primary = screen.getByRole('link', { name: 'New request' });

        expect(primary).toHaveAttribute('href', '/tickets/create');
        expect(screen.getByRole('link', { name: 'View all requests' })).toHaveAttribute(
            'href',
            '/tickets',
        );
        expect(screen.getByRole('link', { name: 'View invoices' })).toHaveAttribute(
            'href',
            '/my/invoices',
        );
        expect(screen.getByRole('link', { name: 'View projects' })).toHaveAttribute(
            'href',
            '/projects',
        );
        // Exactly one ink-primary action on the page.
        expect(document.querySelectorAll('a.bg-ink')).toHaveLength(1);
    });

    it('honours the server visit mode: projects stay in the SPA, Blade destinations load as documents', () => {
        render(<CustomerHomePage {...full} />);

        expect(screen.getByRole('link', { name: 'View projects' })).toHaveAttribute(
            'data-inertia',
            'true',
        );
        expect(screen.getByRole('link', { name: 'View invoices' })).not.toHaveAttribute(
            'data-inertia',
        );
        expect(screen.getByRole('link', { name: 'New request' })).not.toHaveAttribute(
            'data-inertia',
        );
    });

    it('says what is outstanding in plain language, singular and plural', () => {
        const { rerender } = render(<CustomerHomePage {...full} />);

        expect(screen.getByText('2 invoices are awaiting payment.')).toBeInTheDocument();
        expect(screen.getByText('You are on 1 active project.')).toBeInTheDocument();

        rerender(
            <CustomerHomePage
                {...full}
                invoices={{ outstanding: 1, href: '/my/invoices', visit: 'document' }}
                projects={{ active: 3, href: '/projects', visit: 'inertia' }}
            />,
        );

        expect(screen.getByText('1 invoice is awaiting payment.')).toBeInTheDocument();
        expect(screen.getByText('You are on 3 active projects.')).toBeInTheDocument();
    });

    it('uses calm empty states that say what would put something there', () => {
        render(<CustomerHomePage {...empty} />);

        expect(screen.getByText('Nothing is waiting on you.')).toBeInTheDocument();
        expect(screen.getByText('You have no open requests.')).toBeInTheDocument();
        expect(screen.getByText('All of your invoices are settled.')).toBeInTheDocument();
        expect(screen.getByText('You are not on any active projects yet.')).toBeInTheDocument();
    });

    it('draws nothing for a section the server withheld, and no primary action without the capability', () => {
        render(<CustomerHomePage {...none} />);

        expect(screen.queryAllByRole('heading', { level: 2 })).toHaveLength(0);
        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Home');
    });

    it('speaks no operator language', () => {
        render(<CustomerHomePage {...full} />);

        expect(document.body.textContent).not.toMatch(
            /\b(SLA|queue|overdue|requester|priority|assignee|internal)\b/i,
        );
    });
});
