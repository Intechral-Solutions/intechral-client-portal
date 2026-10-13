import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TicketsIndexPage, type TicketsIndexProps } from '@/pages/tickets/index';
import { inertiaSpies, resetInertiaMock } from '@/test/inertia';
import type { RequesterTicketRow } from '@/types/helpdesk';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const statusOptions: TicketsIndexProps['statusOptions'] = [
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'pending_user', label: 'Waiting on you' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
];

function row(id: number, overrides: Partial<RequesterTicketRow> = {}): RequesterTicketRow {
    return {
        id,
        number: `TKT-${id}`,
        title: `Request ${id}`,
        status: 'open',
        statusLabel: 'Open',
        priority: 'medium',
        createdAt: '2026-10-08T10:00:00Z',
        createdAtHuman: '2 hours ago',
        href: `/tickets/${id}`,
        ...overrides,
    };
}

function props(
    overrides: Partial<TicketsIndexProps> = {},
    rows: RequesterTicketRow[] = [row(2), row(1)],
): TicketsIndexProps {
    return {
        tickets: {
            data: rows,
            current_page: 1,
            last_page: 1,
            from: rows.length ? 1 : null,
            to: rows.length || null,
            total: rows.length,
            links: [],
            prev_page_url: null,
            next_page_url: null,
            first_page_url: '/tickets?page=1',
        },
        filters: { search: null, status: null },
        statusOptions,
        hasRequests: true,
        canCreate: true,
        ...overrides,
    };
}

