import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { BulkBar } from '@/components/ui/bulk-bar';
import { Button } from '@/components/ui/button';

/** Direction D §4.4/§18: the bulk-action bar is a level-2 toolbar that exists only while rows are selected. */
describe('BulkBar', () => {
    it('renders nothing with no selection', () => {
        const { container } = render(
            <BulkBar count={0} label="Bulk actions" onClear={() => {}}>
                <Button>Complete</Button>
            </BulkBar>,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('is a named toolbar that states the count and hosts its actions', () => {
        render(
            <BulkBar count={3} noun="task" label="Bulk actions" onClear={() => {}}>
                <Button>Complete</Button>
                <Button>Reopen</Button>
            </BulkBar>,
        );

        const bar = screen.getByRole('toolbar', { name: 'Bulk actions' });
        expect(bar).toHaveTextContent('3 tasks selected');
        expect(screen.getByRole('button', { name: 'Complete' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Reopen' })).toBeVisible();
    });

    it('uses the singular for one row', () => {
        render(
            <BulkBar count={1} noun="task" label="Bulk actions" onClear={() => {}}>
                <span />
            </BulkBar>,
        );

        expect(screen.getByRole('toolbar')).toHaveTextContent('1 task selected');
    });

    it('clears the selection from a named button and on Escape', async () => {
        const user = userEvent.setup();
        const onClear = vi.fn();
        render(
            <BulkBar count={2} noun="task" label="Bulk actions" onClear={onClear}>
                <Button>Complete</Button>
            </BulkBar>,
        );

        await user.click(screen.getByRole('button', { name: 'Clear selection' }));
        screen.getByRole('button', { name: 'Complete' }).focus();
        await user.keyboard('{Escape}');

        expect(onClear).toHaveBeenCalledTimes(2);
    });
});
