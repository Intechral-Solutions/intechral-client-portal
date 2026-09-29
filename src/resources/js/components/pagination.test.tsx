import { render, screen } from '@testing-library/react';

import { Pagination } from '@/components/pagination';
import { resetInertiaMock } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

const middle = {
    current_page: 2,
    last_page: 5,
    prev_page_url: '/things?page=1&status=open',
    next_page_url: '/things?page=3&status=open',
};

it('links to the server-provided previous and next pages and labels the landmark', () => {
    render(<Pagination paginator={middle} />);

    expect(screen.getByRole('navigation', { name: 'Pagination' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Previous' })).toHaveAttribute(
        'href',
        '/things?page=1&status=open',
    );
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute(
        'href',
        '/things?page=3&status=open',
    );
    expect(screen.getByText('Page 2 of 5')).toBeInTheDocument();
});

it('omits the previous link on the first page and the next link on the last', () => {
    const { rerender } = render(
        <Pagination paginator={{ ...middle, current_page: 1, prev_page_url: null }} />,
    );

    expect(screen.queryByRole('link', { name: 'Previous' })).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Next' })).toBeInTheDocument();

    rerender(<Pagination paginator={{ ...middle, current_page: 5, next_page_url: null }} />);

    expect(screen.getByRole('link', { name: 'Previous' })).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Next' })).not.toBeInTheDocument();
});

it('keeps the page indicator centred when there is a single page', () => {
    render(
        <Pagination
            paginator={{ current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }}
        />,
    );

    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('Page 1 of 1')).toBeInTheDocument();
});

it('marks the directions for the browser and draws them as secondary buttons', () => {
    render(<Pagination paginator={middle} />);

    expect(screen.getByRole('link', { name: 'Previous' })).toHaveAttribute('rel', 'prev');
    expect(screen.getByRole('link', { name: 'Next' })).toHaveAttribute('rel', 'next');
    expect(screen.getByRole('link', { name: 'Next' })).toHaveClass(
        'border-control-edge',
        'bg-surface',
    );
});
