import { render, screen } from '@testing-library/react';
import { Inbox } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { EmptyState } from '@/components/ui/empty-state';

/**
 * EPIC-013 WP7 — `EmptyState`, the WP2-deferred primitive arriving with the consumer A6.10 named for
 * it (Home). The contract is that it carries real copy and stays a rule-bounded band rather than
 * becoming a card.
 */
describe('EmptyState', () => {
    it('renders a title alone', () => {
        render(<EmptyState title="No tickets yet" />);

        expect(screen.getByText('No tickets yet')).toBeVisible();
    });

    it('carries copy that can distinguish truly-empty from filtered-empty', () => {
        // The component supplies the shape; the caller supplies the distinction. Both sentences are
        // legitimate uses, and the point is that the description exists to be said at all.
        const { rerender } = render(
            <EmptyState title="No tickets yet" description="New support activity appears here." />,
        );
        expect(screen.getByText('New support activity appears here.')).toBeVisible();

        rerender(
            <EmptyState
                title="No tickets match this filter"
                description="Clear the filter to see the full queue."
            />,
        );
        expect(screen.getByText('Clear the filter to see the full queue.')).toBeVisible();
    });

    it('treats its icon as decoration, not as the message', () => {
        const { container } = render(<EmptyState title="No tickets yet" icon={Inbox} />);

        const icon = container.querySelector('svg')!;
        expect(icon).toHaveAttribute('aria-hidden', 'true');
    });

    it('renders an optional next step and omits the slot otherwise', () => {
        const { rerender } = render(<EmptyState title="No tickets yet" />);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();

        rerender(<EmptyState title="No tickets yet" action={<a href="/new">Raise a ticket</a>} />);
        expect(screen.getByRole('link', { name: 'Raise a ticket' })).toBeVisible();
    });

    it('is a band closed by rules, never a card', () => {
        // L10, "structure over boxes": separation is a rule. A rounded, filled, fully-bordered
        // surface here would make every empty region on the product look like a dialog.
        const { container } = render(<EmptyState title="No tickets yet" />);

        const root = container.firstElementChild!;
        expect(root.className).toMatch(/border-y/);
        expect(root.className).not.toMatch(/rounded/);
        expect(root.className).not.toMatch(/shadow/);
    });

    it('is left-aligned inside the region, never a centred illustration (Direction D §15.2)', () => {
        const { container } = render(
            <EmptyState
                title="No tickets yet"
                description="New support activity appears here."
                icon={Inbox}
                action={<a href="/new">Raise a ticket</a>}
            />,
        );

        // §15.2's own words: "Left-aligned inside the region, not centred illustrations." None of the
        // centering utilities from the pre-M2 version may appear anywhere in the tree. `getAttribute`
        // rather than `.className`, because the icon is an `<svg>`, whose `className` is an
        // `SVGAnimatedString`, not a plain string.
        for (const node of container.querySelectorAll('*')) {
            const classes = node.getAttribute('class') ?? '';
            expect(classes).not.toMatch(/(^|\s)(text-center|mx-auto|justify-center|items-center)(\s|$)/);
        }
    });
});
