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
