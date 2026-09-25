import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Sign in through the real login form. Fortify allows five attempts per minute per email,
 * and one full run of the suite signs in more often than that, so a throttled attempt (429
 * with Retry-After) waits out the window instead of failing the test that happened to be
 * sixth. The test timeout is extended by the wait.
 */
export async function signIn(page: Page, email: string, password = 'password') {
    for (let attempt = 0; attempt < 3; attempt++) {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password').fill(password);

        const [response] = await Promise.all([
            page.waitForResponse(
                (candidate) =>
                    new URL(candidate.url()).pathname === '/login' &&
                    candidate.request().method() === 'POST',
            ),
            page.getByRole('button', { name: 'Sign in' }).click(),
        ]);

        if (response.status() === 429) {
            const waitMs = (Number(response.headers()['retry-after'] ?? 60) + 1) * 1000;
            test.info().setTimeout(test.info().timeout + waitMs);
            await page.waitForTimeout(waitMs);
            continue;
        }

        await expect(page).toHaveURL(/\/dashboard$/);

        return;
    }

    throw new Error(`Sign in as ${email} stayed rate limited.`);
}
