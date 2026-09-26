import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Sign in through the real login form, exercising the real Fortify route.
 *
 * **Narrowed role.** This used to be called once per test by every spec — 64 times a run, which is
 * what made the suite overrun Fortify's five-per-minute limiter. It now has two callers only:
 *
 * 1. `auth.setup.ts`, once per persona, to mint the reusable authenticated state; and
 * 2. specs whose *subject* is authentication, which must keep driving the real form — the
 *    invalid-credentials and logout transitions, the fresh-authentication password confirmation,
 *    the account mutation that would otherwise poison the shared baseline, and the sign-out that
 *    would otherwise invalidate a shared session server-side (sessions are database-backed, so a
 *    logout destroys the session row every other context would be presenting).
 *
 * Ordinary feature specs call `signedIn()` in `support/auth.ts` instead and never authenticate.
 *
 * The 429 handling stays: authentication-focused tests deliberately spend limiter budget (one of
 * them submits a wrong password on purpose), so a throttled attempt must still wait out its
 * `Retry-After` rather than fail the test that happened to be next. It is not a workaround for the
 * suite's login volume any more — that is what the reusable state fixed.
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
