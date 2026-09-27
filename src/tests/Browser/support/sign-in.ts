import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Sign in through the real login form, exercising the real Fortify route.
 *
 * Callers, deliberately few:
 *
 * 1. `support/auth.ts`, once per worker per persona, to mint that worker's own authenticated
 *    session; and
 * 2. specs whose *subject* is authentication, which must keep driving the real form — the
 *    invalid-credentials and logout transitions, the fresh-authentication password confirmation,
 *    the account mutation that would otherwise poison a persona's session, the sign-out that would
 *    otherwise destroy a session row other tests are presenting (sessions are database-backed), and
 *    the login of a user the test itself creates.
 *
 * Ordinary feature specs never call this; they receive an already-authenticated context from
 * `support/auth.ts`.
 *
 * The context must start signed out. If `/login` redirects away the context is already
 * authenticated — typically because it inherited an authenticated `storageState` — and the form this
 * helper needs will never render, so it fails there with that reason instead of timing out on a
 * `fill()` thirty seconds later.
 *
 * Fortify throttles logins to five POSTs per minute per email + IP, counting every request. Flows
 * that spend budget on purpose (one submits a wrong password) wait out a throttled attempt's
 * `Retry-After` rather than fail the test that happened to be next. Worker-session minting passes
 * `waitOutThrottle: false`: it must fail loudly, never pace itself against the limiter.
 */
export async function signIn(
    page: Page,
    email: string,
    password = 'password',
    { waitOutThrottle = true }: { waitOutThrottle?: boolean } = {},
) {
    for (let attempt = 0; attempt < 3; attempt++) {
        await page.goto('/login');
        await expect(
            page,
            'The login form did not render: /login redirected away, so this browser context is ' +
                'already authenticated. A context meant to sign in must start signed out — create ' +
                "it with `contextFor('anonymous')` or `test.use({ persona: 'anonymous' })`.",
        ).toHaveURL(/\/login$/);
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
            if (!waitOutThrottle) {
                throw new Error(
                    `Sign in as ${email} was rate limited (HTTP 429). Fortify allows five login ` +
                        'POSTs per minute per email + IP; the browser suite is spending more than that.',
                );
            }

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
