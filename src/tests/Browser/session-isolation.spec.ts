import { anonymous, expect, signedIn, test } from './support/auth';
import { accountTrigger } from './support/shell';

/**
 * Guards for the browser suite's own authentication architecture (support/auth.ts), so it cannot
 * quietly regress to the states that made the suite flaky. That two workers never share a Laravel
 * session is enforced where the sessions are minted (`claimSession`), which fails the worker
 * outright; these cover the parts that show up as behaviour.
 */

test('a context created for the anonymous actor really is signed out', async ({ contextFor }) => {
    const context = await contextFor('anonymous');

    try {
        const page = await context.newPage();
        await page.goto('/login');

        // An authenticated context would be redirected to /dashboard and never see the form.
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    } finally {
        await context.close();
    }
});

test('reusable session state holds authentication cookies and nothing else', async ({
    sessions,
}) => {
    for (const persona of ['operator', 'member'] as const) {
        const state = await sessions.stateFor(persona);

        // No localStorage origins: the shell's theme, drawer and pin preferences must stay under each
        // test's control rather than being inherited from whoever minted the session.
        expect(state.origins, `${persona} state must not capture localStorage`).toEqual([]);
        expect(state.cookies.map((cookie) => cookie.name)).toContain(
            'intechral-client-portal-session',
        );
    }

    expect(anonymous).toEqual({ cookies: [], origins: [] });
});

test('the operator and member personas stay distinct actors', async ({ page, contextFor }) => {
    await signedIn(page);
    await expect(accountTrigger(page)).toHaveAccessibleName(/Dev Operator/);

    const memberContext = await contextFor('member');

    try {
        const memberPage = await memberContext.newPage();
        await signedIn(memberPage);
        await expect(accountTrigger(memberPage)).not.toHaveAccessibleName(/Dev Operator/);
    } finally {
        await memberContext.close();
    }
});
