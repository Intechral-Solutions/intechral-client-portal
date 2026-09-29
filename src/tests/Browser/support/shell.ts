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

/*
 * EPIC-013 WP6 — the global timer pill and tray (Direction D §12.1, §12.2).
 *
 * Deliberately renderer-agnostic. React and Blade draw different markup but expose the same hooks and
 * the same accessible names, so one set of helpers drives both — which is also what makes a crossing
 * assertion meaningful: the same selector must find the same timer on either side of a document
 * navigation. `data-timer-count` and `data-timer-running` carry the pill's *state* rather than its
 * pixels, so the crossing tests can assert identity instead of appearance.
 */

/** The one global timer affordance. There must never be more than one on a page. */
export function timerPill(page: Page) {
    return page.locator('[data-shell-timer]');
}

export function timerTrigger(page: Page) {
    return page.locator('[data-shell-timer-trigger]');
}

/** The pill's own inline Stop, which acts on the timer the pill is showing. */
export function timerStop(page: Page) {
    return page.locator('[data-shell-timer-stop]');
}

export function timerTray(page: Page) {
    return page.getByRole('dialog', { name: 'Running timers' });
}

/** How many timers the server-reconciled pill currently knows about. */
export async function runningTimerCount(page: Page): Promise<number> {
    await expect(timerPill(page)).toBeAttached();

    return Number(await timerPill(page).getAttribute('data-timer-count'));
}

export async function expectRunningTimers(page: Page, count: number) {
    await expect(timerPill(page)).toHaveAttribute('data-timer-count', String(count));
    await expect(timerPill(page)).toHaveAttribute(
        'data-timer-running',
        count > 0 ? 'true' : 'false',
    );
}

export async function openTimerTray(page: Page) {
    await timerTrigger(page).click();
    await expect(timerTray(page)).toBeVisible();

    return timerTray(page);
}

/** Leaves the actor with no running timer, whichever renderer is showing. */
export async function stopAllTimers(page: Page) {
    if ((await runningTimerCount(page)) === 0) {
        return;
    }

    const tray = await openTimerTray(page);
    const stops = tray.getByRole('button', { name: /^Stop timer/ });

    for (let remaining = await stops.count(); remaining > 0; remaining--) {
        await stops.first().click();
        await expect(stops).toHaveCount(remaining - 1);
    }

    await expectRunningTimers(page, 0);
}
