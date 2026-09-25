import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Menus are opened from the keyboard on purpose: it is the canonical path (EPIC-011E §10) and,
// under jsdom, Radix only opens on pointer-down for the first test of a file, so keyboard
// opening is the reliable way to write more than one menu test per file.
async function openMenu(user: ReturnType<typeof userEvent.setup>) {
    await user.tab();
    await user.keyboard('{Enter}');

    return screen.findByRole('menu');
}

function Menu({ onSelect }: { onSelect: (value: string) => void }) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button type="button">Actions</button>
            </DropdownMenuTrigger>
            <DropdownMenuContent>
                <DropdownMenuLabel>Move to</DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onSelect('doing')}>Doing</DropdownMenuItem>
                <DropdownMenuItem disabled onSelect={() => onSelect('blocked')}>
                    Blocked
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => onSelect('done')}>Done</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

it('opens from the keyboard, moves with the arrows, and selects with Enter', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(<Menu onSelect={onSelect} />);

    expect(await openMenu(user)).toBeInTheDocument();
    expect(screen.getAllByRole('menuitem')).toHaveLength(3);
    // Opening with the keyboard lands on the first item; arrow keys skip the disabled one.
    expect(screen.getByRole('menuitem', { name: 'Doing' })).toHaveFocus();

    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('menuitem', { name: 'Done' })).toHaveFocus();

    await user.keyboard('{Enter}');

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('done');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
});

it('closes on Escape and returns focus to the trigger', async () => {
    const user = userEvent.setup();
    render(<Menu onSelect={vi.fn()} />);

    await openMenu(user);

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Actions' })).toHaveFocus();
});

it('never selects a disabled item and announces it as disabled', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(<Menu onSelect={onSelect} />);

    await openMenu(user);

    const blocked = screen.getByRole('menuitem', { name: 'Blocked' });
    expect(blocked).toHaveAttribute('aria-disabled', 'true');

    await user.click(blocked);
    expect(onSelect).not.toHaveBeenCalled();
});
