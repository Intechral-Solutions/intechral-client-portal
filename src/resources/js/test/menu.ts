import { screen } from '@testing-library/react';
import type { UserEvent } from '@testing-library/user-event';

/**
 * Radix `DropdownMenu` under jsdom opens reliably by keyboard, not by pointer (EPIC-011E WP2 finding,
 * `move-task-menu.test.tsx`), and keyboard is the canonical path anyway. These drive a menu the way a
 * keyboard user does: focus the trigger and press Enter, then focus an item and press Enter.
 */
export async function openMenu(user: UserEvent, trigger: HTMLElement) {
    trigger.focus();
    await user.keyboard('{Enter}');

    return screen.findByRole('menu');
}

export async function chooseRadio(user: UserEvent, name: string | RegExp) {
    const item = await screen.findByRole('menuitemradio', { name });
    item.focus();
    await user.keyboard('{Enter}');
}
