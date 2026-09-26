import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Helpers for the Direction D operator shell (EPIC-013 WP4).
 *
 * The rail's brand mark is decorative — the account trigger carries identity and the rail is already
 * a labelled landmark — so there is no "home" brand link to click any more. Navigation goes through
 * the rail, which is what a real user does.
 */

/** The rail account control: a named menu button, per Direction D §13.1. */
export function accountTrigger(page: Page) {
    return page.getByRole('button', { name: /^Account menu:/ });
}

export async function openAccountMenu(page: Page) {
    await accountTrigger(page).click();
    await expect(page.getByRole('menu')).toBeVisible();
}

/** A workspace link in the rail. Labels come from the server, so they are the product's own words. */
export function railLink(page: Page, workspace: string) {
    return page
        .getByRole('navigation', { name: 'Workspaces' })
        .getByRole('link', { name: workspace, exact: true });
}

/** A contextual view inside the open drawer. */
export function drawerLink(page: Page, workspace: string, view: string) {
    return page
        .getByRole('navigation', { name: `${workspace} views` })
        .getByRole('link', { name: view, exact: true });
}

/** True when the page scrolls horizontally, which the shell must never cause. */
export function hasHorizontalOverflow(page: Page) {
    return page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
}
