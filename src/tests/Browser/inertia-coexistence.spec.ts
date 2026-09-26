import { expect, test } from '@playwright/test';

import { personas, signedIn } from './support/auth';
import { signIn } from './support/sign-in';
import { accountTrigger, drawerLink, openAccountMenu, railLink } from './support/shell';

test('Dashboard and Profile coexist with Blade pages and a persistent theme', async ({ page }) => {
    await signedIn(page);

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await page.getByRole('link', { name: 'My Profile' }).click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();

    // WP4: the standalone header toggle is gone; Appearance lives in the account menu (§17.2).
    await openAccountMenu(page);
    await page.getByRole('radio', { name: 'dark' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await railLink(page, 'Projects').click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.getByRole('heading', { name: 'Projects', level: 1 })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await railLink(page, 'Home').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    // Time Reports moved out of the retired "Manage" account-menu group and into the Time
    // workspace's contextual navigation (§11.2). Time defaults to a collapsed panel, so the rail
    // toggle opens it first.
    await railLink(page, 'Time').click();
    await expect(page).toHaveURL(/\/time$/);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await drawerLink(page, 'Time', 'Reports').click();
    await page.getByLabel('Billing').selectOption('0');
    await page.getByRole('button', { name: 'Apply filters' }).click();
    await expect(page).toHaveURL(/billable=0/);
    await expect(page.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
        'href',
        /billable=0/,
    );

    // Draft controls do not change the export target until Apply is executed.
    await page.getByLabel('Billing').selectOption('1');
    await expect(page.getByRole('link', { name: 'Export CSV' })).toHaveAttribute(
        'href',
        /billable=0/,
    );
    await page.getByLabel('Billing').selectOption('0');

    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Export CSV' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/^time-export-\d{4}-\d{2}-\d{2}\.csv$/);
});

test.describe('as a member', () => {
    // The capability-filtering assertions below are about this actor's own profile, so the persona is
    // the point of the test, not incidental setup.
    test.use({ storageState: personas.member.storageState });

    test('narrow shell exposes permission-aware navigation through the nav sheet', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signedIn(page);

        // At S the rail becomes a 56px top bar and its workspace list folds into the sheet (§16). The
        // rail's own list is removed from the accessibility tree at this width, so the sheet is the
        // single set of workspace links rather than a duplicate.
        await expect(page.getByRole('navigation', { name: 'Workspaces' })).toBeHidden();
        // The account control stays reachable from the top bar itself, not only from the sheet. Asserted
        // before the sheet opens: it is a modal, so Radix correctly hides the rest of the page from the
        // accessibility tree while it is up.
        await expect(accountTrigger(page)).toBeVisible();

        await page.getByRole('button', { name: 'Open navigation' }).click();

        const sheet = page.getByRole('dialog');

        await expect(sheet).toBeVisible();
        await expect(sheet.getByRole('link', { name: 'Projects', exact: true })).toBeVisible();
        // Capability filtering is server-side, so what a customer cannot reach is simply absent.
        await expect(sheet.getByRole('link', { name: 'System', exact: true })).toHaveCount(0);
        await expect(sheet.getByRole('link', { name: 'Directory', exact: true })).toHaveCount(0);

        // Touch targets stay reachable at S (§16).
        const box = await sheet.getByRole('link', { name: 'Projects', exact: true }).boundingBox();

        expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
    });
});

test.describe('account mutation', () => {
    // This one authenticates for real and deliberately does NOT reuse the shared state: it edits the
    // operator's own name and email. Even though it restores them, a failure part-way would leave the
    // reusable baseline describing a different person for every later spec.
    test.use({ storageState: { cookies: [], origins: [] } });

    test('profile information reports validation and saves through Inertia', async ({ page }) => {
        await signIn(page, 'operator@intechral.test');
        await page.getByRole('link', { name: 'My Profile' }).click();

        const name = page.getByLabel('Name');
        const email = page.getByLabel('Email address');
        const originalName = await name.inputValue();
        const originalEmail = await email.inputValue();

        await name.fill('');
        await page.getByRole('button', { name: 'Save profile' }).click();
        await expect(name).toHaveAccessibleDescription(/required/i);

        await name.fill(originalName);
        await email.fill(originalEmail);
        await page.getByRole('button', { name: 'Save profile' }).click();
        await expect(page.getByRole('status')).toContainText('Profile information updated.');
    });
});
