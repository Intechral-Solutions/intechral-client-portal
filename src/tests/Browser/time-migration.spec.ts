import type { Locator, Page } from '@playwright/test';

import { avoidSlotBoundary, expect, test } from './support/e2e-fixtures';
import { signIn } from './support/sign-in';

async function stopAllReactTimers(page: Page) {
    await page.goto('/time');
    await page.waitForLoadState('networkidle');

    while ((await page.getByRole('button', { name: 'Stop timer' }).count()) > 0) {
        const count = await page.getByRole('button', { name: 'Stop timer' }).count();
        await page.getByRole('button', { name: 'Stop timer' }).first().click();
        await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(count - 1);
    }
}

function section(page: Page, heading: string): Locator {
    return page.getByRole('heading', { name: heading }).locator('..');
}

test('multiple timers persist through Inertia and reconstruct across Blade documents', async ({
    page,
}) => {
    await signIn(page, 'user@intechral.test');
    await stopAllReactTimers(page);

    const timerForm = section(page, 'Start a timer');
    await timerForm.getByLabel('Description').fill('Browser timer one');
    await timerForm.getByRole('button', { name: 'Start timer' }).click();
    await expect(page.getByRole('region', { name: 'Active timers' })).toContainText(
        'Browser timer one',
    );

    await timerForm.getByLabel('Description').fill('Browser timer two');
    await timerForm.getByRole('button', { name: 'Start timer' }).click();
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(2);

    const firstClock = page
        .getByRole('region', { name: 'Active timers' })
        .locator('span.font-mono')
        .first();
    const initialClock = await firstClock.textContent();
    await expect(firstClock).not.toHaveText(initialClock ?? '', { timeout: 3000 });

    await page.getByRole('button', { name: 'Open user menu' }).click();
    await page.getByRole('menuitem', { name: 'Profile' }).click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(2);

    await page.getByRole('button', { name: 'Edit timer description: Browser timer one' }).click();
    await page
        .getByRole('textbox', { name: 'Timer description', exact: true })
        .fill('Canonical browser timer');
    await page.getByRole('button', { name: 'Save description' }).click();
    await expect(
        page.getByRole('button', {
            name: 'Edit timer description: Canonical browser timer',
        }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Stop timer' }).first().click();
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(1);

    // Projects is a React page as of EPIC-011E WP3, so the Blade-document leg of this journey
    // moves to Tickets (still Blade until EPIC-011F); it needs no fixture (§21, C4).
    await page.getByRole('link', { name: 'Tickets' }).click();
    await expect(page).toHaveURL(/\/tickets$/);
    await expect(page.locator('#timer-overlay [data-timer-id]')).toHaveCount(1);
    const bladeClock = page.locator('#timer-overlay .timer-clock');
    const bladeInitial = await bladeClock.textContent();
    await expect(bladeClock).not.toHaveText(bladeInitial ?? '', { timeout: 3000 });

    await page.getByRole('link', { name: 'Intechral Client Portal home' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await page.getByRole('link', { name: 'Time', exact: true }).click();
    await expect(page).toHaveURL(/\/time$/);
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(1);
    await page.getByRole('button', { name: 'Stop timer' }).click();
    await expect(page.getByRole('region', { name: 'Active timers' })).toHaveCount(0);

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
    await signIn(page, 'user@intechral.test');
    await stopAllReactTimers(page);

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
    for (const name of ['Browser timer one', 'Browser timer two']) {
        await timerForm.getByLabel('Description').fill(name);
        await timerForm.getByRole('button', { name: 'Start timer' }).click();
        await expect(page.getByRole('region', { name: 'Active timers' })).toContainText(name);
    }
    // Allocation blocks are built from whole seconds, so let both timers actually run.
    await page.waitForTimeout(2000);
    await page.getByRole('button', { name: 'Stop timer' }).first().click();
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(1);
    await page.getByRole('button', { name: 'Stop timer' }).click();
    await expect(page.getByRole('region', { name: 'Active timers' })).toHaveCount(0);

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

test('a timer started from the embedded Blade tracker is reconstructed by React', async ({
    page,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    await stopAllReactTimers(page);

    // The embedded tracker lives on a still-Blade task page. Create a throwaway project and
    // task for it; the fixture teardown deletes the timer entries and then the project.
    // (The create page is React as of WP3; the board and task page it lands on are still Blade.)
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill('E2E Blade tracker project');
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]);
    cleanup.trackProject(projectId);

    await page.locator('.add-task-btn').first().click();
    await page.getByPlaceholder('Task title…').first().fill('E2E Blade tracker task');
    await page.getByRole('button', { name: 'Add', exact: true }).first().click();
    await page.getByRole('link', { name: 'E2E Blade tracker task' }).click();
    await expect(page).toHaveURL(/\/projects\/\d+\/tasks\/\d+$/);
    const taskUrl = page.url();

    // Start from the Blade tracker (the request passes the hardened context validation).
    await page.getByRole('button', { name: /Start Timer/ }).click();
    await expect(page.getByText('Timer running')).toBeVisible();
    await expect(page.locator('#timer-overlay [data-timer-id]')).toHaveCount(1);
    await expect(page.getByRole('region', { name: 'Active timers' })).toHaveCount(0);

    // A document navigation into an Inertia page mounts React, which hydrates from Laravel.
    await page.goto('/time');
    const bar = page.getByRole('region', { name: 'Active timers' });
    await expect(bar).toContainText('Task: E2E Blade tracker task');
    await expect(page.locator('#timer-overlay')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Stop timer' })).toHaveCount(1);

    // And back to Blade: the same server record is shown by the legacy overlay.
    await page.goto(taskUrl);
    await expect(page.locator('#timer-overlay [data-timer-id]')).toHaveCount(1);
    await expect(page.getByText('Timer running')).toBeVisible();

    // Stop through React so the timer is finished before the fixture is removed.
    await page.goto('/time');
    await page.getByRole('button', { name: 'Stop timer' }).click();
    await expect(page.getByRole('region', { name: 'Active timers' })).toHaveCount(0);

    await page.goto(taskUrl);
    await expect(page.getByRole('button', { name: /Start Timer/ })).toBeVisible();
    await expect(page.locator('#timer-overlay [data-timer-id]')).toHaveCount(0);
});
