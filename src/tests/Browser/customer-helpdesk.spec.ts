import { randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';

import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';

/**
 * EPIC-017 WP2 G3-B — the customer Helpdesk in real Chromium (§25.3 journeys 3–8) plus the stale
 * closed-reply regression for the G2 focus fix.
 *
 * **Actors.** `customer` (`e2e-customer@intechral.test`) owns every ticket below; `operator` is the
 * existing Blade operator (`/operator/tickets/{id}`, which WP3 will replace — these tests only drive
 * what exists); `customerOther` is the second, unrelated customer used for isolation. No account is
 * created.
 *
 * **Fixture ownership.** Every ticket is created through the real form by a test in this file, titled
 * with a literal `E2E-` prefix plus a per-ticket random token (`ticketTitle`), so a retry, a second
 * worker or a later run can never collide with, or bind to, another attempt's ticket. Tickets are
 * addressed only by the URL captured from the create redirect or by that unique title, never by list
 * position or id arithmetic. `TKT-E2E1` and `TKT-E2E2` are never touched, and no time entry or task is
 * linked to anything created here (the cleanup command refuses a ticket that has either).
 *
 * **Cleanup.** None here, on purpose. Tickets and their private files are removed once, after the whole
 * suite, by `./dev test:e2e` (or the `globalTeardown` for a hand-started run): the command is untargeted,
 * so a per-test or per-worker call would delete tickets other workers are still using
 * (support/global-teardown.ts, docs/testing/e2e-browser-suite.md).
 *
 * **Retries.** The primary journey (3 → 8) is a serial group because each step reads the ticket the first
 * one made. Playwright re-runs a failed serial group from its first test in a fresh worker, where this
 * module is evaluated again, so `primary` starts undefined and journey 3 makes a new ticket; later steps
 * fail by name if journey 3 did not leave one (`primaryTicket`). Everything else is an independent test
 * that builds its own ticket.
 */

test.use({ persona: 'customer', viewport: { width: 1280, height: 900 } });

const DESKTOP = { width: 1280, height: 900 };
const CLOSED_MESSAGE = 'This request is closed. Raise a new request if you need more help.';

/** `E2E-` + 16 hex characters of entropy + a label: unique per ticket, per attempt, per run. */
function ticketTitle(label: string) {
    return `E2E-${randomBytes(8).toString('hex')} ${label}`;
}

/** A small plain-text upload; libmagic detects text/plain, so it satisfies the `txt` allowlist pair. */
function textFile(name: string, text: string) {
    return { name, mimeType: 'text/plain', buffer: Buffer.from(text, 'utf8') };
}

type Created = { id: number; number: string; url: string };

/** Raise a request through the real form (no attachment) and return what the redirect landed on. */
async function createTicket(page: Page, title: string, description: string): Promise<Created> {
    await signedIn(page, '/tickets/create');
    await page.getByLabel(/^Subject/).fill(title);
    await page.getByLabel(/^Description/).fill(description);
    await page.getByRole('button', { name: 'Submit request' }).click();

    return landedTicket(page, title);
}

async function landedTicket(page: Page, title: string): Promise<Created> {
    await expect(page).toHaveURL(/\/tickets\/\d+$/);
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();

    const url = new URL(page.url()).pathname;
    const number = (await page.locator('header p.font-mono').innerText()).trim();

    return { id: Number(url.split('/').pop()), number, url };
}

/** The operator's Blade page for one ticket, in a context that is signed in as the operator. */
async function openOperatorTicket(page: Page, ticket: Created) {
    await signedIn(page, `/operator/tickets/${ticket.id}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

async function operatorSetStatus(page: Page, ticket: Created, status: string) {
    await openOperatorTicket(page, ticket);
    await page.getByRole('combobox', { name: 'Status' }).selectOption(status);
    await page.getByRole('button', { name: 'Update Status' }).click();
    await expect(page.getByRole('combobox', { name: 'Status' })).toHaveValue(status);
}

/** Posts through the Blade reply form. `file` is attached when given; `internal` ticks the note box. */
async function operatorReply(
    page: Page,
    ticket: Created,
    reply: { body: string; internal?: boolean; file?: ReturnType<typeof textFile> },
) {
    await openOperatorTicket(page, ticket);
    await page.getByPlaceholder(/Write your reply/).fill(reply.body);

    if (reply.internal) await page.getByLabel(/Internal note/).check();
    if (reply.file) await page.getByLabel('Attachments').setInputFiles(reply.file);

    await page.getByRole('button', { name: 'Post Reply' }).click();
    await expect(page.getByText('Reply added.')).toBeVisible();
}

/** The ticket's own header (number, title, status), not the shell's top bar. */
const ticketHeader = (page: Page) => page.getByRole('main').locator('header');

/** A conversation entry (`li`) on the customer ticket page, found by its text. */
const entryWith = (page: Page, text: string) =>
    page.getByRole('list').getByRole('listitem').filter({ hasText: text });

/**
 * The Inertia page object the server put into an HTML document, parsed. A document request carries
 * the serialized props exactly as the first paint received them.
 */
async function documentProps(page: Page, path: string) {
    const response = await page.request.get(path);
    expect(response.status(), `GET ${path}`).toBe(200);
    const html = await response.text();
    const match =
        /<script[^>]*data-page="app"[^>]*>([\s\S]*?)<\/script>/.exec(html) ??
        /\sdata-page="([^"]*)"/.exec(html);
    expect(match, 'the document carries a serialized Inertia page').not.toBeNull();

    const json = match![1].replace(/&quot;/g, '"').replace(/&amp;/g, '&');

    return { html, page: JSON.parse(json) as { props: Record<string, unknown>; url: string } };
}

// ───────────────────────────────────────────────────────────────────────────────────────────────
// Journeys 3–8: one ticket, in order.
// ───────────────────────────────────────────────────────────────────────────────────────────────

type Primary = Created & {
    title: string;
    attachmentName: string;
    attachmentText: string;
    attachmentHref: string;
    customerReply: string;
    operatorReply: string;
    noteSentinel: string;
    noteFileName: string;
    noteHref?: string;
};

let primary: Primary | undefined;

function primaryTicket(): Primary {
    if (!primary) {
        throw new Error(
            'Journey 3 did not leave a ticket behind, so this step has nothing to act on. Fix journey 3 first.',
        );
    }

    return primary;
}

test.describe('customer Helpdesk journeys 3–8 on one disposable ticket', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeAll(() => {
        // A serial retry runs in a fresh worker, but state is reset explicitly rather than relied upon.
        primary = undefined;
    });

    test('3. a customer raises a request with an attachment and sees it confirmed', async ({
        page,
    }) => {
        const token = randomBytes(8).toString('hex');
        const title = ticketTitle('create with attachment');
        const attachmentName = `E2E-${token}-brief.txt`;
        const attachmentText = `Plain-text brief ${token}\nSecond line, with “quotes” and ünïcode.\n`;

        await signedIn(page, '/tickets/create');
        await expect(page.getByRole('heading', { level: 1, name: 'New request' })).toBeVisible();

        // The company selector is gone (F-1), and the form offers the policy it enforces.
        await expect(page.getByLabel(/company/i)).toHaveCount(0);
        await expect(page.getByText(/Up to 10 files, 20 MB each\. Allowed types:/)).toBeVisible();

        await page.getByLabel(/^Subject/).fill(title);
        await page
            .getByLabel(/^Description/)
            .fill('The browser suite is exercising the customer Helpdesk end to end.');
        await page
            .getByLabel('Attachments')
            .setInputFiles(textFile(attachmentName, attachmentText));

        await page.getByRole('button', { name: 'Submit request' }).click();

        const created = await landedTicket(page, title);
        expect(created.number).toMatch(/^TKT-\d{4,}$/);

        // Confirmation: the server's flash, announced in a live region.
        await expect(
            page.getByRole('status').filter({ hasText: `Request ${created.number} received.` }),
        ).toBeVisible();

        // The opening entry carries the attachment, named and sized.
        const attached = page.getByRole('list', { name: 'Attached files' });
        const link = attached.getByRole('link', { name: new RegExp(attachmentName) });
        await expect(link).toBeVisible();
        await expect(link).toContainText(/\(\d+(\.\d+)? ?(B|KB)\)/);
        const attachmentHref = new URL((await link.getAttribute('href'))!).pathname;
        expect(attachmentHref).toMatch(/\/attachments\/\d+\/download$/);

        // A real browser download returns the bytes that were uploaded, as an attachment.
        const downloading = page.waitForEvent('download');
        await link.click();
        const download = await downloading;
        expect(download.suggestedFilename()).toBe(attachmentName);
        expect(await readFile((await download.path())!, 'utf8')).toBe(attachmentText);

        const direct = await page.request.get(attachmentHref);
        expect(direct.status()).toBe(200);
        expect(direct.headers()['content-disposition']).toMatch(/^attachment/);
        expect((await direct.body()).toString('utf8')).toBe(attachmentText);

        // Nothing operational is offered on the requester page.
        await expect(page.getByRole('combobox', { name: /status|assignee/i })).toHaveCount(0);
        await expect(page.getByLabel(/internal note/i)).toHaveCount(0);
        await expect(page.getByRole('button', { name: /timer/i })).toHaveCount(0);
        await expect(page.locator('[data-shell-timer]')).toHaveCount(0);
        await expect(page.getByLabel(/company/i)).toHaveCount(0);

        primary = {
            ...created,
            title,
            attachmentName,
            attachmentText,
            attachmentHref,
            customerReply: `Customer reply ${token}: here is more detail.`,
            operatorReply: `Operator public reply ${token}: we are looking into it.`,
            noteSentinel: `E2E-NOTE-${randomBytes(8).toString('hex')}`,
            noteFileName: `E2E-${token}-internal-only.txt`,
        };
    });

    test('4. the customer lists, searches and filters; the query survives opening and Back', async ({
        page,
    }) => {
        const ticket = primaryTicket();

        await signedIn(page, '/tickets');
        await expect(page.getByRole('heading', { level: 1, name: 'Your requests' })).toBeVisible();

        const filters = page.getByRole('group', { name: 'Filter requests' });
        const search = filters.getByRole('searchbox', { name: 'Search requests' });
        const status = filters.getByRole('combobox', { name: 'Status' });
        const rows = page.getByRole('list', { name: 'Your requests' }).getByRole('listitem');
        const queryOf = () => new URL(page.url()).searchParams;

        // Search by the unique title: Enter commits it.
        await search.fill(ticket.title);
        await search.press('Enter');
        await expect.poll(() => queryOf().get('search')).toBe(ticket.title);
        await expect(page.getByText('1 request matches')).toBeVisible();
        await expect(rows).toHaveCount(1);
        await expect(rows.first().getByRole('link', { name: ticket.title })).toHaveAttribute(
            'href',
            new RegExp(`${ticket.url}$`),
        );
        await expect(rows.first()).toContainText(ticket.number);
        await expect(rows.first()).toContainText('Open');

        // A supported status filter, combined with the search.
        await status.selectOption('open');
        await expect.poll(() => queryOf().get('status')).toBe('open');
        expect(queryOf().get('search')).toBe(ticket.title);
        await expect(rows).toHaveCount(1);
        await expect(page.getByRole('list', { name: 'Active filters' })).toContainText(
            'Status: Open',
        );

        // Open the match, then come back with the browser's Back.
        await rows.first().getByRole('link', { name: ticket.title }).click();
        await expect(page).toHaveURL(new RegExp(`${ticket.url}$`));
        await expect(page.getByRole('heading', { level: 1, name: ticket.title })).toBeVisible();

        await page.goBack();

        await expect(page).toHaveURL(/\/tickets\?/);
        expect(queryOf().get('search')).toBe(ticket.title);
        expect(queryOf().get('status')).toBe('open');
        await expect(search).toHaveValue(ticket.title);
        await expect(status).toHaveValue('open');
        await expect(rows).toHaveCount(1);
        await expect(rows.first().getByRole('link', { name: ticket.title })).toBeVisible();

        // Filtered-empty: the same search under a status the ticket does not have. The customer has
        // other requests (the seeded one), so this is "nothing matches", not "no requests yet".
        await status.selectOption('closed');
        await expect.poll(() => queryOf().get('status')).toBe('closed');
        await expect(page.getByText('No requests match these filters.')).toBeVisible();
        await expect(page.getByRole('list', { name: 'Your requests' })).toHaveCount(0);
        await expect(page.getByText("You haven't raised any requests yet.")).toHaveCount(0);

        // Widening again finds it; the ticket never left its requester's list.
        await status.selectOption('');
        await expect.poll(() => queryOf().has('status')).toBe(false);
        await expect(rows).toHaveCount(1);

        // "All requests" on the detail page deliberately returns to the unfiltered list.
        await rows.first().getByRole('link', { name: ticket.title }).click();
        await expect(page).toHaveURL(new RegExp(`${ticket.url}$`));
        await page.getByRole('link', { name: 'All requests' }).click();
        await expect(page).toHaveURL(/\/tickets$/);
        await expect(search).toHaveValue('');
        await expect(status).toHaveValue('');
    });

    test('5. the customer replies: persisted, announced, focused, composer cleared', async ({
        page,
    }) => {
        const ticket = primaryTicket();

        await signedIn(page, ticket.url);

        const composer = page.getByLabel(/^Your reply/);
        const send = page.getByRole('button', { name: 'Send reply' });

        await expect(send).toBeDisabled();
        await composer.fill(ticket.customerReply);
        await expect(send).toBeEnabled();

        // The real Inertia lifecycle: an XHR visit with the Inertia header, not a document post.
        const request = page.waitForRequest(
            (candidate) =>
                candidate.method() === 'POST' &&
                new URL(candidate.url()).pathname === `${ticket.url}/replies`,
        );
        await send.click();
        expect((await request).headers()['x-inertia']).toBe('true');

        const entry = entryWith(page, ticket.customerReply);
        await expect(entry).toHaveCount(1);
        await expect(entry).toContainText('E2E Customer');
        await expect(page.getByRole('status').filter({ hasText: 'Reply sent.' })).toBeVisible();
        await expect(entry).toBeFocused();

        // Cleared, and ready for another.
        await expect(composer).toHaveValue('');
        await expect(send).toBeDisabled();
        await expect(page.getByLabel('Attachments')).toHaveValue('');

        // An `open` ticket stays open on a customer reply (O2).
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(ticketHeader(page)).toContainText('Open');

        // Persisted: a fresh document load has it, in order after the description.
        await page.reload();
        await expect(entryWith(page, ticket.customerReply)).toHaveCount(1);
        await expect(page.getByRole('list').getByRole('listitem').first()).toContainText(
            'The browser suite is exercising',
        );
    });

    test('6. an operator public reply is visible to the customer, as a public reply', async ({
        page,
        contextFor,
    }) => {
        const ticket = primaryTicket();
        const operatorContext = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await operatorContext.newPage();
            await operatorReply(operator, ticket, { body: ticket.operatorReply });

            // On the operator's own page it is a plain reply, not an internal note.
            const mine = operator
                .locator('div.rounded-lg')
                .filter({ hasText: ticket.operatorReply });
            await expect(mine.last()).toBeVisible();
            await expect(mine.last().locator('[data-internal-note]')).toHaveCount(0);
        } finally {
            await operatorContext.close();
        }

        await signedIn(page, ticket.url);

        const entry = entryWith(page, ticket.operatorReply);
        await expect(entry).toHaveCount(1);
        await expect(entry).toContainText('Dev Operator');
        await expect(entry.locator('[data-internal-note]')).toHaveCount(0);
        await expect(entry).not.toContainText(/internal/i);
        await expect(entryWith(page, ticket.customerReply)).toHaveCount(1);

        // What the server serialized for that entry is the requester shape, nothing more: a name for
        // the author (no id, no email), no internal flag, no operator-only keys.
        const { html, page: inertia } = await documentProps(page, ticket.url);
        const conversation = inertia.props.conversation as Record<string, unknown>[];
        const served = conversation.find((candidate) =>
            String(candidate.body).includes(ticket.operatorReply),
        );

        expect(served, 'the operator reply is in the served conversation').toBeDefined();
        expect(Object.keys(served!).sort()).toEqual([
            'attachments',
            'author',
            'body',
            'createdAt',
            'createdAtHuman',
            'key',
            'kind',
        ]);
        expect(Object.keys(served!.author as object)).toEqual(['name']);
        expect(html).not.toContain('operator@intechral.test');
        expect(html).not.toMatch(/is_internal|isInternal/);
    });

    test('7. an internal note and its attachment never reach the customer', async ({
        page,
        contextFor,
    }) => {
        const ticket = primaryTicket();
        const operatorContext = await contextFor('operator', { viewport: DESKTOP });
        let noteHref: string;

        try {
            const operator = await operatorContext.newPage();
            await operatorReply(operator, ticket, {
                body: `Internal note ${ticket.noteSentinel}: do not tell the customer.`,
                internal: true,
                file: textFile(ticket.noteFileName, `Internal file ${ticket.noteSentinel}\n`),
            });

            // The operator sees it, marked internal, with a working download link to learn the URL from.
            const note = operator
                .locator('div.rounded-lg')
                .filter({ hasText: ticket.noteSentinel })
                .last();
            await expect(note.locator('[data-internal-note]')).toBeVisible();
            const noteLink = note.getByRole('link', { name: new RegExp(ticket.noteFileName) });
            noteHref = new URL((await noteLink.getAttribute('href'))!).pathname;
            expect(noteHref).toMatch(/^\/attachments\/\d+\/download$/);

            // The operator may download it: the route works, so the customer's 403 below is authorization.
            const asOperator = await operator.request.get(noteHref);
            expect(asOperator.status()).toBe(200);
            expect((await asOperator.body()).toString('utf8')).toContain(ticket.noteSentinel);
        } finally {
            await operatorContext.close();
        }

        primaryTicket().noteHref = noteHref;
        expect(noteHref).not.toBe(ticket.attachmentHref);

        // The customer's page: public replies are there, the note is not, in the rendered DOM...
        await signedIn(page, ticket.url);
        await expect(entryWith(page, ticket.operatorReply)).toHaveCount(1);
        await expect(entryWith(page, ticket.customerReply)).toHaveCount(1);
        await expect(
            page.getByRole('list', { name: 'Attached files' }).getByRole('link'),
        ).toHaveCount(1);
        await expect(page.getByText(ticket.noteSentinel)).toHaveCount(0);
        await expect(page.getByText(ticket.noteFileName)).toHaveCount(0);

        const rendered = await page.content();
        expect(rendered).not.toContain(ticket.noteSentinel);
        expect(rendered).not.toContain(ticket.noteFileName);
        expect(rendered).not.toContain(noteHref);

        // ... and in the serialized props, which are not a rendering concern: the document's page
        // object, then a real Inertia visit's JSON response.
        const initial = await documentProps(page, ticket.url);
        const serialized = JSON.stringify(initial.page) + initial.html;
        expect(serialized).not.toContain(ticket.noteSentinel);
        expect(serialized).not.toContain(ticket.noteFileName);
        expect(serialized).not.toContain(noteHref);
        expect(initial.page.props.conversation as unknown[]).toHaveLength(3);

        await signedIn(page, `/tickets?search=${encodeURIComponent(ticket.title)}`);
        const visit = page.waitForResponse(
            (response) =>
                response.request().headers()['x-inertia'] === 'true' &&
                new URL(response.url()).pathname === ticket.url,
        );
        await page.getByRole('link', { name: ticket.title }).click();
        const inertiaBody = await (await visit).text();
        expect(inertiaBody).toContain(ticket.operatorReply);
        expect(inertiaBody).not.toContain(ticket.noteSentinel);
        expect(inertiaBody).not.toContain(ticket.noteFileName);
        expect(inertiaBody).not.toContain(noteHref);

        // The attachment itself is refused with 403 by the policy, and the public one still downloads.
        const refused = await page.request.get(noteHref);
        expect(refused.status()).toBe(403);
        // The refusal page says nothing about the note (its Back link would echo the customer's own
        // previous URL, which is why this runs before the sentinel is ever searched for).
        const refusedBody = await refused.text();
        expect(refusedBody).not.toContain(ticket.noteSentinel);
        expect(refusedBody).not.toContain(ticket.noteFileName);
        expect(refusedBody).not.toContain('do not tell the customer');
        const control = await page.request.get(ticket.attachmentHref);
        expect(control.status()).toBe(200);
        expect((await control.body()).toString('utf8')).toBe(ticket.attachmentText);

        // Search covers public text only: the sentinel finds nothing.
        await page.goto(`/tickets?search=${encodeURIComponent(ticket.noteSentinel)}`);
        await expect(page.getByText('No requests match these filters.')).toBeVisible();
        await expect(page.getByRole('list', { name: 'Your requests' })).toHaveCount(0);
    });

    test('8. another customer is refused the ticket and its attachments, and never lists it', async ({
        contextFor,
    }) => {
        const ticket = primaryTicket();
        const noteHref = ticket.noteHref;
        expect(noteHref, 'journey 7 recorded the internal attachment URL').toBeDefined();

        const other = await contextFor('customerOther', { viewport: DESKTOP });

        try {
            const page = await other.newPage();

            // Direct access, judged by status code, not by what an empty page looks like.
            await signedIn(page, '/tickets');
            expect((await page.request.get(ticket.url)).status()).toBe(403);
            expect((await page.request.get(ticket.attachmentHref)).status()).toBe(403);
            expect((await page.request.get(noteHref!)).status()).toBe(403);
            expect((await page.request.get(`/operator/tickets/${ticket.id}`)).status()).toBe(403);

            // In the browser: refused inside the customer frame, and nothing about the ticket leaks.
            const response = await page.goto(ticket.url);
            expect(response?.status()).toBe(403);
            await expect(page.locator('[data-shell="focused"]')).toHaveCount(1);
            const refusedPage = await page.content();
            expect(refusedPage).not.toContain(ticket.title);
            expect(refusedPage).not.toContain(ticket.number);

            // The list: absent, whatever order, and a search for its unique title exposes nothing.
            await page.goto('/tickets');
            await expect(
                page.getByRole('heading', { level: 1, name: 'Your requests' }),
            ).toBeVisible();
            await expect(page.locator(`a[href$="${ticket.url}"]`)).toHaveCount(0);
            await expect(page.getByText(ticket.title)).toHaveCount(0);

            const search = page
                .getByRole('group', { name: 'Filter requests' })
                .getByRole('searchbox', { name: 'Search requests' });

            // This customer may have no requests at all, in which case there is no filter bar to use;
            // the list URL carries the same query either way, which is what the server decides on.
            await page.goto(`/tickets?search=${encodeURIComponent(ticket.title)}`);
            await expect(
                page.getByRole('heading', { level: 1, name: 'Your requests' }),
            ).toBeVisible();
            await expect(page.getByRole('list', { name: 'Your requests' })).toHaveCount(0);
            await expect(page.locator(`a[href$="${ticket.url}"]`)).toHaveCount(0);
            await expect(page.getByText(ticket.number, { exact: true })).toHaveCount(0);
            await expect(page.getByText(/\d+ requests? match/)).toHaveCount(0);

            const served = await documentProps(
                page,
                `/tickets?search=${encodeURIComponent(ticket.title)}`,
            );
            expect(JSON.stringify(served.page.props.tickets)).not.toContain(ticket.title);

            // The same through the search box, when there is one.
            if (await search.count()) {
                await search.fill(ticket.title);
                await search.press('Enter');
                await expect(page).toHaveURL(/search=/);
                await expect(page.getByRole('list', { name: 'Your requests' })).toHaveCount(0);
            }
        } finally {
            await other.close();
        }
    });
});

// ───────────────────────────────────────────────────────────────────────────────────────────────
// Journey 3, negative: a refused upload creates nothing.
// ───────────────────────────────────────────────────────────────────────────────────────────────

test('3b. a disallowed attachment is refused with a visible message and creates no ticket', async ({
    page,
}) => {
    const title = ticketTitle('refused upload');

    await signedIn(page, '/tickets/create');
    await page.getByLabel(/^Subject/).fill(title);
    await page.getByLabel(/^Description/).fill('This request must never be created.');
    // The allowlist is checked on the bytes: an HTML document is refused whatever it is called.
    await page.getByLabel('Attachments').setInputFiles({
        name: 'notes.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('<!doctype html><html><body><script>alert(1)</script></body></html>'),
    });
    await page.getByRole('button', { name: 'Submit request' }).click();

    const alert = page.locator('#create-attachments-error');
    await expect(alert).toContainText('notes.txt');
    await expect(alert).toContainText('This file type cannot be attached.');
    await expect(page).toHaveURL(/\/tickets\/create$/);
    await expect(page.locator('#create-attachments')).toBeFocused();
    // The customer's input is still there to correct.
    await expect(page.getByLabel(/^Subject/)).toHaveValue(title);

    // Server-side: nothing was created under that title.
    await page.goto(`/tickets?search=${encodeURIComponent(title)}`);
    await expect(page.getByText('No requests match these filters.')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Your requests' })).toHaveCount(0);
});

// ───────────────────────────────────────────────────────────────────────────────────────────────
// Journey 5, state transition: a reply to a request that is waiting on the customer.
// ───────────────────────────────────────────────────────────────────────────────────────────────

test('5b. replying to a request waiting on the customer sends it back to the team (pending_user → in_progress)', async ({
    page,
    contextFor,
}) => {
    const title = ticketTitle('waiting on customer');
    const reply = `Here is what you asked for ${randomBytes(6).toString('hex')}.`;
    const ticket = await createTicket(page, title, 'Needs an answer from the customer.');
    const operatorContext = await contextFor('operator', { viewport: DESKTOP });

    try {
        await operatorSetStatus(await operatorContext.newPage(), ticket, 'pending_user');
    } finally {
        await operatorContext.close();
    }

    await signedIn(page, ticket.url);
    await expect(ticketHeader(page)).toContainText('Waiting on you');
    await expect(
        page.getByText('Replying sends this request back to the support team.'),
    ).toBeVisible();

    await page.getByLabel(/^Your reply/).fill(reply);
    await page.getByRole('button', { name: 'Send reply' }).click();

    await expect(entryWith(page, reply)).toBeFocused();
    await expect(page.getByRole('status').filter({ hasText: 'Reply sent.' })).toBeVisible();
    // The status changed on the server and the page reflects it, with the customer-label history.
    await expect(ticketHeader(page)).toContainText('In progress');
    await expect(
        page.getByText('Replying sends this request back to the support team.'),
    ).toHaveCount(0);
    await expect(page.getByText('Status changed to In progress')).toBeVisible();
});

// ───────────────────────────────────────────────────────────────────────────────────────────────
// G2 regression: a reply sent after the ticket was closed from elsewhere.
// ───────────────────────────────────────────────────────────────────────────────────────────────

test('a reply composed before the ticket was closed is refused visibly, keeps the draft, and persists nothing', async ({
    page,
    contextFor,
}) => {
    const title = ticketTitle('stale closed reply');
    const draft = `Unsent draft ${randomBytes(8).toString('hex')}: thanks, one more thing.`;
    const ticket = await createTicket(page, title, 'Will be closed while the customer is typing.');

    // 1. The customer opens the ticket and starts writing; nothing is sent yet.
    const composer = page.getByLabel(/^Your reply/);
    await composer.fill(draft);
    await expect(page.getByRole('button', { name: 'Send reply' })).toBeEnabled();

    // 2. The operator closes the same ticket, from another session.
    const operatorContext = await contextFor('operator', { viewport: DESKTOP });

    try {
        await operatorSetStatus(await operatorContext.newPage(), ticket, 'closed');
    } finally {
        await operatorContext.close();
    }

    // The customer's page is stale on purpose: it still offers the composer and the draft.
    await expect(composer).toHaveValue(draft);

    // 3. The stale reply is submitted through the real Inertia path.
    const request = page.waitForRequest(
        (candidate) =>
            candidate.method() === 'POST' &&
            new URL(candidate.url()).pathname === `${ticket.url}/replies`,
    );
    await page.getByRole('button', { name: 'Send reply' }).click();
    expect((await request).headers()['x-inertia']).toBe('true');

    // 4–6. The server refused it, the refusal is visible, and it is where focus went.
    const refusal = page.getByRole('group', { name: 'Reply refused' });
    await expect(refusal).toBeVisible();
    await expect(refusal.getByRole('alert')).toHaveText(new RegExp(CLOSED_MESSAGE));
    await expect(refusal).toBeFocused();

    // 7. The unsent text is preserved where the customer can copy it.
    await expect(page.getByLabel('Your message was not sent')).toHaveValue(draft);

    // 8. Nothing claims success.
    await expect(page.getByRole('status').filter({ hasText: 'Reply sent.' })).toHaveCount(0);
    await expect(page.locator('p[role="status"]')).toHaveText('');
    await expect(entryWith(page, draft)).toHaveCount(0);

    // 9. The closed notice stands, and the composer is gone.
    await expect(page.getByRole('status').filter({ hasText: CLOSED_MESSAGE })).toBeVisible();
    await expect(page.getByRole('link', { name: 'New request' }).last()).toBeVisible();
    await expect(ticketHeader(page)).toContainText('Closed');
    await expect(composer).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Send reply' })).toHaveCount(0);

    // 10. No reply was persisted: not in the customer's fresh page, nor in the operator's thread.
    await page.reload();
    await expect(page.getByText(draft)).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Reply refused' })).toHaveCount(0);
    await expect(page.getByText(CLOSED_MESSAGE)).toBeVisible();

    const operatorAgain = await contextFor('operator', { viewport: DESKTOP });

    try {
        const operator = await operatorAgain.newPage();
        await openOperatorTicket(operator, ticket);
        await expect(operator.getByText(draft)).toHaveCount(0);
        await expect(operator.getByRole('combobox', { name: 'Status' })).toHaveValue('closed');
    } finally {
        await operatorAgain.close();
    }
});
