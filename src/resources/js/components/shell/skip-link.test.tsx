import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SkipLink } from './skip-link';

it('is the first focusable element and targets the main landmark', async () => {
    const user = userEvent.setup();
    render(
        <>
            <SkipLink />
            <a href="/elsewhere">Other</a>
        </>,
    );

    await user.tab();

    const link = screen.getByRole('link', { name: 'Skip to content' });

    // Direction D §14.1 requires it in both shells; nothing targeted `#main-content` before WP4.
    expect(link).toHaveFocus();
    expect(link).toHaveAttribute('href', '#main-content');
});

it('stays in the tab order while hidden, and becomes visible on focus', () => {
    render(<SkipLink />);

    const link = screen.getByRole('link', { name: 'Skip to content' });

    // `sr-only` rather than `hidden`: removing it from the tree would remove it from Tab order.
    expect(link).toHaveClass('sr-only');
    expect(link.className).toContain('focus-visible:not-sr-only');
});
