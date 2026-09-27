import { defineConfig, devices } from '@playwright/test';

import { baseURL } from './tests/Browser/support/env';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
    // Authenticated sessions are minted per worker by the `sessions` fixture in
    // tests/Browser/support/auth.ts: a worker mints a persona's session at most once, however many
    // spec files it goes on to run, so this caps how many real logins the `operator`/`member`
    // personas' Fortify buckets (5 POST /login per minute, per email+IP) can ever see in one run to
    // at most `workers` — regardless of machine core count or how many spec files need that persona.
    // Explicit rather than Playwright's core-count default so that isn't left to chance: 3 leaves
    // two logins of headroom under the limiter for each persona. See docs/testing/e2e-browser-suite.md.
    workers: 3,
    projects: [{ name: 'chromium' }],
});
