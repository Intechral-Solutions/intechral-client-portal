import { randomBytes } from 'node:crypto';

import type { Page, Response } from '@playwright/test';

import { expect, test } from './support/auth';
import {
    configuredAppOrigin,
    deleteMessagesTo,
    stayedQuiet,
    waitForInvitationMail,
    waitForInvitationMailOnce,
} from './support/mailpit';
import { captureRequests, leaks, startLeakyProxy } from './support/referer-capture';

/**
 * Release 1.0 (R1-3C) — organization-bound invitations in real Chromium: the operator issues an invitation on the organization page, the
 * recipient follows the link in the MAIL (read from Mailpit, as a person reads their mailbox), registers with a password, and the operator
 * sees the outcome. Also resend, revocation, dead links, the refusals, and the validation and keyboard behaviour of the two forms.
 *
 * **Actors.** `operator` (the default persona) issues and revokes; the recipients are anonymous contexts that become signed in only by
 * ACCEPTING an invitation; `customer` (`e2e-customer@intechral.test`) is the ordinary customer who must be refused every operator route.
 *
 * **Fixture ownership.** Every invitee address is `e2e-invitee+<run>@example.test` (lower case, a reserved test domain), every invitation
 * goes into the seeded `E2E Invitations Org` and nowhere else, and no account is created except by accepting a real invitation.
 * Nothing here deletes anything. The suite-level invitee cleanup command removes exactly those accounts, memberships and invitations once,
 * after the whole suite (`./dev test:e2e`, or the `globalTeardown` for a hand-started run), as the ticket cleanup does for tickets: the
 * command is untargeted and every worker's invitees share the pattern, so a per-test call would remove accounts other workers are still using.
 *
 * **Not here, on purpose.** The new member raising a request for the organization and not seeing anyone else's is proved in Pest (the
 * operator-to-recipient journey in OrganizationInvitationOperatorTest, and the R1-3A and R1-3B suites): a ticket raised by an invitee is not
 * one the ticket cleanup may delete (it is bound to the seeded customers), and the invitee cleanup refuses an account that has one.
 * Customer invitation single sign-on is deferred, so the page offers the password form only and this spec does not touch a provider.
 *
 * **Retries.** The first group is serial because each step reads what the one before made. A re-run starts again from its first test with
 * fresh addresses (`address()` includes per-attempt entropy), so a retry never binds to an earlier attempt's invitation.
 */

const ORGANIZATION = 'E2E Invitations Org';
const PASSWORD = () => `Aa1!${randomBytes(12).toString('hex')}`;

/** `e2e-invitee+<entropy>-<label>@example.test`: unique per attempt, matches the cleanup's reserved pattern. */
function address(label: string) {
    return `e2e-invitee+${randomBytes(6).toString('hex')}-${label}@example.test`;
}

/** The invitations card of the E2E organization, on the operator's page (found through the list, never by id arithmetic). */
async function openOrganization(page: Page) {
    for (let list = 1; list <= 20; list++) {
        await page.goto(list === 1 ? '/organizations' : `/organizations?page=${list}`);
        const link = page.getByRole('link', { name: ORGANIZATION, exact: true });

        if ((await link.count()) > 0) {
            await link.first().click();
            await expect(page.getByRole('heading', { name: ORGANIZATION, level: 1 })).toBeVisible();

            return page.getByRole('region', { name: 'Invitations' });
        }
    }

    throw new Error(
        `The ${ORGANIZATION} was not found in the organization list. Seed the development fixtures (DevSeeder).`,
    );
}

async function invite(page: Page, email: string) {
    const card = page.getByRole('region', { name: 'Invitations' });
    await card.getByLabel('Email address to invite').fill(email);
    await card.getByRole('button', { name: 'Send invitation' }).click();
}

const row = (page: Page, email: string) =>
    page.getByRole('region', { name: 'Invitations' }).getByRole('row').filter({ hasText: email });

/** The organization's own path, taken from the page the operator is on. */
function organizationPath(page: Page) {
    return new URL(page.url()).pathname;
}

async function csrfToken(page: Page) {
    return (await page.locator('meta[name="csrf-token"]').getAttribute('content')) ?? '';
}

