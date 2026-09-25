import { expect, test } from '@playwright/test';

import { signIn } from './support/sign-in';

test('Dashboard and Profile coexist with Blade pages and a persistent theme', async ({ page }) => {
    await signIn(page, 'operator@intechral.test');

    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await page.getByRole('link', { name: 'My Profile' }).click();
    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByRole('heading', { name: 'Profile', exact: true })).toBeVisible();

    await page.getByRole('button', { name: 'Switch to dark theme' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.getByRole('link', { name: 'Projects' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.getByRole('link', { name: 'Intechral Client Portal home' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    await page.getByRole('button', { name: 'Open user menu' }).click();
    await page.getByRole('menuitem', { name: 'Time Reports' }).click();
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

test('mobile navigation renders permission-aware links', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, 'user@intechral.test');
    await page.getByRole('button', { name: 'Open navigation menu' }).click();

    await expect(page.getByRole('navigation', { name: 'Mobile navigation' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Mobile navigation' })).toContainText(
        'Projects',
    );
});

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
