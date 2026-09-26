import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Reusable authenticated browser state for the browser suite (EPIC-013 WP4 remediation).
 *
 * **Why this exists.** Every spec used to submit the real login form once per test — 64 call sites,
 * 53 of them as the operator. Fortify throttles logins to five per minute per email + IP, and the
 * serial suite drove 9–18 attempts a minute, so it spent most of a run being rate limited: 114
 * `POST /login` requests, 45 of them HTTP 429, and two tests failed outright having exhausted every
 * retry. The suite was structurally overrunning the limiter; WP4's own spec added 13 logins and made
 * an already-marginal architecture fail.
 *
 * **What replaces it.** The `setup` project authenticates once per persona through the real login
 * form and saves the resulting cookies. Ordinary feature specs start from that state and never touch
 * the login form. Tests whose *subject* is authentication still drive the form, because
 * pre-authenticating them would hide exactly the regressions they exist to catch.
 *
 * The saved state is **cookies only** — deliberately. A captured `localStorage` would bake this
 * shell's own UI preferences (the appearance theme, and any drawer/pin state) into the baseline every
 * spec inherits, so the drawer-persistence tests could no longer establish their own initial
 * conditions. The baseline represents *authentication*, nothing else.
 */
export type Persona = 'operator' | 'member';

/**
 * The two capability profiles the suite genuinely needs. They are **not** interchangeable: `member`
 * holds the built-in `user` role, and the specs that assert capability filtering, read-only boards
 * and non-owner permissions depend on being that actor rather than an operator. Collapsing them onto
 * the operator would delete the authorization coverage, not just the logins.
 */
export const personas: Record<Persona, { email: string; storageState: string }> = {
    operator: {
        email: 'operator@intechral.test',
        storageState: 'tests/Browser/.auth/operator.json',
    },
    member: {
        email: 'user@intechral.test',
        storageState: 'tests/Browser/.auth/member.json',
    },
};

/**
 * Open an authenticated page without submitting the login form.
 *
 * The persona comes from the context's `storageState` — the project default, or a `test.use()`
 * override — so this only navigates and then proves the reused state is still good. A stale or
 * missing state lands on `/login`, and this fails there with a clear reason rather than deeper in
 * the spec as a confusing assertion about page content.
 */
export async function signedIn(page: Page, path = '/dashboard') {
    await page.goto(path);

    await expect(
        page,
        'Reused authentication state did not resolve to an authenticated page. Re-run the suite so ' +
            'the `setup` project regenerates it.',
    ).not.toHaveURL(/\/login$/);
}
