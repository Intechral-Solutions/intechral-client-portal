import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { Chip, FilterChip } from '@/components/ui/chip';
import { Tag } from '@/components/ui/tag';

/**
 * EPIC-014 WP4 — `Chip`/`FilterChip` (Direction D §18) and the mono `Tag` (§10.2's source tag).
 * Presentation only: a chip names a value, a filter chip can be removed, neither knows what it filters.
 */
describe('Chip', () => {
    it('renders its text and nothing interactive', () => {
        render(<Chip>Overdue</Chip>);

        expect(screen.getByText('Overdue')).toBeVisible();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});

describe('FilterChip', () => {
    it('names its remove control after the filter it removes', () => {
        render(<FilterChip label="Priority: High" onRemove={() => {}} />);

        expect(screen.getByText('Priority: High')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Remove filter: Priority: High' })).toBeVisible();
    });

    it('removes with a click, with Enter and with Space', async () => {
        const user = userEvent.setup();
        const onRemove = vi.fn();
        render(<FilterChip label="Due: Overdue" onRemove={onRemove} />);
        const remove = screen.getByRole('button', { name: 'Remove filter: Due: Overdue' });

        await user.click(remove);
        remove.focus();
        await user.keyboard('{Enter}');
        await user.keyboard(' ');

        expect(onRemove).toHaveBeenCalledTimes(3);
    });

    it('is a single control: the label is text, not a second button', () => {
        render(<FilterChip label="Project filter" onRemove={() => {}} />);

        expect(screen.getAllByRole('button')).toHaveLength(1);
    });
});

describe('Tag', () => {
    it('renders mono, uppercase source text as plain text', () => {
        render(<Tag>Board</Tag>);

        expect(screen.getByText('Board')).toHaveClass('font-mono', 'uppercase');
    });
});
