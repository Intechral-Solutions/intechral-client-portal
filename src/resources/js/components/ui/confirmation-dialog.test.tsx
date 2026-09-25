import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { ConfirmationDialog } from '@/components/ui/confirmation-dialog';

function Harness({ error, onConfirm }: { error?: string; onConfirm: () => void }) {
    const [open, setOpen] = useState(false);

    return (
        <ConfirmationDialog
            open={open}
            onOpenChange={setOpen}
            title="Delete project"
            description="This cannot be undone."
            confirmLabel="Delete project"
            onConfirm={onConfirm}
            error={error}
        >
            <Button type="button">Open</Button>
        </ConfirmationDialog>
    );
}

it('names the dialog and confirms only through its explicit button', async () => {
    const user = userEvent.setup();
    const onConfirm = vi.fn();
    render(<Harness onConfirm={onConfirm} />);

    await user.click(screen.getByRole('button', { name: 'Open' }));
    expect(screen.getByRole('dialog', { name: 'Delete project' })).toHaveAccessibleDescription(
        'This cannot be undone.',
    );
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(onConfirm).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Delete project' }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
});

it('shows a refusal inside the open dialog as an alert', async () => {
    const user = userEvent.setup();
    render(<Harness onConfirm={vi.fn()} error="This project has recorded time and cannot be deleted." />);

    await user.click(screen.getByRole('button', { name: 'Open' }));

    expect(refusal()).toHaveTextContent('This project has recorded time and cannot be deleted.');
});

function refusal() {
    return screen.getByRole('alert');
}

it('closes on Escape, returns focus to its opener, and offers an explicit close control', async () => {
    const user = userEvent.setup();
    render(<Harness onConfirm={vi.fn()} />);
    const opener = screen.getByRole('button', { name: 'Open' });

    await user.click(opener);
    expect(screen.getByRole('button', { name: 'Close dialog' })).toBeInTheDocument();

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();

    await user.click(opener);
    await user.click(screen.getByRole('button', { name: 'Close dialog' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
});

it('keeps the confirm control destructive and disables both actions while processing', async () => {
    const user = userEvent.setup();
    render(
        <ConfirmationDialog
            open
            onOpenChange={vi.fn()}
            title="Delete project"
            description="This cannot be undone."
            confirmLabel="Delete project"
            onConfirm={vi.fn()}
            processing
        >
            <Button type="button">Open</Button>
        </ConfirmationDialog>,
    );

    expect(screen.getByRole('button', { name: 'Working...' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    await user.keyboard('{Escape}');
});
