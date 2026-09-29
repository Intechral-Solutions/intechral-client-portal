import { expect, test } from './support/auth';
import { signIn } from './support/sign-in';
import { openAccountMenu } from './support/shell';

/**
 * Authentication is this file's subject, so it starts signed out: invalid
 * credentials, the logout transition and the fresh-authentication password confirmation all have to
 * drive the real Fortify route. Pre-authenticating them would hide the regressions they exist to
 * catch, and they are also intentional consumers of limiter budget.
 *
 * They authenticate as `e2e-login-flow@intechral.test`, a dedicated fixture (DevSeeder), not the
 * reusable `operator` persona: this file's own login/relogin/logout sequence already spends most of
 * a Fortify window (five POSTs/minute per email+IP) on its own, and every other worker that needs the
 * operator persona mints its own real session too (support/auth.ts) — sharing one identity between
 * that and this file's login churn would reintroduce the exact limiter collision the per-worker
 * session design exists to avoid. No permission assertion here depends on which account this is.
 */
test.use({ persona: 'anonymous' });

test('React login reports invalid credentials and logout returns to the auth shell', async ({
    page,
}) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.getByLabel('Email address').fill('e2e-login-flow@intechral.test');
    await page.getByLabel('Password').fill('incorrect');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByLabel('Email address')).toHaveAccessibleDescription(/credentials/i);

    await page.getByLabel('Password').fill('password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await openAccountMenu(page);
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
    await signIn(page, 'e2e-login-flow@intechral.test');
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
