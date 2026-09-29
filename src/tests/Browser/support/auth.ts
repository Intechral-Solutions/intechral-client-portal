import { mkdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test as base } from '@playwright/test';
import type { Browser, BrowserContext, BrowserContextOptions, Page } from '@playwright/test';

import { baseURL } from './env';
import { signIn } from './sign-in';

/**
 * Authenticated browser sessions for the browser suite, one per **worker** per persona.
 *
 * **Why per worker.** Playwright runs one worker per spec file by default, all against one
 * database-backed Laravel session store. An earlier design minted a single session per persona per
 * run and handed the same cookie to every worker, so every worker read and wrote the *same session
 * row*. Flash messages and validation errors live in that row and survive exactly one later request,
 * so any worker's request could consume, overwrite or receive another worker's: a Projects page
 * rendering another spec's "Task created.", or losing its own "Project updated.". Sessions must
 * therefore not be shared across workers. (Before that, every test logged in for itself — 64 logins a
 * run against Fortify's five-per-minute limiter — which is what the reusable state replaced.)
 *
 * **How.** A worker-scoped fixture (`sessions`) signs each persona in through the real login form the
 * first time one of that worker's tests needs it, and keeps the resulting cookies in memory. The
 * `storageState` fixture hands them to every context the worker creates. Tests in one worker run
 * one at a time, so they safely share that worker's session; nothing crosses to another worker.
 * Nothing is written to disk and nothing is forged: each session is a genuine login.
 *
 * **Cookies only, deliberately.** The reusable state holds authentication and nothing else. A
 * captured `localStorage` would bake the shell's own UI preferences (appearance theme, drawer and
 * pin state) into the baseline every spec inherits, so the persistence tests could no longer set
 * their own initial conditions.
 *
 * **Choosing who a test is.** The default is the operator. `test.use({ persona: 'member' })` picks the
 * member profile and `test.use({ persona: 'anonymous' })` starts signed out. The two personas are not
 * interchangeable: `member` holds the built-in `user` role, and the specs asserting capability
 * filtering, read-only boards and non-owner permissions depend on being that actor.
 *
 * **Extra contexts.** A bare `browser.newContext()` inherits the test's own `storageState`, i.e. it is
 * *already signed in*. Open extra contexts with `contextFor(...)`, which states who the context is.
 */
export type Persona = 'operator' | 'member';
export type Who = Persona | 'anonymous';

export const personas: Record<Persona, { email: string }> = {
    operator: { email: 'operator@intechral.test' },
    member: { email: 'user@intechral.test' },
};

type StorageState = Exclude<BrowserContextOptions['storageState'], string | undefined>;

export const anonymous: StorageState = { cookies: [], origins: [] };

type Sessions = { stateFor: (persona: Persona) => Promise<StorageState> };

type ContextFor = (who: Who, options?: BrowserContextOptions) => Promise<BrowserContext>;

/**
 * Two workers holding the same session is the defect this file exists to prevent. Each worker
 * records the CSRF token of every session it mints (one token per Laravel session) in a shared
 * temp directory, exclusively, so a second claimant fails at once instead of producing flaky
 * flash-message failures much later.
 */
function claimSession(token: string, owner: string) {
    const directory = join(tmpdir(), 'portal-e2e-session-claims');
    mkdirSync(directory, { recursive: true });

    try {
        writeFileSync(join(directory, createHash('sha1').update(token).digest('hex')), owner, {
            flag: 'wx',
        });
    } catch (error) {
        if ((error as NodeJS.ErrnoException).code === 'EEXIST') {
            throw new Error(
                `${owner} minted a Laravel session another Playwright worker already holds. ` +
                    'Workers must never share a session (see support/auth.ts).',
                { cause: error },
            );
        }
        throw error;
    }
}

async function mintSession(browser: Browser, persona: Persona, workerIndex: number) {
    const context = await browser.newContext({ baseURL, storageState: anonymous });

    try {
        const page = await context.newPage();

        // The real login form through the real Fortify route: the session has to come from a genuine
        // authentication event, never a forged or hard-coded cookie.
        await signIn(page, personas[persona].email, 'password', { waitOutThrottle: false });

        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        claimSession(token ?? '', `worker ${workerIndex} (${persona})`);

        const { cookies } = await context.storageState();
        expect(cookies.length, `${persona} authentication produced no cookies`).toBeGreaterThan(0);

        return { cookies, origins: [] } satisfies StorageState;
    } finally {
        await context.close();
    }
}

export const test = base.extend<{ persona: Who; contextFor: ContextFor }, { sessions: Sessions }>({
    sessions: [
        async ({ browser }, use, workerInfo) => {
            const minted = new Map<Persona, Promise<StorageState>>();

            await use({
                stateFor: (persona) => {
                    if (!minted.has(persona)) {
                        minted.set(persona, mintSession(browser, persona, workerInfo.workerIndex));
                    }

                    return minted.get(persona)!;
                },
            });
        },
        { scope: 'worker' },
    ],

    persona: ['operator', { option: true }],

    storageState: async ({ persona, sessions }, use) => {
        await use(persona === 'anonymous' ? anonymous : await sessions.stateFor(persona));
    },

    contextFor: async ({ browser, sessions }, use) => {
        await use(async (who, options = {}) =>
            browser.newContext({
                ...options,
                storageState: who === 'anonymous' ? anonymous : await sessions.stateFor(who),
            }),
        );
    },
});

export { expect };

/**
 * Open an authenticated page. The persona comes from the context's `storageState` (the default, or a
 * `test.use({ persona })` override), so this only navigates and proves the session is still good. A
 * missing or invalidated session lands on `/login`, and this fails there with a clear reason rather
 * than deeper in the spec as a confusing assertion about page content.
 */
export async function signedIn(page: Page, path = '/dashboard') {
    await page.goto(path);

    await expect(
        page,
        "This worker's authenticated session did not resolve to an authenticated page. Something " +
            'invalidated it (a sign-out, a password change) or the context started signed out.',
    ).not.toHaveURL(/\/login$/);
}
