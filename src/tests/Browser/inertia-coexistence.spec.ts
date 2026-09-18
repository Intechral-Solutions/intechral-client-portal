import { expect, test } from '@playwright/test';

async function signIn(page: import('@playwright/test').Page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill('operator@intechral.test');
    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
}

test('Blade and Inertia coexist with persistent theme and server flash', async ({ page }) => {
    await signIn(page);

    await page.getByRole('link', { name: 'Frontend foundation smoke proof' }).click();
    await expect(page.getByRole('heading', { name: 'React foundation is running' })).toBeVisible();

    await page.getByRole('link', { name: 'Inertia visit' }).click();
    await expect(page).toHaveURL(/\/inertia-smoke\?refreshed=1$/);

    await page.getByRole('button', { name: 'Switch to dark theme' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.getByRole('button', { name: 'Test redirect flash' }).click();
    await expect(page.getByRole('status')).toContainText('Foundation flash message received.');

    await page.getByRole('link', { name: 'Open Blade dashboard' }).click();
    await expect(page).toHaveURL(/\/dashboard\?source=inertia-smoke$/);
    await expect(page.getByRole('heading', { name: /Welcome back/ })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});

test('mobile navigation renders permission-aware links', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);
    await page.goto('/inertia-smoke');
    await page.getByRole('button', { name: 'Open navigation menu' }).click();

    await expect(page.getByRole('navigation', { name: 'Mobile navigation' })).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Mobile navigation' })).toContainText(
        'Projects',
    );
});
