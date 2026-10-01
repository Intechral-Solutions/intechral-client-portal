import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { FormDialog } from '@/components/ui/form-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

function Harness({
    onSubmit = vi.fn(),
    processing = false,
    error,
}: {
    onSubmit?: (event: FormEvent<HTMLFormElement>) => void;
    processing?: boolean;
    error?: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button type="button" onClick={() => setOpen(true)}>
                Add milestone
            </Button>
            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title="New milestone"
                description="Group tasks under a target date."
                submitLabel="Create milestone"
                onSubmit={onSubmit}
                processing={processing}
            >
                <div>
                    <Label htmlFor="milestone-name">Name</Label>
                    <Input id="milestone-name" name="name" required />
                    {error ? <p role="alert">{error}</p> : null}
                </div>
            </FormDialog>
        </>
    );
}

it('names the dialog, moves focus inside it, and traps Tab', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Add milestone' }));

    const dialog = screen.getByRole('dialog', { name: 'New milestone' });
    expect(dialog).toHaveAccessibleDescription('Group tasks under a target date.');
    expect(dialog).toContainElement(document.activeElement as HTMLElement);

    for (let i = 0; i < 6; i += 1) {
        await user.tab();
        expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
});

it('closes on Escape and on Cancel and returns focus to the opener', async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const opener = screen.getByRole('button', { name: 'Add milestone' });

    await user.click(opener);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();

    await user.click(opener);
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(opener).toHaveFocus();
});

it('submits once through native validation with the default action prevented', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn((event: FormEvent<HTMLFormElement>) => {
        expect(event.defaultPrevented).toBe(true);
    });
    render(<Harness onSubmit={onSubmit} />);

    await user.click(screen.getByRole('button', { name: 'Add milestone' }));
    await user.click(screen.getByRole('button', { name: 'Create milestone' }));
    expect(onSubmit).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText('Name'), 'Launch');
    await user.keyboard('{Enter}');

    expect(onSubmit).toHaveBeenCalledTimes(1);
});

it('does not submit a form that happens to contain the dialog', async () => {
    const user = userEvent.setup();
    const outer = vi.fn((event: FormEvent) => event.preventDefault());
    const inner = vi.fn();
    render(
        <form onSubmit={outer}>
            <Harness onSubmit={inner} />
        </form>,
    );

    await user.click(screen.getByRole('button', { name: 'Add milestone' }));
    await user.type(screen.getByLabelText('Name'), 'Launch');
    await user.click(screen.getByRole('button', { name: 'Create milestone' }));

    expect(inner).toHaveBeenCalledTimes(1);
    expect(outer).not.toHaveBeenCalled();
});

it('renders field errors in place and disables both actions while processing', async () => {
    const user = userEvent.setup();
    render(<Harness error="The name has already been taken." processing />);

    await user.click(screen.getByRole('button', { name: 'Add milestone' }));

    expect(screen.getByRole('alert')).toHaveTextContent('The name has already been taken.');
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled();
});

it('asks to open when its own trigger is used', async () => {
    const user = userEvent.setup();
    const onOpenChange = vi.fn();
    render(
        <FormDialog
            open={false}
            onOpenChange={onOpenChange}
            title="Owned"
            description="Has its own opener."
            submitLabel="Save"
            onSubmit={vi.fn()}
            trigger={<button type="button">Open owned</button>}
        >
            <p>Body</p>
        </FormDialog>,
    );

    await user.click(screen.getByRole('button', { name: 'Open owned' }));

    expect(onOpenChange).toHaveBeenCalledExactlyOnceWith(true);
});

it('opens on its first field rather than the Close button (Direction D §14.2)', async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Add milestone' }));

    expect(screen.getByLabelText('Name')).toHaveFocus();
});
