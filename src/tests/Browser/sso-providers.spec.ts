import { expect, test } from './support/auth';

/**
 * Release 1.0 (SSO completion): browser coverage of the Google and Microsoft entry points that needs no provider credentials.
 *
 * What this proves, for BOTH providers, against the real application: the sign-in page offers each provider; starting either one never ends in
 * a server error, whether or not the installation has credentials (no credentials is a plain "not available" message, credentials is a redirect
 * to the provider's authorization endpoint carrying a state, and for Microsoft a PKCE challenge); and a provider callback that arrives with no
 * pending sign-in (the user cancelled at the provider, or the page was replayed) is a plain refusal, never an error page.
 *
 * What it does NOT prove, because it cannot without real credentials and real accounts: that Google or Microsoft actually authenticates a person
 * and returns the verified-email evidence. That is the live end-to-end check listed in docs/product/release-1.0-sso-completion.md; the token and
 * claim handling is covered by the Pest contract tests (MicrosoftProviderTest, SsoCompletionTest). The provider hosts are stubbed here so the test
 * never leaves the machine.
 */
test.use({ persona: 'anonymous' });

const providers = [
    { key: 'google', label: 'Google', host: 'accounts.google.com' },
    { key: 'microsoft', label: 'Microsoft', host: 'login.microsoftonline.com' },
] as const;

test('the sign-in page offers both providers', async ({ page }) => {
    await page.goto('/login');

    for (const provider of providers) {
        await expect(page.getByRole('link', { name: provider.label })).toBeVisible();
    }
});

for (const provider of providers) {
    test(`${provider.label}: starting sign-in is a redirect to the provider or a plain refusal, never an error`, async ({
        page,
    }) => {
        await page.route(`https://${provider.host}/**`, (route) =>
            route.fulfill({ status: 200, contentType: 'text/html', body: '<title>Provider stub</title>' }),
        );

        await page.goto('/login');
        await page.getByRole('link', { name: provider.label }).click();
        await page.waitForLoadState('domcontentloaded');

        const url = new URL(page.url());

        if (url.host === provider.host) {
            // Credentials are configured here: the request must carry the authorization-code parameters.
            expect(url.searchParams.get('response_type')).toBe('code');
            expect(url.searchParams.get('client_id')).toBeTruthy();
            expect(url.searchParams.get('state')).toBeTruthy();

            if (provider.key === 'microsoft') {
                expect(url.searchParams.get('code_challenge_method')).toBe('S256');
                expect(url.searchParams.get('code_challenge')).toBeTruthy();
            }
        } else {
            await expect(page).toHaveURL(/\/login$/);
            await expect(page.getByText(`${provider.label} sign-in is not available.`)).toBeVisible();
        }
    });

    test(`${provider.label}: a callback with no pending sign-in is refused plainly`, async ({ page }) => {
        const response = await page.goto(`/auth/${provider.key}/callback?error=access_denied`);

        expect(response?.status()).toBeLessThan(500);
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText('This provider sign-in request is no longer valid.')).toBeVisible();
    });
}

test('an unknown provider is a 404', async ({ page }) => {
    const response = await page.goto('/auth/github/redirect');

    expect(response?.status()).toBe(404);
});
