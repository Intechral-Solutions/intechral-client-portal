import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { FlashRegion } from './flash-region';

it('announces errors and lets the user dismiss flash feedback', async () => {
    const user = userEvent.setup();

    render(
        <FlashRegion
            flash={{ success: null, error: 'Something failed.', status: null, warning: null }}
        />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Something failed.');
    await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('announces successful feedback as a status', () => {
    render(<FlashRegion flash={{ success: 'Saved.', error: null, status: null, warning: null }} />);

    expect(screen.getByRole('status')).toHaveTextContent('Saved.');
});

it('gives every flash kind a glyph and a kind label, and only errors are assertive', () => {
    render(
        <FlashRegion
            flash={{ success: 'Saved.', error: 'Broke.', status: 'FYI.', warning: 'Careful.' }}
        />,
    );

    expect(screen.getAllByRole('alert')).toHaveLength(1);
    expect(screen.getAllByRole('status')).toHaveLength(3);
    expect(screen.getByRole('alert')).toHaveTextContent('Error: Broke.');
    expect(screen.getByText('Saved.').closest('[role="status"]')).toHaveTextContent(
        'Success: Saved.',
    );
    expect(screen.getByText('Careful.').closest('[role="status"]')).toHaveTextContent(
        'Warning: Careful.',
    );
    expect(screen.getByText('FYI.').closest('[role="status"]')).toHaveTextContent('Notice: FYI.');
});

it('renders a hostile flash message as text', () => {
    const hostile = '<img src=x onerror="window.__pwned = true">';
    const { container } = render(
        <FlashRegion flash={{ success: null, error: hostile, status: null, warning: null }} />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent(hostile);
    expect(container.querySelector('img')).not.toBeInTheDocument();
});
