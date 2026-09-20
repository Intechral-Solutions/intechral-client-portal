import { expect, test } from '@playwright/test';

async function signIn(
    page: import('@playwright/test').Page,
    email = 'operator@intechral.test',
) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('Dashboard and Profile coexist with Blade pages and a persistent theme', async ({ page }) => {
    await signIn(page);

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
    await signIn(page);
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
