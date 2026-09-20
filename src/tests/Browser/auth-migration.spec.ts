import { expect, test } from '@playwright/test';

async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('operator@intechral.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('React login reports invalid credentials and logout returns to the auth shell', async ({
    page,
}) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.getByLabel('Email address').fill('operator@intechral.test');
    await page.getByLabel('Password').fill('incorrect');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByLabel('Email address')).toHaveAccessibleDescription(/credentials/i);

    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await page.getByRole('button', { name: 'Open user menu' }).click();
    await page.getByRole('menuitem', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
});

test('forgot password gives an enumeration-safe browser response', async ({ page }) => {
    await page.goto('/forgot-password');
    await page.getByLabel('Email address').fill('unknown@example.com');
    await page.getByRole('button', { name: 'Send reset link' }).click();

    await expect(page.getByRole('status')).toContainText(
        'If an account can use password sign-in, we have sent a password reset link.',
    );
});

test('password confirmation returns to Profile through the server intended URL', async ({
    page,
}) => {
    await signIn(page);
    await page.goto('/profile/confirm-password');
    await expect(page.getByRole('heading', { name: 'Confirm password' })).toBeVisible();

    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Confirm password' }).click();
    await expect(page).toHaveURL(/\/profile$/);
});

test('auth shell persists dark theme without mobile overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/login');
    await page.getByRole('button', { name: 'Switch to dark theme' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflow).toBe(false);
});
