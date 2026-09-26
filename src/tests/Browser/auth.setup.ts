import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

import { expect, test as setup } from '@playwright/test';

import { personas, type Persona } from './support/auth';
import { signIn } from './support/sign-in';

/**
 * The `setup` Playwright project: one real authentication per persona, whose cookies every ordinary
 * feature spec then reuses (see `support/auth.ts` for why).
 *
 * It runs on every invocation because the `chromium` project depends on it, so the state is always
 * freshly minted from a real login. There is no cache to go stale, no expiry window to reason about,
 * and nothing to invalidate by hand.
 */
for (const persona of Object.keys(personas) as Persona[]) {
    setup(`authenticate ${persona}`, async ({ page }) => {
        const { email, storageState } = personas[persona];

        // The real login form, through the real Fortify route — the state has to come from a genuine
        // authentication event, never a forged or hard-coded session cookie.
        await signIn(page, email);

        const state = await page.context().storageState();

        expect(
            state.cookies.length,
            `${persona} authentication produced no cookies`,
        ).toBeGreaterThan(0);

        // Cookies only. `localStorage` at this point already holds the appearance theme the shell
        // writes on mount, and would hold any drawer or pin state too; baking that into the baseline
        // would hand every spec a preconfigured shell and break the persistence tests' ability to set
        // their own initial conditions.
        const authenticationOnly = { cookies: state.cookies, origins: [] };

        await mkdir(dirname(storageState), { recursive: true });
        await writeFile(storageState, JSON.stringify(authenticationOnly, null, 2));
    });
}
