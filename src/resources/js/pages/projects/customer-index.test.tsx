import { render, screen, within } from '@testing-library/react';

import {
    CustomerProjectsIndexPage,
    type CustomerProjectsIndexProps,
} from '@/pages/projects/customer-index';
import { resetInertiaMock, setPageProps } from '@/test/inertia';

/**
 * Release 0.9 pilot (R0.9-CS1): the customer projects list renders a name and a lifecycle status and
 * offers nothing the server did not send.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes/projects', () => ({ show: { url: (id: number) => `/projects/${id}` } }));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

function paginated(data: CustomerProjectsIndexProps['projects']['data']) {
    return {
        data,
        current_page: 1,
        last_page: 1,
        from: data.length ? 1 : null,
        to: data.length || null,
        total: data.length,
        links: [],
        prev_page_url: null,
        next_page_url: null,
    };
}

it('lists the project name and status only, linking to the overview', () => {
    render(
        <CustomerProjectsIndexPage
            projects={paginated([{ id: 5, name: 'Portal rebuild', status: 'on_hold' }])}
        />,
    );

    expect(screen.getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
        'Project',
        'Status',
    ]);

    const row = screen.getByRole('row', { name: /Portal rebuild/ });
    expect(within(row).getByRole('link', { name: 'Portal rebuild' })).toHaveAttribute(
        'href',
        '/projects/5',
    );
    expect(within(row).getByText('On hold')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /new project/i })).not.toBeInTheDocument();
});

it('says so when the customer has no projects', () => {
    render(<CustomerProjectsIndexPage projects={paginated([])} />);

    expect(screen.getByText('No projects yet')).toBeInTheDocument();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
});
