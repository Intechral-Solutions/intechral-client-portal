import type { Locator, Page } from '@playwright/test';

import { avoidSlotBoundary, expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import {
    expectRunningTimers,
    hasHorizontalOverflow,
    installShellShiftProbe,
    openAccountMenu,
    openTimerTray,
    railLink,
    readShellShift,
    stopAllTimers,
    timerPill,
    timerStop,
    timerTray,
    timerTrigger,
} from './support/shell';

// The first flows are the member's own time; the last group is an operator's. The persona is the
// context's reusable authentication state, so it is declared per group rather than logged in per test.
//
// EPIC-013 WP6 note: this file is the SINGLE OWNER of the member's active-timer set. Timers are
// global per user, and Playwright runs spec files concurrently across its three workers, so a
// second member spec starting or stopping timers would race this one — an exact-count assertion
// here would see the other file's timer, and `stopAllTimers` would stop it mid-test. The WP6 pill
// and tray flows therefore live in the describe block at the foot of this file rather than in a
// spec of their own. No new persona was added for them: a third identity would be one more login
// against Fortify's per-email limiter for no behavioural gain.
test.use({ persona: 'member' });

function section(page: Page, heading: string): Locator {
    return page.getByRole('heading', { name: heading }).locator('..');
}

test('multiple timers persist through Inertia and reconstruct across Blade documents', async ({
    page,
}) => {
    await signedIn(page);
    await page.goto('/time');
    await stopAllTimers(page);

    const timerForm = section(page, 'Start a timer');
    await timerForm.getByLabel('Description').fill('Browser timer one');
    await timerForm.getByRole('button', { name: 'Start timer' }).click();
    await expectRunningTimers(page, 1);

    await timerForm.getByLabel('Description').fill('Browser timer two');
    await timerForm.getByRole('button', { name: 'Start timer' }).click();
    // WP6: one pill, showing the newest timer, with the rest behind a count — not a row each.
    await expectRunningTimers(page, 2);
    await expect(timerPill(page)).toContainText('Browser timer two');
    await expect(timerPill(page)).toContainText('+1');
    await expect(timerPill(page)).not.toContainText('Browser timer one');

    const reactClock = timerPill(page).locator('[data-timer-elapsed="wide"]');
    const initialClock = await reactClock.textContent();
    await expect(reactClock).not.toHaveText(initialClock ?? '', { timeout: 3000 });

    await openAccountMenu(page);
    await page.getByRole('menuitem', { name: 'Profile' }).click();
    await expect(page).toHaveURL(/\/profile$/);
    // The shell is persistent, so an Inertia visit must not disturb the timer state at all.
    await expectRunningTimers(page, 2);

    // Descriptions are edited in the tray (Direction D §12.2), saved through the same endpoint.
    const tray = await openTimerTray(page);
    const firstRow = tray.getByRole('listitem').filter({ hasText: 'Browser timer one' });
    await firstRow.getByRole('textbox').fill('Canonical browser timer');
    await firstRow.getByRole('textbox').press('Enter');
    await expect(
        tray.getByRole('listitem').filter({ hasText: 'Canonical browser timer' }),
    ).toBeVisible();

    await tray.getByRole('listitem').filter({ hasText: 'Canonical browser timer' })
        .getByRole('button', { name: /^Stop timer/ })
        .click();
    await expectRunningTimers(page, 1);
    await page.keyboard.press('Escape');

    // Projects is a React page as of EPIC-011E WP3, so the Blade-document leg of this journey moves
    // to the Helpdesk workspace (still Blade until EPIC-011F); it needs no fixture (§21, C4).
    // Helpdesk is a `document` destination, so this crosses the React -> Blade boundary.
    await railLink(page, 'Helpdesk').click();
    await expect(page).toHaveURL(/\/tickets$/);

    // The SAME selector finds the SAME state on the other renderer: one timer, still running. The
    // retired strip is gone and no second affordance took its place.
    await expectRunningTimers(page, 1);
    await expect(timerPill(page)).toHaveCount(1);
    await expect(page.locator('#timer-overlay')).toHaveCount(0);

    const bladeClock = timerPill(page).locator('[data-timer-elapsed="wide"]');
    const bladeInitial = await bladeClock.textContent();
    await expect(bladeClock).not.toHaveText(bladeInitial ?? '', { timeout: 3000 });

    // Back across the boundary through the Blade shell's own navigation, then into Time.
    await page.getByRole('link', { name: 'Home', exact: true }).first().click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await railLink(page, 'Time').click();
    await expect(page).toHaveURL(/\/time$/);
    await expectRunningTimers(page, 1);
    await timerStop(page).click();
    await expectRunningTimers(page, 0);

    // A timer stopped after a few seconds is stored as one minute. Re-saving it through the
    // edit form must keep that duration instead of demanding the 15-minute manual minimum.
    const timerRow = page.getByRole('row').filter({ hasText: 'Canonical browser timer' });
    await expect(timerRow).toContainText('1m');
    await timerRow.getByRole('button', { name: /Edit entry/ }).click();
    const editHours = timerRow.getByLabel('Hours');
    expect(await editHours.inputValue()).toBe('0.02');
    expect(await editHours.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(true);
    await timerRow.getByLabel('Description').fill('Short timer edited');
    await timerRow.getByRole('button', { name: 'Save entry' }).click();
    const editedRow = page.getByRole('row').filter({ hasText: 'Short timer edited' });
    await expect(editedRow).toContainText('1m');
});

test('manual entries and server-owned allocation remain usable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signedIn(page);
    await page.goto('/time');
    await stopAllTimers(page);

    const manualForm = section(page, 'Log time manually');
    await manualForm.getByLabel('Hours').fill('0.5');
    await manualForm.getByLabel('Description').fill('Browser manual entry');
    await manualForm.getByRole('button', { name: 'Log time' }).click();
    await expect(page.getByRole('status')).toContainText('Time entry logged.');

    const row = page.getByRole('row').filter({ hasText: 'Browser manual entry' });
    await row.getByRole('button', { name: /Edit entry/ }).click();
    await row.getByLabel('Description').fill('Browser manual entry updated');
    await row.getByRole('button', { name: 'Save entry' }).click();
    await expect(
        page.getByRole('row').filter({ hasText: 'Browser manual entry updated' }),
    ).toBeVisible();

    // Timer-derived durations are arbitrary minutes (50 min -> 0.83h); editing must not be
    // blocked by the quarter-hour step that only applies to new manual entries.
    const updatedRow = page.getByRole('row').filter({ hasText: 'Browser manual entry updated' });
    await updatedRow.getByRole('button', { name: /Edit entry/ }).click();
    const editHours = updatedRow.getByLabel('Hours');
    await editHours.fill('0.83');
    expect(await editHours.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(true);
    // The create form keeps its quarter-hour contract for the same value.
    await manualForm.getByLabel('Hours').fill('0.83');
    expect(
        await manualForm
            .getByLabel('Hours')
            .evaluate((input: HTMLInputElement) => input.validity.stepMismatch),
    ).toBe(true);
    await manualForm.getByLabel('Hours').fill('');
    await updatedRow.getByRole('button', { name: 'Save entry' }).click();
    await expect(
        page.getByRole('row').filter({ hasText: 'Browser manual entry updated' }),
    ).toContainText('50m');

    await page
        .getByRole('row')
        .filter({ hasText: 'Browser manual entry updated' })
        .getByRole('button', { name: /Delete entry/ })
        .click();
    await page.getByRole('button', { name: 'Delete entry', exact: true }).click();
    await expect(page.getByText('Browser manual entry updated')).toHaveCount(0);

    // Produce this test's own allocation data (two timers sharing one slot) rather than
    // depending on another test's leftovers; the fixture teardown deletes both entries.
    await avoidSlotBoundary(page);
    const timerForm = section(page, 'Start a timer');
    for (const [index, name] of ['Browser timer one', 'Browser timer two'].entries()) {
        await timerForm.getByLabel('Description').fill(name);
        await timerForm.getByRole('button', { name: 'Start timer' }).click();
        await expectRunningTimers(page, index + 1);
    }
    // Allocation blocks are built from whole seconds, so let both timers actually run.
    await page.waitForTimeout(2000);
    await stopAllTimers(page);

    await page.getByRole('link', { name: 'Allocation' }).click();
    const allocationInput = page.getByLabel('Browser timer two percentage').first();
    await expect(allocationInput).toBeVisible();

    // Sequential timers in one slot must already total exactly 100% (the finalizer keeps
    // the same invariant the editor enforces), not 200%.
    await expect(page.getByText('Total 100.0%').first()).toBeVisible();
    await allocationInput.fill('65');
    await allocationInput.press('Enter');
    await expect(page.getByRole('status')).toContainText('updated.');
    await expect(page.getByRole('status')).toContainText('Browser timer two is now 65%');

    // Keyboard focus must survive the server reconciliation instead of falling to <body>.
    await expect(allocationInput).toBeFocused();
    await expect(allocationInput).toHaveValue('65');

    // Tab to Save and activate it from the keyboard; focus stays on the button.
    await allocationInput.fill('60');
    await page.keyboard.press('Tab');
    const saveButton = allocationInput.locator('xpath=ancestor::form').getByRole('button', {
        name: 'Save',
    });
    await expect(saveButton).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status')).toContainText('Browser timer two is now 60%');
    await expect(saveButton).toBeFocused();

    await allocationInput.fill('65');
    await allocationInput.press('Enter');
    await expect(page.getByRole('status')).toContainText('Browser timer two is now 65%');
    await page.reload();
    await expect(page.getByLabel('Browser timer two percentage').first()).toHaveValue('65');

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflow).toBe(false);
});

test.describe('as an operator', () => {
    test.use({ persona: 'operator' });

    test('a timer started from the embedded Blade tracker is reconstructed by React', async ({
        page,
    }) => {
        await signedIn(page);
        await page.goto('/time');
    await stopAllTimers(page);

        // The embedded tracker's only remaining host is a ticket page (EPIC-011E WP7, §21: the
        // project task page it used to also live on is React as of this work package). Tickets have
        // no delete route and DevSeeder seeds none per run, so this uses the seeder's idempotent
        // fixture ticket (`TKT-E2E1`) rather than creating one here; teardown removes only the timer
        // entries this test creates, never the ticket.
        await page.goto('/tickets');
        await page
            .getByRole('row')
            .filter({ hasText: 'TKT-E2E1' })
            .getByRole('link', { name: 'View' })
            .click();
        await expect(page).toHaveURL(/\/tickets\/\d+$/);
        const ticketUrl = page.url();

        // Start from the Blade tracker (the request passes the hardened context validation). The
        // `timerStarted` event contract WP6 preserved is what lets the pill adopt it without a reload.
        await page.getByRole('button', { name: /Start Timer/ }).click();
        await expect(page.getByText('Timer running')).toBeVisible();
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('TKT-E2E1');

        // A document navigation into an Inertia page mounts React, which hydrates from Laravel.
        await page.goto('/time');
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('TKT-E2E1');
        // The retired strip is gone from both renderers, and no duplicate affordance exists.
        await expect(page.locator('#timer-overlay')).toHaveCount(0);
        await expect(timerPill(page)).toHaveCount(1);

        // And back to Blade: the same server record, shown by the Blade pill.
        await page.goto(ticketUrl);
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('TKT-E2E1');
        await expect(page.getByText('Timer running')).toBeVisible();

        // Stop through React so the timer is finished before cleanup runs.
        await page.goto('/time');
        await timerStop(page).click();
        await expectRunningTimers(page, 0);

        // Stopped state survives the crossing too: the embedded tracker offers Start again.
        await page.goto(ticketUrl);
        await expect(page.getByRole('button', { name: /Start Timer/ })).toBeVisible();
        await expectRunningTimers(page, 0);

        // E2eCleanup already recorded this timer's entry id from the `/time/timer/start` response
        // and removes it in teardown; the fixture ticket itself has no delete route and is not
        // removed, so the next run's `firstOrCreate` finds it already there.
    });
});

test.describe('the Direction D timer pill and tray (WP6)', () => {
    /** Starts a timer through the Time screen's existing start form, which WP6 did not change. */
    async function startTimer(page: Page, description: string) {
        const form = section(page, 'Start a timer');
        await form.getByLabel('Description').fill(description);
        await form.getByRole('button', { name: 'Start timer' }).click();
    }

    test('a full page load paints no timer control until confirmed, and the shell never shifts', async ({
        page,
    }) => {
        // Direction D §15.1 on the React shell: the pill shows the last confirmed state, or nothing
        // on first load. Painting "Start timer" before the active set arrived, then the running pill,
        // moved the pill inside the utility bar (hosted CI, 2026-09-29: shellShift
        // 0.0010702674897119342 from [data-shell-timer], 1317,9,107,30 -> 1115,8,309,32). This file
        // owns the member's timers, so both states are set here, deterministically.
        await page.setViewportSize({ width: 1440, height: 900 });
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await installShellShiftProbe(page);

        // Confirmed idle, on a genuine full-document load.
        await page.goto('/dashboard');
        await expectRunningTimers(page, 0);
        await expect(timerTrigger(page)).toHaveAccessibleName('Start timer');
        let shift = await readShellShift(page);
        expect(shift.value, `shell shift sources: ${shift.sources.join('; ')}`).toBe(0);

        // Confirmed running, with a label long enough to take the pill to its real width.
        await page.goto('/time');
        await avoidSlotBoundary(page);
        await startTimer(page, 'First paint regression with a long description');
        await expectRunningTimers(page, 1);

        await page.goto('/dashboard');
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('First paint regression');
        shift = await readShellShift(page);
        expect(shift.value, `shell shift sources: ${shift.sources.join('; ')}`).toBe(0);

        // Stop through the pill so the timer is finished before cleanup runs.
        await timerStop(page).click();
        await expectRunningTimers(page, 0);
    });

    test('a full Blade page load paints no timer control until confirmed, and the shell never shifts', async ({
        page,
    }) => {
        // The Blade twin of the test above, on `/tickets` (a Blade page the member reaches through the
        // Helpdesk rail item). The partial used to server-render a visible "Start timer" and then grew
        // it into the running pill after its first read: 0.0010528120713305898, 1317,9,107,30 ->
        // 1117,8,307,32. The root now renders hidden and is revealed once that read has a final
        // result, so its becoming visible is the confirmation signal. Still the member's timers, still
        // this file's ownership: no operator timer, no mocked `/time/timers/active`.
        await page.setViewportSize({ width: 1440, height: 900 });
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await installShellShiftProbe(page);

        // Confirmed idle, on a genuine full-document load of a Blade page.
        await page.goto('/tickets');
        await expect(timerPill(page)).toBeVisible();
        await expectRunningTimers(page, 0);
        await expect(timerTrigger(page)).toHaveAccessibleName('Start timer');
        let shift = await readShellShift(page);
        expect(shift.value, `shell shift sources: ${shift.sources.join('; ')}`).toBe(0);

        // Confirmed running, with a label long enough to take the pill to its real width.
        await page.goto('/time');
        await avoidSlotBoundary(page);
        await startTimer(page, 'Blade first paint regression with a long description');
        await expectRunningTimers(page, 1);

        await page.goto('/tickets');
        await expect(timerPill(page)).toBeVisible();
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('Blade first paint regression');
        shift = await readShellShift(page);
        expect(shift.value, `shell shift sources: ${shift.sources.join('; ')}`).toBe(0);

        // Stop through the pill so the timer is finished before cleanup runs.
        await timerStop(page).click();
        await expectRunningTimers(page, 0);
    });

    test('the pill is the one global timer affordance, on both renderers', async ({ page }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);

        // React, idle: a ghost start affordance, no running state disclosed.
        await expect(timerPill(page)).toHaveCount(1);
        await expectRunningTimers(page, 0);
        await expect(timerTrigger(page)).toHaveAccessibleName('Start timer');
        await expect(timerStop(page)).toBeHidden();

        // The retired presentations are gone from the React shell.
        await expect(page.locator('#timer-overlay')).toHaveCount(0);
        await expect(page.getByRole('region', { name: 'Active timers' })).toHaveCount(0);

        // The pill lives inside the sticky utility bar, which is what retires the A10.16 stacking defect.
        await expect(page.locator('[data-shell-utility] [data-shell-timer]')).toHaveCount(1);

        // Blade, idle: the same single affordance, same hooks, same absent strip.
        await railLink(page, 'Helpdesk').click();
        await expect(page).toHaveURL(/\/tickets$/);
        await expect(timerPill(page)).toHaveCount(1);
        await expectRunningTimers(page, 0);
        await expect(page.locator('[data-shell-utility] [data-shell-timer]')).toHaveCount(1);
        await expect(page.locator('#timer-overlay')).toHaveCount(0);
    });

    test('a running timer survives both renderer crossings with its identity intact', async ({
        page,
    }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Crossing timer');
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('Crossing timer');

        // The elapsed clock is ticking before we go anywhere.
        const elapsed = timerPill(page).locator('[data-timer-elapsed="wide"]');
        const before = await elapsed.textContent();
        await expect(elapsed).not.toHaveText(before ?? '', { timeout: 3000 });

        // ── React -> Blade, a full document navigation ──
        await railLink(page, 'Helpdesk').click();
        await expect(page).toHaveURL(/\/tickets$/);

        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('Crossing timer');
        // Elapsed time stays coherent: it is derived from the server's start instant, so a fresh document
        // reconstructs the same duration rather than restarting from zero.
        const bladeElapsed = timerPill(page).locator('[data-timer-elapsed="wide"]');
        await expect(bladeElapsed).not.toHaveText('0:00:00');
        const bladeBefore = await bladeElapsed.textContent();
        await expect(bladeElapsed).not.toHaveText(bladeBefore ?? '', { timeout: 3000 });
        // No second presentation survived the crossing.
        await expect(timerPill(page)).toHaveCount(1);
        await expect(page.locator('#timer-overlay')).toHaveCount(0);

        // ── Blade -> React, back the other way ──
        await railLink(page, 'Time').click();
        await expect(page).toHaveURL(/\/time$/);

        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('Crossing timer');
        await expect(timerPill(page).locator('[data-timer-elapsed="wide"]')).not.toHaveText('0:00:00');

        // ── Back/Forward: the timer is server state, so history navigation cannot invent or lose it ──
        await page.goBack();
        await expect(page).toHaveURL(/\/tickets$/);
        await expectRunningTimers(page, 1);
        await page.goForward();
        await expect(page).toHaveURL(/\/time$/);
        await expectRunningTimers(page, 1);

        // ── Stop, then cross: it stays stopped on the other renderer ──
        await timerStop(page).click();
        await expectRunningTimers(page, 0);

        await railLink(page, 'Helpdesk').click();
        await expect(page).toHaveURL(/\/tickets$/);
        await expectRunningTimers(page, 0);
        await expect(timerTrigger(page)).toHaveAccessibleName('Start timer');
    });

    test('several timers collapse into one pill with the newest shown and the rest in the tray', async ({
        page,
    }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Older timer');
        await expectRunningTimers(page, 1);
        await startTimer(page, 'Newer timer');
        await expectRunningTimers(page, 2);

        // Never more than one timer inline, and never wider than Direction D's ~360px ceiling.
        await expect(timerPill(page)).toContainText('Newer timer');
        await expect(timerPill(page)).toContainText('+1');
        await expect(timerPill(page)).not.toContainText('Older timer');
        expect((await timerPill(page).boundingBox())!.width).toBeLessThanOrEqual(360);

        // The tray lists every running timer, newest first.
        const tray = await openTimerTray(page);
        await expect(tray).toContainText('2 running');
        const rows = tray.getByRole('listitem');
        await expect(rows).toHaveCount(2);
        await expect(rows.nth(0)).toContainText('Newer timer');
        await expect(rows.nth(1)).toContainText('Older timer');

        // Foundation scope only: no Stop all, no "Today N logged", no "Start another…" search (§12.0).
        await expect(tray.getByRole('button', { name: /Stop all/i })).toHaveCount(0);
        await expect(tray.getByText(/logged/i)).toHaveCount(0);

        // Stopping one from the tray leaves the other running, and the pill reconciles from the server.
        await rows
            .filter({ hasText: 'Older timer' })
            .getByRole('button', { name: /^Stop timer/ })
            .click();
        await expectRunningTimers(page, 1);
        await expect(timerPill(page)).toContainText('Newer timer');
        await expect(timerPill(page)).not.toContainText('+1');

        await page.keyboard.press('Escape');
        await stopAllTimers(page);
    });

    test('the tray is keyboard-operable and returns focus to the pill', async ({ page }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Keyboard timer');
        await expectRunningTimers(page, 1);

        // Opened from the keyboard, not the mouse.
        await timerTrigger(page).focus();
        await page.keyboard.press('Enter');
        await expect(timerTray(page)).toBeVisible();
        await expect(timerTrigger(page)).toHaveAttribute('aria-expanded', 'true');

        // Focus lands on the tray, not on a row's description field: opening the tray to press Stop must
        // not drop the caret into a text box.
        await expect(timerTray(page)).toBeFocused();

        // Escape closes it and hands focus back to the pill, where the user left it.
        await page.keyboard.press('Escape');
        await expect(timerTray(page)).toBeHidden();
        await expect(timerTrigger(page)).toBeFocused();
        await expect(timerTrigger(page)).toHaveAttribute('aria-expanded', 'false');

        // The pill's own controls are reachable by plain Tab, and are real buttons in document order.
        await timerTrigger(page).focus();
        await page.keyboard.press('Tab');
        await expect(timerStop(page)).toBeFocused();

        // A clock that changes every second is never inside a live region (§13).
        expect(
            await timerPill(page)
                .locator('[data-timer-elapsed="wide"]')
                .evaluate((node) => node.closest('[aria-live]') !== null),
        ).toBe(false);

        await stopAllTimers(page);
    });

    test('a description edited in the tray reaches the server and survives a crossing', async ({
        page,
    }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Before the edit');
        await expectRunningTimers(page, 1);

        const tray = await openTimerTray(page);
        const field = tray.getByRole('listitem').first().getByRole('textbox');
        await expect(field).toHaveValue('Before the edit');

        // Escape with an unsaved draft reverts the draft and leaves the tray open...
        await field.fill('Abandoned draft');
        await page.keyboard.press('Escape');
        await expect(field).toHaveValue('Before the edit');
        await expect(timerTray(page)).toBeVisible();

        // ...and a committed edit goes through the canonical endpoint.
        await field.fill('After the edit');
        await field.press('Enter');
        await expect(timerTray(page).getByRole('listitem').first()).toContainText('After the edit');

        await page.keyboard.press('Escape');

        // The edit was server state, so the Blade renderer shows it after a document navigation.
        await railLink(page, 'Helpdesk').click();
        await expect(page).toHaveURL(/\/tickets$/);
        await expectRunningTimers(page, 1);

        const bladeTray = await openTimerTray(page);
        await expect(bladeTray.getByRole('listitem').first()).toContainText('After the edit');

        await page.keyboard.press('Escape');
        await stopAllTimers(page);
    });

    test('the pill stays usable and within the viewport on a narrow shell', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'A description long enough to need truncating on a narrow shell');
        await expectRunningTimers(page, 1);

        // The narrow form is dot + H:MM: the seconds and the context label are CSS-hidden at S, so the
        // bar cannot overflow. Which form shows is a width-class decision, never a JS one.
        await expect(timerPill(page).locator('[data-timer-elapsed="narrow"]')).toBeVisible();
        await expect(timerPill(page).locator('[data-timer-elapsed="wide"]')).toBeHidden();
        await expect(timerPill(page).locator('[data-timer-label]')).toBeHidden();
        expect(await hasHorizontalOverflow(page)).toBe(false);

        // Targets stay tappable (§16, WCAG 2.5.8).
        expect((await timerTrigger(page).boundingBox())!.height).toBeGreaterThanOrEqual(44);
        expect((await timerStop(page).boundingBox())!.height).toBeGreaterThanOrEqual(44);

        // The tray is still reachable, fits the viewport, and does not push the page sideways.
        const tray = await openTimerTray(page);
        const box = (await tray.boundingBox())!;
        expect(box.width).toBeLessThanOrEqual(390);
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(await hasHorizontalOverflow(page)).toBe(false);

        // Everything the narrow pill dropped is still available here — undrawn, never unreachable.
        await expect(tray.getByRole('listitem').first()).toContainText('A description long enough');

        await page.keyboard.press('Escape');
        await stopAllTimers(page);
    });

    test('the Blade pill hides its narrow clock when it is not counting, at S', async ({ page }) => {
        // The narrow test above is React-only, and jsdom cannot see any of this, so until now nothing
        // exercised the Blade pill at S with real CSS applied. What it pins is the cascade that makes
        // Blade's `hidden` ATTRIBUTE authoritative over the S rule that shows the narrow elapsed span:
        // the two hand-written rules tie on specificity and the S one is later, so the attribute wins
        // only because Tailwind's preflight emits `[hidden]:where(…){display:none!important}`. That is
        // a real dependency on a third-party stylesheet, and a Tailwind upgrade that dropped the
        // `!important`, or a `display` utility added to the span, would silently strip a stale clock
        // out from behind "Stopping…" and "Start timer" — `blade-timer.ts` never clears the text, it
        // only hides it. This test is the thing that would notice.
        await page.setViewportSize({ width: 390, height: 844 });
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Blade narrow timer');
        await expectRunningTimers(page, 1);

        // Cross to a Blade document, where the pill is driven by `shell/blade-timer.ts`.
        await page.goto('/tickets');
        await expectRunningTimers(page, 1);

        const narrow = timerPill(page).locator('[data-timer-elapsed="narrow"]');
        const wide = timerPill(page).locator('[data-timer-elapsed="wide"]');

        // Running: the narrow form is the one that shows, exactly as on React. This half guards the
        // fix from over-correcting into hiding the clock that is supposed to be there.
        await expect(narrow).toBeVisible();
        await expect(wide).toBeHidden();
        await expect(narrow).not.toHaveText('');

        // A test-only network delay on the stop response (nothing about the product's own timing
        // changes) so the pending window is wide enough to observe in a real browser, rather than
        // racing a request a local stack answers in milliseconds. Same idiom as board-drag.spec.ts.
        await page.route('**/time/timer/*/stop', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.continue();
        });

        await timerStop(page).click();

        // Pending: "Stopping…" and NO elapsed value beside it (Direction D §12.1). The span still
        // holds the last value written into it, so this asserts the hiding, not the clearing.
        await expect(timerPill(page).locator('[data-timer-pending]')).toHaveText('Stopping…');
        await expect(narrow).toBeHidden();
        await expect(wide).toBeHidden();

        // Stopped: back to the idle affordance, with no stale clock left beside it. This is the
        // half that would persist rather than flicker — the text stays in the DOM until the next
        // document load, so only the hiding keeps it off the screen.
        await expectRunningTimers(page, 0);
        await expect(timerTrigger(page)).toHaveAccessibleName('Start timer');
        await expect(narrow).toBeHidden();
        await expect(wide).toBeHidden();
        await expect(timerStop(page)).toBeHidden();
        expect(await hasHorizontalOverflow(page)).toBe(false);
    });

    test('the tray draws above the sticky shell chrome on the Blade renderer', async ({ page }) => {
        await signedIn(page);
        await page.goto('/time');
        await stopAllTimers(page);
        await avoidSlotBoundary(page);

        await startTimer(page, 'Stacking timer');
        await expectRunningTimers(page, 1);

        await railLink(page, 'Helpdesk').click();
        await expect(page).toHaveURL(/\/tickets$/);

        const tray = await openTimerTray(page);
        const box = (await tray.boundingBox())!;

        // Blade has no portal and the utility bar is a stacking context (A10.12 #1), so this is the
        // assertion that matters: the point just inside the open tray must actually hit the tray, not
        // whatever page content sits underneath it.
        const topmost = await page.evaluate(
            ({ x, y }) => {
                const element = document.elementFromPoint(x, y);

                return element?.closest('[data-shell-timer-tray]') !== null;
            },
            { x: box.x + box.width / 2, y: box.y + 12 },
        );

        expect(topmost).toBe(true);

        await page.keyboard.press('Escape');
        await stopAllTimers(page);
    });
});
