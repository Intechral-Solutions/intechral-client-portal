import { randomBytes } from 'node:crypto';

import type { Page } from '@playwright/test';

import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { decidePilotRun, pilotRequired } from './support/pilot-probe';

/**
 * Release 0.9 (R0.9-CS1) — the customer pilot surface, in real Chromium.
 *
 * Needs a server whose APPLICATION ENVIRONMENT has `PORTAL_CUSTOMER_SURFACE_MODE=pilot`. The mode is read by the
 * application, not by this test, so the spec asks the server what it does (see support/pilot-probe.ts) and never
 * trusts a setting it cannot see:
 *
 *   - `E2E_REQUIRE_PILOT_GATE=1` makes the spec REQUIRED. It then fails, and never skips, unless the server is in
 *     pilot mode: a legacy or closed server, a 500, a redirect or any unexpected status is an error. The hosted
 *     "customer pilot browser spec" job runs it this way.
 *   - Without it, the spec skips only on a plainly legacy server (the default development stack), so the normal
 *     full suite is unaffected. Any other behaviour still fails.
 *
 * Locally: `PORTAL_CUSTOMER_SURFACE_MODE=pilot docker compose up -d app` (recreates the app container with that
 * environment; a real environment variable beats src/.env), then
 * `E2E_REQUIRE_PILOT_GATE=1 ./dev test:e2e tests/Browser/customer-pilot.spec.ts`, then restart the stack without
 * the variable. Every other browser spec assumes the development default (legacy) and several are expected to fail
 * against a pilot server, so run this file alone in that mode. The operator persona needs `portal.operator`
 * (`roles:sync` or a fresh `migrate --seed`).
 *
 * The server-side contract is covered exhaustively by Pest (tests/Feature/Pilot); this proves the same boundary
 * through a real browser session. Runs against the shared development database; the one project is registered with
 * `cleanup.trackProject` as soon as its id is known.
 */

const SECRET = 'E2E-PILOT-SECRET';