/** Every `Referrer-Policy` header value of a response, one entry per header line (a duplicate shows as two). */
async function referrerPolicies(response: Response | null) {
    return ((await response?.headersArray()) ?? [])
        .filter((header) => header.name.toLowerCase() === 'referrer-policy')
        .map((header) => header.value);
}

test.use({ viewport: { width: 1280, height: 900 } });

const used: string[] = [];

test.afterAll(async () => {
    for (const email of used) await deleteMessagesTo(email);
});

test.describe('the operator-to-recipient journey', () => {
    test.describe.configure({ mode: 'serial' });

    const invitee = address('journey');
    const smuggled = address('smuggled');
    let orgPath = '';
    const password = PASSWORD();

    test('an operator invites a new person, and the card shows the invitation as pending', async ({
        page,
    }) => {
        used.push(invitee, smuggled);
        await openOrganization(page);
        orgPath = organizationPath(page);

        const card = page.getByRole('region', { name: 'Invitations' });
        await expect(
            card.getByText(
                'Invited people join as ordinary members; administrators are made separately.',
            ),
        ).toBeVisible();

        await invite(page, invitee);

        await expect(
            page.getByRole('status').filter({ hasText: `Invitation sent to ${invitee}.` }),
        ).toBeVisible();
        const invited = row(page, invitee);
        await expect(invited).toContainText('Pending');
        await expect(invited).toContainText('Invited by');
        await expect(invited).toContainText('Expires');
        await expect(
            invited.getByRole('button', { name: `Resend the invitation to ${invitee}` }),
        ).toBeVisible();
        await expect(
            invited.getByRole('button', { name: `Revoke the invitation to ${invitee}` }),
        ).toBeVisible();

        // The card never shows a link, a token or a hash.
        expect(await card.innerHTML()).not.toMatch(/\/invitation\/[A-Za-z0-9]{20}/);
    });

    test('the recipient follows the link in the email, names the organization, and registers with a password', async ({
        contextFor,
    }) => {
        const mail = await waitForInvitationMailOnce(invitee);

        expect(mail.to).toEqual([invitee]);
        expect(mail.text).toContain(ORGANIZATION);

        const context = await contextFor('anonymous');
        // Every request the recipient's browser makes from here on, with the Referer it really sent (asserted at the end).
        const capture = captureRequests(context);

        try {
            const page = await context.newPage();
            const document = await page.goto(mail.path);

            await expect(page.getByRole('heading', { name: 'Accept invitation' })).toBeVisible();
            await expect(
                page.getByText(`You have been invited to join ${ORGANIZATION}`),
            ).toBeVisible();
            // The address is the invited one, shown and not editable: the request can carry no other.
            await expect(page.getByLabel('Email address')).toHaveValue(invitee);
            await expect(page.getByLabel('Email address')).toBeDisabled();
            // (Whether the page also offers single sign-on depends on the customer-surface mode: it does not in closed and pilot, where
            // invitation SSO is deferred, and the Pest suites prove that. This journey takes the password form, which is always there.)

            // The credential response carries ONE Referrer-Policy value, no-referrer (a proxy that appended a second would win, since a
            // browser applies the last), and the document states the same policy itself, first in the head. The effective behaviour is
            // asserted on the requests below, not inferred from this header.
            const policies = await referrerPolicies(document);
            expect(policies).toEqual(['no-referrer']);
            expect(document?.headers()['cache-control']).toContain('no-store');
            await expect(page.locator('meta[name="referrer"]')).toHaveCount(1);
            await expect(
                page.locator('head > meta[charset] + meta[name="referrer"]'),
            ).toHaveAttribute('content', 'no-referrer');

            await page.getByLabel('Name').fill('E2E Invitee');
            await page.getByLabel('Password', { exact: true }).fill(password);
            await page.getByLabel('Confirm password').fill(password);
            await page.getByRole('button', { name: 'Create account' }).click();

            // Signed in, as a customer: the account exists and the organization's membership came with it.
            await expect(page).toHaveURL(/\/dashboard/);
            await expect(page).not.toHaveURL(/\/invitation\//);

            // What the browser sent for the whole journey (the document, its assets, the form's POST, the navigation to the dashboard):
            // no request carries the invitation route as a Referer, whatever sits in front of the application.
            const seen = await capture.settled();
            expect(
                seen.some(
                    (request) =>
                        request.origin === new URL(page.url()).origin &&
                        ['script', 'stylesheet'].includes(request.type),
                ),
                'the capture saw same-origin assets (otherwise the leak check below proves nothing)',
            ).toBe(true);
            expect(
                seen.some(
                    (request) => request.method === 'POST' && request.url.includes(mail.token),
                ),
                'the capture saw the form submission',
            ).toBe(true);
            expect(leaks(seen)).toEqual([]);

            // Single use: the same link is now dead. Opened in a FRESH anonymous browser context, so the answer is the invitation's own
            // (accepted: consumed), not the `guest` middleware turning a signed-in browser away.
            const fresh = await contextFor('anonymous');

            try {
                const again = await fresh.newPage();
                await again.goto(mail.path);
                await expect(
                    again.getByRole('heading', { name: 'Invitation unavailable' }),
                ).toBeVisible();
                await expect(again.getByRole('heading', { name: 'Accept invitation' })).toHaveCount(
                    0,
                );
                await expect(again.getByLabel('Password', { exact: true })).toHaveCount(0);
            } finally {
                await fresh.close();
            }

            // The new member is an ordinary member: every operator route refuses them (they belong to the organization, so it is the
            // capability, not visibility, that answers).
            await page.goto('/dashboard');
            const refused = await page.request.post(`${orgPath}/invitations`, {
                headers: { 'X-CSRF-TOKEN': await csrfToken(page) },
                form: { email: smuggled },
            });
            expect([403, 404]).toContain(refused.status());
            const asPage = await page.request.get(orgPath);
            expect([403, 404]).toContain(asPage.status());
        } finally {
            await context.close();
        }

        // Nothing was mailed for the refused attempt.
        expect(await stayedQuiet(smuggled, 3_000)).toBe(true);
    });

    test('the operator sees the outcome: accepted, by whom, and nothing left to revoke', async ({
        page,
    }) => {
        await page.goto(orgPath);
        const accepted = row(page, invitee);

        await expect(accepted).toContainText('Accepted');
        await expect(accepted).toContainText('E2E Invitee');
        await expect(accepted.getByRole('button', { name: /^Revoke/ })).toHaveCount(0);
        await expect(accepted.getByRole('button', { name: /^Resend/ })).toHaveCount(0);
        // And the person is a member of the organization (the Members card lists the invited address).
        await expect(page.getByText(invitee).first()).toBeVisible();
    });

    test('inviting the address again is refused now: it belongs to an account', async ({
        page,
    }) => {
        await page.goto(orgPath);
        await invite(page, invitee);

        await expect(
            page.getByRole('region', { name: 'Invitations' }).getByRole('alert'),
        ).toContainText('That address already belongs to an account');
        await expect(page.getByLabel('Email address to invite')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });
});

test.describe('revocation, resend and dead links', () => {
    test.describe.configure({ mode: 'serial' });

    test('a revoked invitation is dead, and the card says why', async ({ page, contextFor }) => {
        const email = address('revoked');
        used.push(email);
        await openOrganization(page);
        await invite(page, email);
        await expect(row(page, email)).toContainText('Pending');
        const mail = await waitForInvitationMailOnce(email);

        await row(page, email)
            .getByRole('button', { name: `Revoke the invitation to ${email}` })
            .click();

        await expect(
            page
                .getByRole('status')
                .filter({ hasText: 'Invitation revoked. Its link no longer works.' }),
        ).toBeVisible();
        await expect(row(page, email)).toContainText('Revoked');
        await expect(row(page, email)).toContainText('Revoked by an operator');
        await expect(row(page, email).getByRole('button', { name: /^Revoke/ })).toHaveCount(0);

        const context = await contextFor('anonymous');

        try {
            const recipient = await context.newPage();
            await recipient.goto(mail.path);
            await expect(
                recipient.getByRole('heading', { name: 'Invitation unavailable' }),
            ).toBeVisible();
            await expect(recipient.getByLabel('Password', { exact: true })).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('resending replaces the earlier link: the first is dead, the second works, and the card keeps the history', async ({
        page,
        contextFor,
    }) => {
        const email = address('resent');
        used.push(email);
        await openOrganization(page);
        await invite(page, email);
        await expect(row(page, email)).toContainText('Pending');
        const first = await waitForInvitationMailOnce(email);

        await row(page, email)
            .getByRole('button', { name: `Resend the invitation to ${email}` })
            .click();
        await expect(
            page.getByRole('status').filter({ hasText: `Invitation sent to ${email}.` }),
        ).toBeVisible();
        const mails = await waitForInvitationMail(email, { count: 2 });
        const second = mails.find((mail) => mail.token !== first.token);
        expect(second, 'the resend produced a second, different link').toBeDefined();

        // Two rows for the address: the replaced one and the live one.
        await expect(row(page, email)).toHaveCount(2);
        await expect(page.getByRole('region', { name: 'Invitations' })).toContainText(
            'Replaced by a newer invitation',
        );

        const context = await contextFor('anonymous');

        try {
            const recipient = await context.newPage();
            await recipient.goto(first.path);
            await expect(
                recipient.getByRole('heading', { name: 'Invitation unavailable' }),
            ).toBeVisible();

            await recipient.goto(second!.path);
            await expect(
                recipient.getByRole('heading', { name: 'Accept invitation' }),
            ).toBeVisible();
            await expect(recipient.getByLabel('Email address')).toHaveValue(email);
        } finally {
            await context.close();
        }
    });

    test('a link that was never issued is the same unavailable page, whatever it looks like', async ({
        contextFor,
    }) => {
        const context = await contextFor('anonymous');

        try {
            const page = await context.newPage();

            for (const token of ['x'.repeat(64), 'short', '0'.repeat(64)]) {
                await page.goto(`/invitation/${token}`);
                await expect(
                    page.getByRole('heading', { name: 'Invitation unavailable' }),
                ).toBeVisible();
            }
        } finally {
            await context.close();
        }
    });
});

test.describe('the invitation link and what it may leak', () => {
    test.describe.configure({ mode: 'serial' });

    const email = address('credential');
    let mail: Awaited<ReturnType<typeof waitForInvitationMailOnce>>;

    test('the link in the email is absolute and uses the configured APP_URL origin', async ({
        page,
    }) => {
        used.push(email);
        await openOrganization(page);
        await invite(page, email);
        await expect(row(page, email)).toContainText('Pending');
        mail = await waitForInvitationMailOnce(email);

        // Built by the queue worker from APP_URL, not from the request that issued it (the browser reached the app under another name).
        expect(mail.origin).toBe(configuredAppOrigin());
        expect(mail.text).toContain(`${mail.origin}/invitation/${mail.token}`);
    });

    test('the credential response carries one no-referrer policy and the document repeats it; an ordinary page keeps the stack default and no meta tag', async ({
        contextFor,
    }) => {
        const context = await contextFor('anonymous');

        try {
            const page = await context.newPage();
            const document = await page.goto(mail.path);
            const policies = await referrerPolicies(document);

            expect(policies, 'exactly one value: a duplicate would let the last one win').toEqual([
                'no-referrer',
            ]);
            await expect(page.locator('meta[name="referrer"]')).toHaveAttribute(
                'content',
                'no-referrer',
            );

            // An ordinary page does not acquire the policy: its response keeps the stack's single default (the development nginx
            // supplies it only when the application sent none) and its document has no referrer meta tag.
            const login = await page.goto('/login');
            const ordinary = await referrerPolicies(login);

            expect(ordinary).toEqual(['strict-origin-when-cross-origin']);
            await expect(page.locator('meta[name="referrer"]')).toHaveCount(0);
        } finally {
            await context.close();
        }
    });

    test('no request the invitation page causes carries the token-bearing URL as a Referer: assets, fetch, images and navigation', async ({
        contextFor,
    }) => {
        const context = await contextFor('anonymous');
        const capture = captureRequests(context);

        try {
            const page = await context.newPage();
            await page.goto(mail.path);
            await expect(page.getByRole('heading', { name: 'Accept invitation' })).toBeVisible();

            // What the page itself can cause beyond its assets: a same-origin fetch, an image, and a navigation (an ordinary link click).
            await page.evaluate(async () => {
                await fetch('/favicon.svg?probe=fetch');
                await new Promise<void>((resolve) => {
                    const image = new Image();
                    image.onload = image.onerror = () => resolve();
                    image.src = '/favicon.svg?probe=image';
                });
                const link = document.createElement('a');
                link.href = '/login?probe=navigation';
                link.textContent = 'probe';
                document.body.appendChild(link);
                link.click();
            });
            await expect(page).toHaveURL(/\/login\?probe=navigation/);

            const seen = await capture.settled();
            const own = seen.filter(
                (request) =>
                    !request.url.includes('probe=navigation') &&
                    request.url !== new URL(mail.path, page.url()).href,
            );

            expect(
                own.some((request) => ['script', 'stylesheet'].includes(request.type)),
                'assets of the invitation page were captured',
            ).toBe(true);
            for (const kind of ['fetch', 'image']) {
                expect(
                    seen.some((request) => request.url.includes(`probe=${kind}`)),
                    `the ${kind} probe was captured`,
                ).toBe(true);
            }

            const navigation = seen.find((request) => request.url.includes('probe=navigation'));
            expect(navigation, 'the navigation probe was captured').toBeDefined();
            // The policy is no-referrer, so the page's own requests and its navigation carry no Referer at all.
            expect(navigation?.referer ?? null).toBeNull();
            expect(leaks(seen)).toEqual([]);
            expect(
                seen.filter((request) => request.rawHeaders === false),
                'every request had its raw headers resolved',
            ).toEqual([]);
        } finally {
            await context.close();
        }
    });

    test('control: the formerly leaking response (a second, weaker Referrer-Policy and no meta tag) DOES leak the token, so the check above can fail', async ({
        contextFor,
        baseURL,
    }) => {
        // A proxy that behaves as the development nginx used to: the application's `no-referrer`, then its own default as a second
        // header line, and no document-level policy. A browser applies the last value.
        const proxy = await startLeakyProxy(baseURL ?? '');
        const context = await contextFor('anonymous');
        const capture = captureRequests(context);

        try {
            const page = await context.newPage();
            const document = await page.goto(`${proxy.origin}${mail.path}`);
            await expect(page.getByRole('heading', { name: 'Accept invitation' })).toBeVisible();
            await expect(page.locator('meta[name="referrer"]')).toHaveCount(0);

            expect(
                await referrerPolicies(document),
                'the control really serves two policy lines',
            ).toEqual(['no-referrer', 'strict-origin-when-cross-origin']);

            const seen = await capture.settled();
            const sameOriginAssets = leaks(seen).filter(
                (request) =>
                    request.origin === proxy.origin &&
                    ['script', 'stylesheet'].includes(request.type),
            );

            expect(
                sameOriginAssets.length,
                'the old behaviour sends the token-bearing URL as the Referer of same-origin asset requests',
            ).toBeGreaterThan(0);
            expect(leaks(seen).every((request) => request.referer?.includes(mail.token))).toBe(
                true,
            );
        } finally {
            await context.close();
            await proxy.close();
        }
    });
});

test.describe("the recipient's form", () => {
    test('refuses a mismatched confirmation, keeps the person on the page and names the field', async ({
        page,
        contextFor,
    }) => {
        const email = address('validation');
        used.push(email);
        await openOrganization(page);
        await invite(page, email);
        const mail = await waitForInvitationMailOnce(email);

        const context = await contextFor('anonymous');

        try {
            const recipient = await context.newPage();
            await recipient.goto(mail.path);
            await recipient.getByLabel('Name').fill('E2E Invitee');
            await recipient.getByLabel('Password', { exact: true }).fill(PASSWORD());
            await recipient.getByLabel('Confirm password').fill(PASSWORD());
            await recipient.getByRole('button', { name: 'Create account' }).click();

            await expect(recipient).toHaveURL(new RegExp(`${mail.path}$`));
            await expect(recipient.getByLabel('Password', { exact: true })).toHaveAttribute(
                'aria-invalid',
                'true',
            );
            await expect(recipient.getByLabel('Password', { exact: true })).toHaveAttribute(
                'aria-describedby',
                'password-error',
            );
            await expect(recipient.locator('#password-error')).toContainText(/confirmation/i);
            // The password fields are cleared, and focus goes to the field that needs attention.
            await expect(recipient.getByLabel('Password', { exact: true })).toBeFocused();
            await expect(recipient.getByLabel('Password', { exact: true })).toHaveValue('');
        } finally {
            await context.close();
        }

        // The invitation is still open: a refused attempt writes nothing.
        await page.goto(organizationPath(page));
        await expect(row(page, email)).toContainText('Pending');
    });
});

test.describe("the operator's form", () => {
    test('is operable from the keyboard, and names its field and its errors', async ({ page }) => {
        const email = address('keyboard');
        used.push(email);
        const card = await openOrganization(page);
        const field = card.getByLabel('Email address to invite');

        // The browser's own constraint validation stops a value that is not an address: nothing is sent.
        await field.fill('not-an-address');
        await card.getByRole('button', { name: 'Send invitation' }).click();
        expect(await field.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(false);
        await expect(page.getByRole('status')).toHaveCount(0);

        // Typing a valid address and pressing Enter submits the form; Tab from the field reaches the button.
        await field.fill(email);
        await page.keyboard.press('Tab');
        await expect(card.getByRole('button', { name: 'Send invitation' })).toBeFocused();
        await field.focus();
        await page.keyboard.press('Enter');

        await expect(row(page, email)).toContainText('Pending');
        await expect(
            page.getByRole('status').filter({ hasText: `Invitation sent to ${email}.` }),
        ).toBeVisible();
        // The table has a caption and column headers for assistive technology.
        await expect(card.getByRole('columnheader', { name: 'Invitee' })).toBeVisible();
        await expect(card.getByRole('columnheader', { name: 'Status' })).toBeVisible();
    });

    test('names a refusal beside the field and keeps the attempted address', async ({ page }) => {
        // A seeded account's address: an invitation cannot be issued to someone who already has an account.
        const card = await openOrganization(page);
        await card.getByLabel('Email address to invite').fill('user@intechral.test');
        await card.getByRole('button', { name: 'Send invitation' }).click();

        const field = page.getByLabel('Email address to invite');
        await expect(page.locator('#invitation-email-error')).toContainText(
            'That address already belongs to an account',
        );
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(field).toHaveAttribute('aria-describedby', 'invitation-email-error');
        await expect(field).toHaveValue('user@intechral.test');
        // Nothing was invited.
        await expect(row(page, 'user@intechral.test')).toHaveCount(0);
    });
});

test.describe('authorization', () => {
    test.use({ persona: 'customer' });

    test('an ordinary customer is refused the organization page and both operator routes, and nothing is mailed', async ({
        page,
        contextFor,
    }) => {
        // Find the organization as the operator (the customer cannot), then act as the customer.
        const operator = await contextFor('operator');
        let orgPath = '';

        try {
            const operatorPage = await operator.newPage();
            await openOrganization(operatorPage);
            orgPath = organizationPath(operatorPage);
        } finally {
            await operator.close();
        }

        const email = address('refused');
        used.push(email);
        await page.goto('/dashboard');
        const headers = { 'X-CSRF-TOKEN': await csrfToken(page) };

        const issue = await page.request.post(`${orgPath}/invitations`, {
            headers,
            form: { email },
        });
        const revoke = await page.request.delete(`${orgPath}/invitations/1`, { headers });
        const view = await page.request.get(orgPath);

        for (const response of [issue, revoke, view]) {
            expect([403, 404]).toContain(response.status());
        }

        await page.goto(orgPath);
        await expect(page.getByRole('region', { name: 'Invitations' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Send invitation' })).toHaveCount(0);

        expect(await stayedQuiet(email, 3_000)).toBe(true);
    });

    test('a guest is sent to sign in', async ({ contextFor }) => {
        const context = await contextFor('anonymous');

        try {
            const page = await context.newPage();
            await page.goto('/organizations');
            await expect(page).toHaveURL(/\/login$/);
        } finally {
            await context.close();
        }
    });
});
