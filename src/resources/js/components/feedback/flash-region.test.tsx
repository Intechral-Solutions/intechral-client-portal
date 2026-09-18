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
