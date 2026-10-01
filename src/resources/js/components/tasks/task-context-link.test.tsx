import { render, screen } from '@testing-library/react';

import { TaskContextLink } from '@/components/tasks/task-context-link';
import { resetInertiaMock } from '@/test/inertia';

vi.mock('@inertiajs/react', async () => (await import('@/test/inertia')).inertiaReactMock());

afterEach(resetInertiaMock);

it('renders a project context as an Inertia link (the board is React, WP5)', () => {
    render(
        <TaskContextLink context={{ kind: 'project', label: 'Alpha', url: '/projects/1/board' }} />,
    );

    const link = screen.getByRole('link', { name: 'Alpha' });
    expect(link).toHaveAttribute('href', '/projects/1/board');
});

it('renders plain text, never a link, when the destination policy denies it', () => {
    render(<TaskContextLink context={{ kind: 'project', label: 'Alpha', url: null }} />);

    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('Alpha')).toBeInTheDocument();
});

it('renders a standalone context as plain text (D3: no destination exists at all)', () => {
    render(<TaskContextLink context={{ kind: 'standalone', label: 'Standalone', url: null }} />);

    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(screen.getByText('Standalone')).toBeInTheDocument();
});
