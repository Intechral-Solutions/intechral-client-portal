import { randomBytes } from 'node:crypto';

import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';

/**
 * Release 1.0 — the operator's optional due-date adjustment on the Blade reply form, in real Chromium.
 *
 * **Actors.** `customer` raises the request through the real form; `operator` (an existing persona
 * holding `tickets.assign`) adjusts it. No account is created.
 *
 * **Fixture ownership and cleanup.** Every ticket is made by a test here through the real form, with a
 * literal `E2E-` prefix and a random token, and is addressed only by the number captured from the
 * redirect. Cleanup is the suite-wide one in `./dev test:e2e` / `globalTeardown` (see
 * customer-helpdesk.spec.ts); the ticket delete cascades the due-date history row.
 *
 * The app zone is pinned to UTC, so the `datetime-local` value typed here is a UTC wall-clock time.
 */

test.use({ persona: 'customer', viewport: { width: 1280, height: 900 } });

const DESKTOP = { width: 1280, height: 900 };
const DAY = 24 * 60 * 60 * 1000;

function ticketTitle(label: string) {
    return `E2E-${randomBytes(8).toString('hex')} ${label}`;
}

/** `datetime-local` value (UTC wall clock) a whole number of days ahead, at 09:30. */
function daysAhead(days: number) {
    const d = new Date(Date.now() + days * DAY);
    d.setUTCHours(9, 30, 0, 0);

    return d.toISOString().slice(0, 16);
}

/** The same instant as the operator page prints it: `d M Y H:i`. */
function printed(local: string) {
    const d = new Date(`${local}:00Z`);
    const month = d.toLocaleString('en-GB', { month: 'short', timeZone: 'UTC' });

    return `${String(d.getUTCDate()).padStart(2, '0')} ${month} ${d.getUTCFullYear()} 09:30`;
}

async function createTicket(page: Page, title: string) {
    await signedIn(page, '/tickets/create');
    await page.getByLabel(/^Subject/).fill(title);
    await page.getByLabel(/^Description/).fill('Needs more time on our side.');
    await page.getByRole('button', { name: 'Submit request' }).click();

    await expect(page).toHaveURL(/\/tickets\/TK-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/);
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();

    return {
        url: new URL(page.url()).pathname,
        number: (await page.locator('header p.font-mono').innerText()).trim(),
    };
}