it('lists each request as a link with its number, relative time, status and priority', () => {
    render(
        <TicketsIndexPage
            {...props({}, [
                row(7, {
                    title: 'Printer offline',
                    status: 'pending_user',
                    statusLabel: 'Waiting on you',
                    priority: 'critical',
                }),
            ])}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Your requests' })).toBeInTheDocument();

    const item = within(screen.getByRole('list', { name: 'Your requests' })).getByRole('listitem');

    expect(within(item).getByRole('link', { name: 'Printer offline' })).toHaveAttribute(
        'href',
        '/tickets/7',
    );
    expect(within(item).getByText('TKT-7')).toBeInTheDocument();
    expect(within(item).getByText('2 hours ago')).toHaveAttribute(
        'datetime',
        '2026-10-08T10:00:00Z',
    );
    expect(within(item).getByText('Waiting on you')).toBeVisible();
    expect(within(item).getByText('Critical')).toBeVisible();
    expect(screen.getByText('1 request')).toBeInTheDocument();
});

it('shows the customer vocabulary, not the raw status value', () => {
    render(
        <TicketsIndexPage
            {...props({}, [row(1, { status: 'pending_user', statusLabel: 'Waiting on you' })])}
        />,
    );

    expect(screen.queryByText('pending_user')).not.toBeInTheDocument();
});

it('offers New request in the header only with the ability', () => {
    const { rerender } = render(<TicketsIndexPage {...props()} />);
    expect(screen.getByRole('link', { name: 'New request' })).toHaveAttribute(
        'href',
        '/tickets/create',
    );

    rerender(<TicketsIndexPage {...props({ canCreate: false })} />);
    expect(screen.queryByRole('link', { name: 'New request' })).not.toBeInTheDocument();
});

describe('the three empty lists', () => {
    it('says the requester has raised nothing, with no filters to offer and a way to start', () => {
        render(<TicketsIndexPage {...props({ hasRequests: false }, [])} />);

        expect(screen.getByText("You haven't raised any requests yet.")).toBeInTheDocument();
        expect(screen.queryByRole('group', { name: 'Filter requests' })).not.toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'New request' }).length).toBeGreaterThan(0);
        expect(screen.queryByText('No requests match these filters.')).not.toBeInTheDocument();
    });

    it('says nothing matches when requests exist, and clears the filters', async () => {
        const user = userEvent.setup();
        render(<TicketsIndexPage {...props({ filters: { search: 'zzz', status: null } }, [])} />);

        expect(screen.getByText('No requests match these filters.')).toBeInTheDocument();
        expect(screen.queryByText("You haven't raised any requests yet.")).not.toBeInTheDocument();

        await user.click(screen.getAllByRole('button', { name: 'Clear filters' })[0]!);

        expect(inertiaSpies.router.get).toHaveBeenCalledWith(
            '/tickets',
            {},
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('says the page is past the last one when matching requests exist, and links to the first page', () => {
        const page = props({}, []);
        page.tickets = { ...page.tickets, current_page: 9, last_page: 1, total: 3 };
        render(<TicketsIndexPage {...page} />);

        expect(screen.getByText('There are no requests on this page')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Go to the first page' })).toHaveAttribute(
            'href',
            '/tickets?page=1',
        );
        // An empty page is not proof of zero tickets, and not of a filter that matches nothing.
        expect(screen.queryByText("You haven't raised any requests yet.")).not.toBeInTheDocument();
        expect(screen.queryByText('No requests match these filters.')).not.toBeInTheDocument();
    });
});

describe('search and status filters, rendered from the normalized values', () => {
    it("shows the server's search and status, and nothing else from the address bar", () => {
        window.history.replaceState({}, '', '/tickets?search[]=x&status[]=open');
        render(<TicketsIndexPage {...props({ filters: { search: null, status: null } })} />);

        expect(screen.getByRole('searchbox', { name: 'Search requests' })).toHaveValue('');
        expect(screen.getByLabelText('Status')).toHaveValue('');
        expect(screen.queryByRole('list', { name: 'Active filters' })).not.toBeInTheDocument();
        window.history.replaceState({}, '', '/');
    });

    it('searches by visiting with only the term, resetting the page', () => {
        render(<TicketsIndexPage {...props()} />);

        fireEvent.change(screen.getByRole('searchbox', { name: 'Search requests' }), {
            target: { value: '  printer ' },
        });
        fireEvent.submit(
            screen.getByRole('searchbox', { name: 'Search requests' }).closest('form')!,
        );

        expect(inertiaSpies.router.get).toHaveBeenCalledWith(
            '/tickets',
            { search: 'printer' },
            { preserveState: true, preserveScroll: true },
        );
    });

    it("caps the term at the server's 200 characters", () => {
        render(<TicketsIndexPage {...props()} />);

        expect(screen.getByRole('searchbox', { name: 'Search requests' })).toHaveAttribute(
            'maxlength',
            '200',
        );
    });

    it('filters by status and keeps an active search', async () => {
        const user = userEvent.setup();
        render(<TicketsIndexPage {...props({ filters: { search: 'printer', status: null } })} />);

        await user.selectOptions(screen.getByLabelText('Status'), 'pending_user');

        expect(inertiaSpies.router.get).toHaveBeenCalledWith(
            '/tickets',
            { search: 'printer', status: 'pending_user' },
            expect.any(Object),
        );
    });

    it('shows active filters as removable chips with the customer status label', async () => {
        const user = userEvent.setup();
        render(
            <TicketsIndexPage
                {...props({ filters: { search: 'printer', status: 'pending_user' } })}
            />,
        );

        const chips = within(screen.getByRole('list', { name: 'Active filters' }));
        expect(chips.getByText('Search: printer')).toBeInTheDocument();
        expect(chips.getByText('Status: Waiting on you')).toBeInTheDocument();
        expect(screen.getByText('2 requests match')).toBeInTheDocument();

        await user.click(chips.getByRole('button', { name: /Search: printer/ }));

        expect(inertiaSpies.router.get).toHaveBeenLastCalledWith(
            '/tickets',
            { status: 'pending_user' },
            expect.any(Object),
        );
    });
});

it("pages with the server's own links, shown only when there is more than one page", () => {
    const page = props();
    const { rerender } = render(<TicketsIndexPage {...page} />);
    expect(screen.queryByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument();

    page.tickets = {
        ...page.tickets,
        current_page: 1,
        last_page: 3,
        next_page_url: '/tickets?search=printer&page=2',
        total: 45,
    };
    rerender(<TicketsIndexPage {...page} />);

    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/tickets?search=printer&page=2',
    );
    expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
});

it('renders a hostile title as text', () => {
    const hostile = '<img src=x onerror="window.__pwned=1">';
    const { container } = render(<TicketsIndexPage {...props({}, [row(1, { title: hostile })])} />);

    expect(container.querySelector('img')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: hostile })).toBeInTheDocument();
});
