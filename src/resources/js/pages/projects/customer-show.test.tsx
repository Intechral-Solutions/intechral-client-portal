import { render, screen, within } from '@testing-library/react';

import { CustomerProjectShowPage } from '@/pages/projects/customer-show';
import { resetInertiaMock, setPageProps } from '@/test/inertia';

/**
 * Release 0.9 pilot (R0.9-CS1): the customer overview is a name, a status and milestone names, dates
 * and completion. There is no board, task, comment, time, budget or member section to find.
 */

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());
vi.mock('@/routes/projects', () => ({ index: { url: () => '/projects' } }));

beforeEach(() => setPageProps({}));
afterEach(resetInertiaMock);

it('shows the project name, its status and each milestone by name and date, with no completion state', () => {
    render(
        <CustomerProjectShowPage
            project={{ name: 'Portal rebuild', status: 'active' }}
            milestones={[
                { name: 'Kick-off', dueDate: '2026-11-02' },
                { name: 'Go-live', dueDate: '2026-12-01' },
            ]}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Portal rebuild' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'All projects' })).toHaveAttribute('href', '/projects');

    const [first, second, ...rest] = screen.getAllByRole('listitem');
    expect(rest).toHaveLength(0);
    expect(within(first!).getByText('Kick-off')).toBeInTheDocument();
    expect(within(second!).getByText('Go-live')).toBeInTheDocument();

    for (const absent of [
        /task/i,
        /board/i,
        /comment/i,
        /time/i,
        /budget/i,
        /member/i,
        /health/i,
    ]) {
        expect(screen.queryByText(absent)).not.toBeInTheDocument();
    }
});

it('says so when there are no milestones', () => {
    render(
        <CustomerProjectShowPage project={{ name: 'Empty', status: 'archived' }} milestones={[]} />,
    );

    expect(screen.getByText('No milestones yet')).toBeInTheDocument();
});
