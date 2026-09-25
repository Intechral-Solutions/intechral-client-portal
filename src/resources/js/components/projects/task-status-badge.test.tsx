import { render, screen } from '@testing-library/react';

import { TaskStatusBadge } from '@/components/projects/task-status-badge';

it('shows the column/status label and reflects done state', () => {
    const { rerender } = render(
        <TaskStatusBadge status={{ label: 'In Progress', done: false, source: 'column' }} />,
    );
    expect(screen.getByText('In Progress')).toBeInTheDocument();

    rerender(<TaskStatusBadge status={{ label: 'Done', done: true, source: 'column' }} />);
    expect(screen.getByText('Done')).toBeInTheDocument();
});

it('marks done with the success tone and a glyph, and open work as neutral, with the label as plain text', () => {
    const hostile = '<b>Done</b>';
    const { container, rerender } = render(
        <TaskStatusBadge status={{ label: hostile, done: true, source: 'column' }} />,
    );

    expect(screen.getByText(hostile)).toBeInTheDocument();
    expect(container.querySelector('b')).not.toBeInTheDocument();
    expect(container.firstElementChild).toHaveClass('text-success');
    expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');

    rerender(<TaskStatusBadge status={{ label: 'To Do', done: false, source: 'column' }} />);
    expect(container.firstElementChild).toHaveClass('text-text-muted');
});
