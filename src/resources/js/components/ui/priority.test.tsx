import { render, screen } from '@testing-library/react';

import { Priority } from '@/components/ui/priority';

/** Direction D §10.3: three ascending bars plus a text label; the label, not the bars, is the signal. */
describe('Priority', () => {
    it('shows the label as text and the bars as decoration', () => {
        const { container } = render(<Priority bars={2}>Medium</Priority>);

        expect(screen.getByText('Medium')).toBeVisible();
        expect(container.querySelector('svg')).toHaveAttribute('aria-hidden', 'true');
    });

    it.each([1, 2, 3] as const)('fills exactly %i of the three bars', (bars) => {
        const { container } = render(<Priority bars={bars}>Label</Priority>);

        expect(container.querySelectorAll('[data-bar="filled"]')).toHaveLength(bars);
        expect(container.querySelectorAll('[data-bar="empty"]')).toHaveLength(3 - bars);
    });

    it('turns the whole mark danger for a critical level', () => {
        render(
            <Priority bars={3} tone="danger">
                Critical
            </Priority>,
        );

        expect(screen.getByText('Critical').parentElement).toHaveClass('text-danger');
    });
});
