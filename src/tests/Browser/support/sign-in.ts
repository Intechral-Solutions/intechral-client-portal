import { mkdirSync, readFileSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { E2E_TOTP_SECRET, TOTP_STEP_SECONDS, totp } from './totp';

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
 * **Second factor (Release 1.0).** The seeded operator personas carry a confirmed second factor (mandatory for operators), so their sign-in ends at
 * Fortify's challenge. This helper answers it with a TOTP code from the development secret. Fortify refuses a code it has already accepted, and several workers mint the same persona at the start of a run, so each claims a later step (see `claimCode`).
 * Accounts without a second factor never see the challenge and are unaffected.
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

        await expect(page).toHaveURL(/\/(dashboard|two-factor-challenge)$/);

        if (new URL(page.url()).pathname === '/two-factor-challenge') {
            await answerChallenge(page);
        }

        await expect(page).toHaveURL(/\/dashboard$/);

        return;
    }

    throw new Error(`Sign in as ${email} stayed rate limited.`);
}

/**
 * Fortify caches each accepted code (per code value, shared by every account) and refuses a repeat, and it accepts only the current 30 s step and one
 * either side. Several workers sign the same persona in at once and would draw the SAME code, so each reserves a distinct, strictly later step (a short
 * file lock around a shared counter in the temp directory) and then, OUTSIDE the lock, waits until its step is inside the window. Retrying a refused code
 * instead would spend the account's two-factor limiter (five a minute, per ACCOUNT, shared by every worker) and cascade into 429s.
 */
async function claimCode(): Promise<string> {
    const lock = join(tmpdir(), 'portal-e2e-totp.lock');
    const counter = join(tmpdir(), 'portal-e2e-totp.step');
    const stepMs = TOTP_STEP_SECONDS * 1000;

    for (let waited = 0; ; waited += 25) {
        try {
            mkdirSync(lock);
            break;
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error;
            try {
                // The lock is held for milliseconds; one this old belongs to a worker that died. Reclaim it by RENAMING it away to a unique name (atomic:
                // of several waiters exactly one succeeds), never by deleting it in place, which could remove a fresh lock another waiter just took.
                if (Date.now() - statSync(lock).mtimeMs > 15_000) {
                    const dead = `${lock}.dead-${process.pid}-${Date.now()}`;
                    renameSync(lock, dead);
                    rmSync(dead, { recursive: true, force: true });
                }
            } catch {
                // released, or reclaimed by another waiter, meanwhile
            }
            if (waited > 60_000) throw new Error('Timed out waiting for the TOTP step lock.');
            await new Promise((resolve) => setTimeout(resolve, 25));
        }
    }

    let step: number;
    try {
        const current = Math.floor(Date.now() / stepMs);
        let last = 0;
        try {
            last = Number(readFileSync(counter, 'utf8')) || 0;
        } catch {
            // first reservation of the run
        }
        // A counter far ahead of the clock cannot be genuine (a clock that went back): ignore it.
        if (last > current + 20) last = 0;

        step = Math.max(current, last + 1);
        writeFileSync(counter, String(step));
    } finally {
        rmSync(lock, { recursive: true, force: true });
    }

    // The step is usable once the clock is within one step of it (the server accepts the next step too). The wait counts against the test.
    const waitMs = Math.max(0, (step - 1) * stepMs - Date.now() + 500);
    if (waitMs > 0) {
        test.info().setTimeout(test.info().timeout + waitMs);
        await new Promise((resolve) => setTimeout(resolve, waitMs));
    }

    return totp(E2E_TOTP_SECRET, step * stepMs);
}

/** Answer the two-factor challenge with the development secret. */
async function answerChallenge(page: Page) {
    const code = await claimCode();
    await page.getByLabel('Authentication code').fill(code);

    const [response] = await Promise.all([
        page.waitForResponse(
            (candidate) =>
                new URL(candidate.url()).pathname === '/two-factor-challenge' &&
                candidate.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Verify' }).click(),
    ]);

    if (response.status() === 429) {
        throw new Error(
            'The two-factor challenge was rate limited (HTTP 429): Fortify allows five a minute per account, shared by every worker.',
        );
    }
}
