import { defineConfig, devices } from '@playwright/test';

import { personas } from './tests/Browser/support/auth';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:4242',
        trace: 'retain-on-failure',
    },
    projects: [
        // One real authentication per persona, whose cookies the feature specs reuse. See
        // tests/Browser/support/auth.ts: the suite used to submit the login form 64 times and spent
        // most of a run throttled by Fortify's five-per-minute limiter.
        {
            name: 'setup',
            testMatch: /auth\.setup\.ts/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            use: {
                ...devices['Desktop Chrome'],
                // The operator is the persona most specs need. A spec wanting the member profile, or
                // an unauthenticated start, overrides this with `test.use({ storageState: … })`.
                storageState: personas.operator.storageState,
            },
        },
    ],
});
