import { render, screen } from '@testing-library/react';

import { PageHeader } from '@/components/page-header';

it('renders the page title as the single h1 with its summary and actions', () => {
    render(
        <PageHeader
            title="Tasks"
            description="Tasks assigned to you."
            actions={<button type="button">New task</button>}
        />,
    );

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Tasks' })).toBeInTheDocument();
    expect(screen.getByText('Tasks assigned to you.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New task' })).toBeInTheDocument();
});

it('shows an optional overline as plain text above the title, outside the heading', () => {
    render(<PageHeader title="Tasks" overline="Workspace" />);

    expect(screen.getByText('Workspace')).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(/^Tasks$/);
});

it('omits the optional parts entirely when they are not given', () => {
    const { container } = render(<PageHeader title="Tasks" />);

    expect(container.querySelectorAll('p')).toHaveLength(0);
    expect(container.querySelectorAll('header > div')).toHaveLength(1);
});