test('a pilot customer sees only the approved surface, and an operator sees everything', async ({
    page,
    contextFor,
    cleanup,
}) => {
    // R1-2B added the organization creation matrix (three more customer sessions) to this one test; the default 30 s leaves too little on a
    // slow runner. The hosted job still requires exactly "1 passed".
    test.setTimeout(120_000);
    const probe = await contextFor('member');
    const status = async (path: string) =>
        (await probe.request.get(path, { maxRedirects: 0, failOnStatusCode: false })).status();
    const verdict = decidePilotRun(
        {
            invoices: await status('/my/invoices'),
            tickets: await status('/tickets'),
            projects: await status('/projects'),
        },
        pilotRequired(process.env.E2E_REQUIRE_PILOT_GATE),
    );
    await probe.close();
    test.skip(
        verdict === 'skip',
        'The server is in legacy mode; this spec needs PORTAL_CUSTOMER_SURFACE_MODE=pilot.',
    );

    await signedIn(page);

    // The operator sets up a project the customer is a member of, with data the pilot must withhold.
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill('E2E Pilot project');
    await page.getByLabel('Budget ($)').fill('4321');
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)$/)![1]);
    cleanup.trackProject(projectId);

    const created = await page.evaluate(
        async ({ id, secret }) => {
            const token =
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
            const response = await fetch(`/projects/${id}/milestones`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify({
                    name: 'E2E Pilot milestone',
                    description: secret,
                    due_date: '2030-01-15',
                }),
            });

            return response.status;
        },
        { id: projectId, secret: SECRET },
    );
    expect(created).toBeLessThan(400);

    const customerContext = await contextFor('member');
    const customer = await customerContext.newPage();

    try {
        // Navigation: Home, Support and Projects, nothing else.
        await customer.goto('/dashboard');
        const nav = customer.getByRole('navigation', { name: 'Main' });
        await expect(nav.getByRole('link', { name: 'Home' })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Support' })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Projects' })).toBeVisible();
        for (const absent of ['Invoices', 'Resources', 'Time', 'Tasks']) {
            await expect(nav.getByRole('link', { name: absent })).toHaveCount(0);
        }
        await expect(customer.getByRole('main')).not.toContainText(/invoice/i);

        // The project list and the reduced overview.
        await customer.goto('/projects');
        await customer.getByRole('main').getByRole('link', { name: 'E2E Pilot project' }).click();
        await expect(customer).toHaveURL(`/projects/${projectId}`);
        const main = customer.getByRole('main');
        await expect(
            main.getByRole('heading', { level: 1, name: 'E2E Pilot project' }),
        ).toBeVisible();
        await expect(main.getByText('E2E Pilot milestone')).toBeVisible();
        await expect(main.getByText(/completed|planned|overdue/i)).toHaveCount(0);
        for (const absent of ['Board', 'Tasks', 'Time', 'Settings', 'Budget', 'People']) {
            await expect(main.getByRole('link', { name: absent })).toHaveCount(0);
        }
        await expect(main).not.toContainText(SECRET);
        await expect(main).not.toContainText(/4,?321/);

        // What the server sent is what the page shows: none of it is in the page data.
        const pageData = await customer.evaluate(() => {
            const script = document.querySelector('script[data-page]');
            const element = document.querySelector('[data-page]');

            return script?.textContent ?? element?.getAttribute('data-page') ?? '';
        });
        for (const absent of [SECRET, '"budget"', '"members"', '"description"', '"health"']) {
            expect(pageData).not.toContain(absent);
        }

        // Direct URLs into excluded modules are refused by the server.
        for (const path of [
            '/my/invoices',
            '/time',
            '/tasks',
            '/pages',
            `/projects/${projectId}/board`,
            `/projects/${projectId}/tasks`,
            `/projects/${projectId}/milestones`,
            `/projects/${projectId}/time`,
            '/operator/cms',
        ]) {
            const response = await customer.goto(path);
            expect(response?.status(), path).toBe(404);
        }

        // The Helpdesk is unchanged.
        await customer.goto('/tickets');
        await expect(customer.getByRole('heading', { level: 1 })).toBeVisible();
        const create = await customer.goto('/tickets/create');
        expect(create?.status()).toBe(200);
    } finally {
        await customerContext.close();
    }

    // R1-2B: the Helpdesk creation matrix, in pilot mode, through real customer sessions. Kept inside this one test so the hosted job's
    // "1 passed" check still means the whole spec. The operator (this `page`) reads the organization ids the customers must not be able to
    // borrow; the three customers are the seeded fixtures: one organization, two, and none.
    await page.goto('/operator/tickets');
    const foreignId = (await page
        .getByRole('combobox', { name: 'Organization' })
        .locator('option', { hasText: 'E2E Customer Org' })
        .getAttribute('value'))!;
    expect(foreignId).toMatch(/^\d+$/);

    const detailOrganization = (customer: Page) =>
        customer.locator('dt', { hasText: 'Organization' }).locator('xpath=following-sibling::dd[1]');

    // One organization: stated, not chosen, and the request carries it.
    const single = await contextFor('customer');
    try {
        const customer = await single.newPage();
        await customer.goto('/tickets/create');
        await expect(customer.getByText('Requesting for E2E Customer Org')).toBeVisible();
        await expect(customer.getByRole('combobox', { name: /Organization/ })).toHaveCount(0);
        await customer.getByLabel(/^Subject/).fill(`E2E-${randomBytes(8).toString('hex')} pilot single organization`);
        await customer.getByLabel(/^Description/).fill('Pilot mode, one organization.');
        await customer.getByRole('button', { name: 'Submit request' }).click();
        await expect(customer).toHaveURL(/\/tickets\/TK-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/);
        await expect(detailOrganization(customer)).toHaveText('E2E Customer Org');
    } finally {
        await single.close();
    }

    // Two organizations: an explicit choice; a forged id is refused and shown; the honest one is carried.
    const multi = await contextFor('customerMulti');
    try {
        const customer = await multi.newPage();
        await customer.goto('/tickets/create');
        const select = customer.getByRole('combobox', { name: /Organization/ });
        await expect(select).toHaveValue('');
        await expect(select.locator('option')).toHaveText(['Choose an organization', 'E2E Multi Org A', 'E2E Multi Org B']);
        await customer.getByLabel(/^Subject/).fill(`E2E-${randomBytes(8).toString('hex')} pilot two organizations`);
        await customer.getByLabel(/^Description/).fill('Pilot mode, two organizations.');

        await select.selectOption({ label: 'E2E Multi Org A' });
        // The form submits React state, not the DOM: rewrite the chosen option's value AND tell React (a `change` event), so the forged id is
        // what the form really sends.
        await select.evaluate((element, id) => {
            (element as HTMLSelectElement).selectedOptions[0]!.value = id;
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }, foreignId);
        await expect(select).toHaveValue(foreignId);
        await customer.getByRole('button', { name: 'Submit request' }).click();
        await expect(customer.getByText('That organization is not available for this request.')).toBeVisible();
        await expect(customer.getByText(/E2E Customer Org/)).toHaveCount(0);

        await select.selectOption({ label: 'E2E Multi Org B' });
        await customer.getByRole('button', { name: 'Submit request' }).click();
        await expect(customer).toHaveURL(/\/tickets\/TK-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/);
        await expect(detailOrganization(customer)).toHaveText('E2E Multi Org B');
    } finally {
        await multi.close();
    }

    // No organization: explained, no form, and the server refuses the same request made by hand.
    const none = await contextFor('customerNoOrg');
    try {
        const customer = await none.newPage();
        const created = await customer.goto('/tickets/create');
        expect(created?.status()).toBe(200);
        await expect(customer.getByText("Your account isn't linked to an organization yet.")).toBeVisible();
        await expect(customer.getByLabel(/^Subject/)).toHaveCount(0);
        const refused = await customer.evaluate(async (id) => {
            const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
            const response = await fetch('/tickets', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ title: 'E2E-pilot-no-org', description: 'x', category: 'General', priority: 'low', organization_id: id }),
            });

            return response.status;
        }, foreignId);
        expect(refused).toBe(422);
    } finally {
        await none.close();
    }

    // The operator is unaffected, and still sees the full internal project.
    for (const path of [
        '/billing/invoices',
        '/time',
        '/tasks',
        `/projects/${projectId}/board`,
        `/projects/${projectId}/milestones`,
    ]) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(200);
    }
    await page.goto(`/projects/${projectId}`);
    await expect(page.getByRole('main').getByRole('link', { name: 'Settings' })).toBeVisible();
});
