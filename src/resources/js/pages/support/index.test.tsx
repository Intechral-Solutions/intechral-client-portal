import { render, screen } from '@testing-library/react';

import { SupportIndexPage, type SupportIndexProps } from '@/pages/support/index';
import { resetInertiaMock } from '@/test/inertia';
import type { SupportTicketRow } from '@/types/helpdesk';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const statusOptions: SupportIndexProps['statusOptions'] = [
    { value: 'open', label: 'Open' },
    { value: 'pending_user', label: 'Waiting on requester' },
];

function row(id: number, overrides: Partial<SupportTicketRow> = {}): SupportTicketRow {
    const ref = `TK-${String(id).padStart(5, '0')}-ABCDE`;

    return {
        number: ref,
        title: `Request ${id}`,
        organization: 'Charter College',
        requester: 'Rae Requester',
        status: 'open',
        statusLabel: 'Open',
        priority: 'medium',
        createdAt: '2026-10-08T10:00:00Z',
        createdAtHuman: '2 hours ago',
        href: `/support/requests/${ref}`,
        ...overrides,
    };
}

function props(
    rows: SupportTicketRow[],
    filters: SupportIndexProps['filters'] = { search: null, status: null },
): SupportIndexProps {
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
            first_page_url: '/support/requests?page=1',
        },
        filters,
        statusOptions,
    };
}

it('lists each supported request with its organization, requester and the support link', () => {
    render(<SupportIndexPage {...props([row(2), row(1)])} />);

    expect(
        screen.getByRole('heading', { level: 1, name: 'Supported requests' }),
    ).toBeInTheDocument();
    expect(screen.getByText('2 requests')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Request 2' })).toHaveAttribute(
        'href',
        '/support/requests/TK-00002-ABCDE',
    );
    expect(screen.getAllByText(/Charter College/)).toHaveLength(2);
    expect(screen.getAllByText(/Rae Requester/).length).toBeGreaterThan(0);
});

it('says there is nothing yet, and offers the creation page in the header only', () => {
    render(<SupportIndexPage {...props([])} />);

    expect(screen.getByText('There are no supported requests yet.')).toBeInTheDocument();
    // R1-5: one entry point, the page header's. The page itself is reachable only with a grant (the list route answers 404 without one).
    expect(screen.getAllByRole('link', { name: /New request/ })).toHaveLength(1);
    expect(screen.getByRole('link', { name: /New request/ })).toHaveAttribute(
        'href',
        '/support/requests/create',
    );
});

it('distinguishes a filtered-empty list', () => {
    render(<SupportIndexPage {...props([], { search: 'printer', status: null })} />);

    expect(screen.getByText('No requests match these filters.')).toBeInTheDocument();
});