async function openOperatorTicket(page: Page, number: string) {
    await signedIn(page, `/operator/tickets/${number}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

const trigger = (page: Page) => page.locator('[data-due-trigger]');
const dueInput = (page: Page) => page.getByLabel(/^Due date and time/);

/** HD-C1: open the inline editor, type a wall-clock date (UTC), and finish the local edit with Enter. */
async function pickDue(page: Page, local: string) {
    await trigger(page).click();
    await dueInput(page).fill(local);
    await dueInput(page).press('Enter');
    await expect(trigger(page)).toBeVisible();
}

test('an operator moves the due date with a public reply; the requester sees none of it', async ({
    page,
    contextFor,
}) => {
    const ticket = await createTicket(page, ticketTitle('due date move'));
    const operatorContext = await contextFor('operator', { viewport: DESKTOP });

    try {
        const operator = await operatorContext.newPage();
        await openOperatorTicket(operator, ticket.number);

        const target = daysAhead(5);
        const reason = `We agreed more time ${randomBytes(4).toString('hex')}.`;

        // Optional and compact: a button in the reply form showing the current date, with the editor closed.
        await expect(trigger(operator)).toBeVisible();
        await expect(dueInput(operator)).toBeHidden();

        await operator.getByPlaceholder(/Write your reply/).fill(reason);
        await pickDue(operator, target);

        // Pending until posted: nothing has been saved yet.
        await expect(trigger(operator)).toContainText(printed(target));
        await expect(trigger(operator)).toHaveAttribute('data-pending', 'set');
        await expect(operator.locator('[data-due-date-change]')).toHaveCount(0);

        await operator.getByRole('button', { name: 'Post Update' }).click();

        await expect(operator.getByText('Reply added and due date updated.')).toBeVisible();

        // The thread records who/what, and the page keeps the original SLA visible.
        const change = operator.locator('[data-due-date-change]');
        await expect(change).toHaveCount(1);
        await expect(change).toContainText(printed(target));
        await expect(change).toContainText('(UTC)');
        await expect(operator.getByText(/Original SLA:/)).toBeVisible();

        // Restoring is offered now that there is an override.
        await trigger(operator).click();
        await expect(operator.locator('[data-due-restore]')).toHaveCount(1);

        // The queue shows the effective date, labelled UTC, with the original behind "Adjusted".
        await signedIn(operator, `/operator/tickets?search=${ticket.number}`);
        await expect(operator.getByRole('columnheader', { name: 'Due (UTC)' })).toBeVisible();
        const row = operator.getByRole('row').filter({ hasText: ticket.number });
        await expect(row).toContainText(printed(target).replace(/ \d{4} /, ' '));
        await expect(row.locator('details summary')).toHaveText(/Adjusted/);

        // The requester: the public reply is there, no due-date data of any kind.
        await signedIn(page, ticket.url);
        await expect(page.getByText(reason)).toBeVisible();
        const html = await (await page.request.get(ticket.url)).text();
        for (const leak of [
            'Due date changed',
            'Original SLA',
            'data-due-date',
            printed(target),
            'due_override_at',
        ]) {
            expect(html, `the requester page must not contain "${leak}"`).not.toContain(leak);
        }
    } finally {
        await operatorContext.close();
    }
});

test('a refused adjustment keeps the internal note internal, the date pending and shows the reason', async ({
    page,
    contextFor,
}) => {
    const ticket = await createTicket(page, ticketTitle('due date refill'));
    const operatorContext = await contextFor('operator', { viewport: DESKTOP });

    try {
        const operator = await operatorContext.newPage();
        await openOperatorTicket(operator, ticket.number);

        const note = `Internal only ${randomBytes(4).toString('hex')}.`;
        await operator.getByPlaceholder(/Write your reply/).fill(note);
        await operator.locator('[data-visibility-button]').click();

        // The inline editor refuses a past date itself; a stale or hand-built post is what the server rule answers. Put the
        // contract fields where the control would, with a date in the past.
        await operator.locator('[data-due-action]').evaluate((el) => {
            (el as HTMLInputElement).value = 'set';
        });
        await operator.locator('[data-due-at]').evaluate((el, value) => {
            (el as HTMLInputElement).value = value;
        }, daysAhead(-2));
        await operator.getByRole('button', { name: 'Post Update' }).click();

        await expect(operator.locator('#due_at-error')).toHaveText(
            'The new due date must be in the future.',
        );

        // Nothing was recorded, and the form came back as the operator left it: still an internal
        // note, so a second click cannot publish it (and email it) as a public reply.
        await expect(operator.locator('[data-due-date-change]')).toHaveCount(0);
        await expect(operator.getByPlaceholder(/Write your reply/)).toHaveValue(note);
        const hidden = operator.locator('[data-visibility-button]');
        await expect(hidden).toHaveAttribute('aria-pressed', 'true');
        await expect(operator.locator('[data-visibility-input]')).toHaveValue('1');
        await expect(trigger(operator)).toHaveAttribute('data-pending', 'set');
        await expect(trigger(operator)).toContainText(printed(daysAhead(-2)));

        // The requester never saw the note.
        await signedIn(page, ticket.url);
        await expect(page.getByText(note)).toHaveCount(0);
    } finally {
        await operatorContext.close();
    }
});

test('restoring the SLA deadline is a recorded, reasoned change', async ({ page, contextFor }) => {
    const ticket = await createTicket(page, ticketTitle('due date restore'));
    const operatorContext = await contextFor('operator', { viewport: DESKTOP });

    try {
        const operator = await operatorContext.newPage();
        await openOperatorTicket(operator, ticket.number);

        await operator.getByPlaceholder(/Write your reply/).fill('Extending for now.');
        await pickDue(operator, daysAhead(7));
        await operator.getByRole('button', { name: 'Post Update' }).click();
        await expect(operator.getByText('Reply added and due date updated.')).toBeVisible();

        await operator.getByPlaceholder(/Write your reply/).fill('Back to the original deadline.');
        await trigger(operator).click();
        await operator.locator('[data-due-restore]').click();
        // Pending until posted, and the contract fields say restore with no date.
        await expect(trigger(operator)).toHaveAttribute('data-pending', 'restore');
        await expect(operator.locator('[data-due-action]')).toHaveValue('restore');
        await expect(operator.locator('[data-due-at]')).toHaveValue('');
        await operator.getByRole('button', { name: 'Post Update' }).click();
        await expect(operator.getByText('Reply added and due date updated.')).toBeVisible();

        await expect(operator.locator('[data-due-date-change]')).toHaveCount(2);
        await expect(operator.locator('[data-due-date-change]').last()).toContainText(
            'the SLA deadline',
        );
        // No override left: nothing to restore, and no "Original SLA:" disclosure line.
        await expect(operator.getByText(/Original SLA:/)).toHaveCount(0);
    } finally {
        await operatorContext.close();
    }
});
