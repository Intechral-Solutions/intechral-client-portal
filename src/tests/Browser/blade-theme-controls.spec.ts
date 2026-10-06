import type { Locator, Page } from '@playwright/test';

import { signedIn } from './support/auth';
import { expect, test } from './support/e2e-fixtures';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-016 WP1 (Controls and Accessibility): the Blade control layer in real Chromium (§18.4).
 *
 * Every route runs in light AND dark, at 1440 and 390. For each, the spec proves: no document-level
 * horizontal overflow; keyboard `Tab` through `main` shows the 2px `focus` outline on every focus stop
 * (§10.1); field boundaries are `control-edge`; primary actions compute to `ink`; secondary to `surface`
 * with the `control-edge` boundary; destructive triggers compute to `danger` and are not ink; checkboxes
 * use the selection accent; and the accessible names the epic fixes still resolve by role and name.
 *
 * Colours are compared against the LIVE `--ds-*` values of the current theme, resolved in the browser, so
 * a legacy indigo or a hard-coded hex fails by value, not by source text.
 *
 * Authentication comes from the per-worker persona sessions (support/auth.ts); nothing here logs in. Every
 * record the spec creates (a company, a contact, an invoice, a CMS page, 26 companies for pagination) is
 * deleted through the application's own DELETE routes in `afterEach`, pass or fail, and the ticket pages
 * use only records that already exist (a ticket has no delete route, so none is created).
 */

const XL = { width: 1440, height: 900 };
const S = { width: 390, height: 844 };
const THEMES = ['light', 'dark'] as const;
const MODES = THEMES.flatMap((theme) => [XL, S].map((viewport) => ({ theme, viewport })));

type Theme = (typeof THEMES)[number];
type Tokens = Record<
    'ink' | 'onInk' | 'surface' | 'controlEdge' | 'danger' | 'focus' | 'accent' | 'text',
    string
>;

/** The current theme's token values as the computed `rgb(...)` strings the browser reports. */
function readTokens(page: Page): Promise<Tokens> {
    return page.evaluate(() => {
        const probe = document.createElement('i');
        document.body.appendChild(probe);
        const resolve = (name: string) => {
            probe.style.color = `var(--ds-${name})`;
            return getComputedStyle(probe).color;
        };
        const tokens = {
            ink: resolve('ink'),
            onInk: resolve('on-ink'),
            surface: resolve('surface'),
            controlEdge: resolve('control-edge'),
            danger: resolve('danger'),
            focus: resolve('focus'),
            accent: resolve('accent'),
            text: resolve('text'),
        };
        probe.remove();

        return tokens;
    });
}

/**
 * Open `path` in a theme at a viewport, and wait out the 120ms colour transition the theme switch starts,
 * so computed colours are at rest. Transitions stay ON: the focus-ring check must see what a keyboard user
 * sees at the moment of focus (a ring that fades in from `currentColor` is a defect).
 */
async function visit(page: Page, path: string, theme: Theme, viewport: { width: number; height: number }) {
    await page.setViewportSize(viewport);
    await signedIn(page, path);
    await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
    await expect(page.locator('#main-content')).toBeVisible();
    await page.waitForTimeout(400);

    return readTokens(page);
}

/**
 * Target-control lookups are scoped to the main region. Label and role lookups are substring matches over
 * the whole document, and the shell's utility bar carries controls of its own (the timer pill, whose name
 * includes the running ticket's title, "Start timer", the navigation and the user menu): a parallel spec
 * that leaves a timer running must not make "Description" ambiguous. Shell-level assertions stay on `page`.
 */
const inMain = (page: Page) => page.locator('#main-content');

const style = (locator: Locator, property: string) =>
    locator.evaluate((element, name) => getComputedStyle(element).getPropertyValue(name), property);

/**
 * Tab through `main` and require every focus stop to show the Direction D focus outline: 2px solid in
 * the theme's `focus` token. Returns the stops visited (by tag and name) so a caller can assert a given
 * control was reached.
 */
async function expectFocusRingOnEveryStop(page: Page, tokens: Tokens): Promise<string[]> {
    await page.locator('#main-content').focus();

    const stops: string[] = [];

    for (let step = 0; step < 200; step++) {
        await page.keyboard.press('Tab');

        const stop = await page.evaluate(() => {
            const element = document.activeElement as HTMLElement | null;
            const main = document.getElementById('main-content');

            if (!element || !main || element === main || !main.contains(element)) {
                return null;
            }

            // A native composite's inner stop (the calendar button of <input type="date">) leaves the
            // host as `document.activeElement` but not `:focus`; the browser draws that ring itself.
            if (!element.matches(':focus')) {
                return 'native' as const;
            }

            const computed = getComputedStyle(element);

            return {
                label:
                    `${element.tagName.toLowerCase()}:` +
                    (element.getAttribute('name') ||
                        element.getAttribute('aria-label') ||
                        element.getAttribute('id') ||
                        (element.textContent ?? '').trim().slice(0, 40)),
                focusVisible: element.matches(':focus-visible'),
                outlineStyle: computed.outlineStyle,
                outlineWidth: computed.outlineWidth,
                outlineColor: computed.outlineColor,
            };
        });

        if (stop === null) {
            break;
        }

        if (stop === 'native') {
            continue;
        }

        stops.push(stop.label);
        expect(stop.focusVisible, `${stop.label} is keyboard-focused`).toBe(true);
        expect(stop.outlineStyle, `${stop.label} outline style`).toBe('solid');
        expect(stop.outlineWidth, `${stop.label} outline width`).toBe('2px');
        expect(stop.outlineColor, `${stop.label} outline colour is the focus token`).toBe(tokens.focus);
    }

    return stops;
}

/** Every visible text-like field and select in `main` rests on the `control-edge` boundary. */
async function expectFieldBoundaries(page: Page, tokens: Tokens) {
    const fields = page.locator(
        '#main-content :is(input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea):visible',
    );

    for (const field of await fields.all()) {
        const name = (await field.getAttribute('name')) ?? (await field.getAttribute('id'));

        expect(await style(field, 'border-top-color'), `${name} boundary`).toBe(tokens.controlEdge);
        expect(await style(field, 'background-color'), `${name} surface`).toBe(tokens.surface);
    }
}

async function expectInk(control: Locator, tokens: Tokens) {
    await expect(control).toBeVisible();
    expect(await style(control, 'background-color')).toBe(tokens.ink);
    expect(await style(control, 'color')).toBe(tokens.onInk);
}

async function expectSecondary(control: Locator, tokens: Tokens) {
    await expect(control).toBeVisible();
    expect(await style(control, 'background-color')).toBe(tokens.surface);
    expect(await style(control, 'border-top-color')).toBe(tokens.controlEdge);
}

/** A destructive TRIGGER: danger edge and text on the secondary surface, never ink. */
async function expectDangerTrigger(control: Locator, tokens: Tokens) {
    await expect(control).toBeVisible();
    expect(await style(control, 'border-top-color')).toBe(tokens.danger);
    expect(await style(control, 'color')).toBe(tokens.danger);
    expect(await style(control, 'background-color')).toBe(tokens.surface);
    expect(await style(control, 'background-color')).not.toBe(tokens.ink);
}

/**
 * A page-header action (a fixed-height control such as "New Ticket") stays whole at the current viewport:
 * inside it horizontally, its label on ONE line and inside the control's box vertically, and it receives
 * the pointer at its own centre. A label that wraps inside the fixed `h-9` control overflows the box (the
 * 390px defect this guards), which shows as two line boxes and as text outside the control's edges.
 */
async function expectHeaderActionIntact(control: Locator, viewport: { width: number; height: number }) {
    await expect(control).toBeVisible();

    const measured = await control.evaluate((element) => {
        const box = element.getBoundingClientRect();
        const lineTops = new Set<number>();
        let textTop = Infinity;
        let textBottom = -Infinity;
        const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);

        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            if (!(node.textContent ?? '').trim()) {
                continue;
            }

            const range = document.createRange();
            range.selectNodeContents(node);

            for (const rect of Array.from(range.getClientRects())) {
                lineTops.add(Math.round(rect.top));
                textTop = Math.min(textTop, rect.top);
                textBottom = Math.max(textBottom, rect.bottom);
            }
        }

        const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

        return {
            left: box.left,
            right: box.right,
            top: box.top,
            bottom: box.bottom,
            lines: lineTops.size,
            textTop,
            textBottom,
            reachable: hit !== null && element.contains(hit),
        };
    });

    expect(measured.left, 'header action: left edge inside the viewport').toBeGreaterThanOrEqual(0);
    expect(measured.right, 'header action: right edge inside the viewport').toBeLessThanOrEqual(viewport.width);
    expect(measured.lines, 'header action: label on one line').toBe(1);
    expect(measured.textTop, 'header action: label starts inside the control').toBeGreaterThanOrEqual(measured.top - 1);
    expect(measured.textBottom, 'header action: label ends inside the control').toBeLessThanOrEqual(measured.bottom + 1);
    expect(measured.reachable, 'header action: receives the pointer at its centre').toBe(true);
}

/** The checks every route gets in every mode. */
async function expectControlContract(page: Page, tokens: Tokens) {
    expect(await hasHorizontalOverflow(page), 'no document-level horizontal overflow').toBe(false);
    await expectFieldBoundaries(page, tokens);

    const stops = await expectFocusRingOnEveryStop(page, tokens);
    expect(stops.length, 'the page has keyboard focus stops in main').toBeGreaterThan(0);

    return stops;
}

// ── records the spec creates, removed in afterEach ─────────────────────────────

const created: string[] = [];

async function csrf(page: Page) {
    await page.goto('/crm/companies');

    return (await page.locator('meta[name="csrf-token"]').getAttribute('content')) ?? '';
}

async function createViaForm(page: Page, path: string, token: string, form: Record<string, string>) {
    const response = await page.request.post(path, {
        form: { _token: token, ...form },
        headers: { 'X-CSRF-TOKEN': token },
        maxRedirects: 0,
    });

    expect(response.status(), `POST ${path}`).toBe(302);

    return response.headers()['location'];
}

test.afterEach(async ({ page }) => {
    if (created.length === 0) {
        return;
    }

    const token = await csrf(page);
    const failures: string[] = [];

    for (const url of created.splice(0).reverse()) {
        const response = await page.request.delete(url, {
            headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            maxRedirects: 0,
        });

        if (![200, 204, 302, 404].includes(response.status())) {
            failures.push(`${url} returned ${response.status()}`);
        }
    }

    // Drain the flash bag the deletions just wrote, so the next test's banners are its own.
    await page.goto('/crm/companies');
    expect(failures, 'spec fixture cleanup').toEqual([]);
});

/** `/crm/companies/12` from an absolute or relative Location header. */
const pathOf = (location: string) => new URL(location, 'http://localhost').pathname;

// ═══ Helpdesk ═════════════════════════════════════════════════════════════════════

test.describe('Helpdesk as an operator', () => {
    test('the ticket queue: names, focus, boundaries, actions and selection', async ({ page }) => {
        test.setTimeout(120_000);

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, '/operator/tickets', theme, viewport);

            // Pre-existing names still resolve; previously unnamed filters now have names.
            await expect(inMain(page).getByRole('searchbox', { name: 'Search…' })).toBeVisible();
            for (const name of ['Status', 'Priority', 'Assignee', 'Submitted from', 'Submitted to']) {
                await expect(inMain(page).getByLabel(name, { exact: true })).toBeVisible();
            }

            await expectSecondary(inMain(page).getByRole('button', { name: 'Filter', exact: true }), tokens);
            await expectSecondary(page.locator('#main-content').getByRole('link', { name: 'Reports', exact: true }), tokens);

            // Selection: the select-all checkbox is named, uses the selection accent, and drives the bulk bar.
            const selectAll = inMain(page).getByRole('checkbox', { name: 'Select all tickets on this page' });
            if ((await selectAll.count()) > 0) {
                expect(await style(selectAll, 'accent-color')).toBe(tokens.accent);
                await selectAll.check();
                await expect(page.locator('#bulk-bar')).toBeVisible();
                await expect(inMain(page).getByLabel('Bulk action')).toBeVisible();
                await expect(inMain(page).getByLabel('Assign to')).toBeVisible();
                await expectSecondary(inMain(page).getByRole('button', { name: 'Apply', exact: true }), tokens);
                await expect(inMain(page).getByRole('checkbox', { name: /^Select ticket / }).first()).toBeChecked();
                await selectAll.uncheck();
            }

            await expectControlContract(page, tokens);
        }
    });

    test('the report date range keeps its labels and a secondary Apply', async ({ page }) => {
        test.setTimeout(120_000);

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, '/operator/tickets/reports', theme, viewport);

            await expect(inMain(page).getByLabel('From', { exact: true })).toBeVisible();
            await expect(inMain(page).getByLabel('To', { exact: true })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('button', { name: 'Apply', exact: true }), tokens);
            await expectSecondary(inMain(page).getByRole('link', { name: 'Export CSV' }), tokens);
            await expectControlContract(page, tokens);
        }
    });

    test('an existing ticket: reply, status and assignee controls', async ({ page }) => {
        test.setTimeout(120_000);

        await signedIn(page, '/operator/tickets');
        const first = inMain(page).getByRole('row').getByRole('link', { name: 'View', exact: true }).first();
        test.skip((await first.count()) === 0, 'the development database has no ticket to open');
        const href = await first.getAttribute('href');

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, href!, theme, viewport);

            await expectInk(inMain(page).getByRole('button', { name: 'Post Reply' }), tokens);
            await expectSecondary(inMain(page).getByRole('button', { name: 'Update Status' }), tokens);
            await expectSecondary(inMain(page).getByRole('button', { name: 'Update Assignee' }), tokens);

            // The two selects are named through aria-labelledby by their existing headings.
            await expect(inMain(page).getByRole('combobox', { name: 'Status', exact: true })).toBeVisible();
            await expect(inMain(page).getByRole('combobox', { name: 'Assignee', exact: true })).toBeVisible();
            await expect(inMain(page).getByRole('textbox', { name: 'Write your reply…' })).toBeVisible();
            await expect(inMain(page).getByLabel('Attachments')).toBeVisible();

            const internal = inMain(page).getByRole('checkbox', { name: /Internal note/ });
            expect(await style(internal, 'accent-color')).toBe(tokens.accent);

            await expectControlContract(page, tokens);
        }
    });
});

test.describe('Helpdesk as a member', () => {
    test.use({ persona: 'member' });

    test('my requests and the new-ticket form', async ({ page }) => {
        test.setTimeout(120_000);

        for (const { theme, viewport } of MODES) {
            let tokens = await visit(page, '/tickets', theme, viewport);

            await expectInk(inMain(page).getByRole('link', { name: 'New Ticket' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: 'New Ticket' }), viewport);
            await expect(inMain(page).getByRole('searchbox', { name: 'Search tickets…' })).toBeVisible();
            await expect(inMain(page).getByRole('combobox', { name: 'Status', exact: true })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('button', { name: 'Filter', exact: true }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/tickets/create', theme, viewport);

            await expect(inMain(page).getByLabel('Subject')).toBeVisible();
            await expect(inMain(page).getByLabel('Category')).toBeVisible();
            await expect(inMain(page).getByLabel('Priority')).toBeVisible();
            // The description is the textarea named by its visible label, not a shell control whose name contains the word.
            const description = inMain(page).getByLabel('Description');
            await expect(description).toBeVisible();
            await expect(description).toHaveAttribute('id', 'description');
            expect(await description.evaluate((element) => element.tagName.toLowerCase() + ':' + element.getAttribute('name'))).toBe('textarea:description');
            await expect(inMain(page).getByLabel('Attachments')).toBeVisible();
            await expectInk(inMain(page).getByRole('button', { name: 'Submit Ticket' }), tokens);
            await expectControlContract(page, tokens);
        }
    });

    test('my invoices', async ({ page }) => {
        test.setTimeout(60_000);

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, '/my/invoices', theme, viewport);

            expect(await hasHorizontalOverflow(page)).toBe(false);
            // Pay Now repeats per row, so it is secondary (EPIC-016 §8.1); there may be no payable invoice.
            for (const pay of await inMain(page).getByRole('link', { name: 'Pay Now' }).all()) {
                await expectSecondary(pay, tokens);
            }
        }
    });

    test('errors/403: the member requesting /admin/users', async ({ page }) => {
        test.setTimeout(60_000);

        for (const { theme, viewport } of MODES) {
            await page.setViewportSize(viewport);
            const response = await page.goto('/admin/users');

            expect(response?.status()).toBe(403);
            await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
            await page.waitForTimeout(400);
            const tokens = await readTokens(page);

            await expect(inMain(page).getByRole('heading', { name: 'Access Denied' })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('link', { name: 'Go back' }), tokens);
            await expectInk(inMain(page).getByRole('link', { name: 'Dashboard' }), tokens);
            await expectControlContract(page, tokens);
        }
    });
});

// ═══ Directory ════════════════════════════════════════════════════════════════════

test.describe('Directory', () => {
    test('people and organizations: lists and create forms', async ({ page }) => {
        test.setTimeout(180_000);

        for (const { theme, viewport } of MODES) {
            let tokens = await visit(page, '/crm/contacts', theme, viewport);
            await expectInk(inMain(page).getByRole('link', { name: '+ New Contact' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: '+ New Contact' }), viewport);
            await expect(inMain(page).getByRole('textbox', { name: 'Search by name or email…' })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('button', { name: 'Search', exact: true }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/crm/contacts/create', theme, viewport);
            for (const name of ['First Name', 'Last Name', 'Company', 'Email', 'Phone', 'Job Title', 'Notes']) {
                await expect(inMain(page).getByLabel(name)).toBeVisible();
            }
            await expectInk(inMain(page).getByRole('button', { name: 'Create Contact' }), tokens);
            await expectSecondary(inMain(page).getByRole('link', { name: 'Cancel' }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/crm/companies', theme, viewport);
            await expectInk(inMain(page).getByRole('link', { name: '+ New Company' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: '+ New Company' }), viewport);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/crm/companies/create', theme, viewport);
            for (const name of ['Company Name', 'Website', 'Phone', 'Address', 'Notes']) {
                await expect(inMain(page).getByLabel(name)).toBeVisible();
            }
            await expectInk(inMain(page).getByRole('button', { name: 'Create Company' }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/organizations', theme, viewport);
            await expectSecondary(inMain(page).getByRole('link', { name: 'View Companies' }), tokens);
            expect(await hasHorizontalOverflow(page)).toBe(false);
        }
    });

    test('a company: destructive trigger semantics, and the native confirm is unchanged', async ({ page }) => {
        test.setTimeout(180_000);

        const token = await csrf(page);
        const location = await createViaForm(page, '/crm/companies', token, { name: 'E2E WP1 Company' });
        const companyPath = pathOf(location);
        created.push(companyPath);

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, `${companyPath}/edit`, theme, viewport);

            await expectInk(inMain(page).getByRole('button', { name: 'Save Changes' }), tokens);
            await expectDangerTrigger(inMain(page).getByRole('button', { name: 'Delete Company' }), tokens);
            // The danger-zone container takes the danger border from the theme utility.
            const zone = page.locator('div.border-danger').filter({ hasText: 'Delete Company' });
            expect(await style(zone, 'border-top-color')).toBe(tokens.danger);
            await expectControlContract(page, tokens);
        }

        // The confirmation step is still the browser's native confirm(): dismissing it deletes nothing.
        await visit(page, `${companyPath}/edit`, 'light', XL);
        let confirmation = '';
        page.once('dialog', async (dialog) => {
            confirmation = `${dialog.type()}: ${dialog.message()}`;
            await dialog.dismiss();
        });
        await inMain(page).getByRole('button', { name: 'Delete Company' }).click();
        expect(confirmation).toBe('confirm: Delete E2E WP1 Company? This cannot be undone.');
        await expect(page).toHaveURL(new RegExp(`${companyPath}/edit$`));
        expect((await page.request.get(companyPath)).status()).toBe(200);
    });

    test('a contact created from the form keeps its labels and a danger-only delete trigger', async ({ page }) => {
        test.setTimeout(120_000);

        const token = await csrf(page);
        const location = await createViaForm(page, '/crm/contacts', token, {
            first_name: 'E2E',
            last_name: 'WP1 Contact',
        });
        created.push(pathOf(location));

        const tokens = await visit(page, `${pathOf(location)}/edit`, 'dark', S);

        await expect(inMain(page).getByLabel('First Name')).toHaveValue('E2E');
        await expectDangerTrigger(inMain(page).getByRole('button', { name: 'Delete Contact' }), tokens);
        await expectControlContract(page, tokens);
    });
});

// ═══ Finance ══════════════════════════════════════════════════════════════════════

test.describe('Finance', () => {
    test('invoices: list and form, with the approved names and the line-item template', async ({ page }) => {
        test.setTimeout(180_000);

        for (const { theme, viewport } of MODES) {
            let tokens = await visit(page, '/billing/invoices', theme, viewport);
            await expectInk(inMain(page).getByRole('link', { name: 'New Invoice' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: 'New Invoice' }), viewport);
            await expect(inMain(page).getByRole('searchbox', { name: 'Search invoices or clients…' })).toBeVisible();
            await expect(inMain(page).getByRole('combobox', { name: 'Status', exact: true })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('button', { name: 'Filter', exact: true }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/billing/invoices/create', theme, viewport);
            await expectInk(inMain(page).getByRole('button', { name: 'Create Invoice' }), tokens);
            await expectSecondary(inMain(page).getByRole('link', { name: 'Cancel' }), tokens);

            // Approved Finance name change 1: the description's name is its visible label.
            await expect(inMain(page).getByRole('textbox', { name: 'Description', exact: true })).toHaveAttribute(
                'placeholder',
                'Service or product description',
            );
            await expect(inMain(page).getByRole('textbox', { name: 'Service or product description' })).toHaveCount(0);

            await expectControlContract(page, tokens);
        }
    });

    test('line items: added rows are labelled, identities stay unique, and an invoice can be created, paid-for and deleted', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        const tokens = await visit(page, '/billing/invoices/create', 'light', XL);

        // Add two rows, remove the middle one, add another: the index only ever moves forward.
        const add = inMain(page).getByRole('button', { name: '+ Add line item' });
        await add.click();
        await add.click();
        await expect(inMain(page).getByRole('button', { name: 'Remove line item' })).toHaveCount(3);
        await page.locator('#items-1-description').locator('xpath=ancestor::div[contains(@class, "line-item-row")]').getByRole('button', { name: 'Remove line item' }).click();
        await add.click();

        const ids = await page.evaluate(() =>
            [...document.querySelectorAll('#line-items [id]')].map((element) => element.id),
        );
        expect(new Set(ids).size, 'no duplicate id after add / remove / add').toBe(ids.length);
        expect(await page.locator('#line-items input[name^="items["]').evaluateAll((inputs) => inputs.map((input) => (input as HTMLInputElement).name))).toEqual([
            'items[0][description]', 'items[0][quantity]', 'items[0][unit_price]',
            'items[2][description]', 'items[2][quantity]', 'items[2][unit_price]',
            'items[3][description]', 'items[3][quantity]', 'items[3][unit_price]',
        ]);

        // Approved name change 2: an added row's unit price is named "Unit Price" (it was "0.00"), and
        // every added row is named by its sr-only labels.
        await expect(page.locator('#items-3-unit_price')).toHaveAttribute('placeholder', '0.00');
        await expect(inMain(page).getByLabel('Unit Price', { exact: true })).toHaveCount(3);
        await expect(inMain(page).getByRole('spinbutton', { name: '0.00' })).toHaveCount(0);
        await expect(inMain(page).getByLabel('Description', { exact: true })).toHaveCount(3);

        // Keyboard focus on an added row's remove control and fields.
        await page.locator('#items-3-description').focus();
        await page.keyboard.press('Tab');
        expect(await style(page.locator('#items-3-quantity'), 'outline-color')).toBe(tokens.focus);

        // Remove the extras, fill the first row, create the invoice and clean up.
        await inMain(page).getByRole('button', { name: 'Remove line item' }).nth(2).click();
        await inMain(page).getByRole('button', { name: 'Remove line item' }).nth(1).click();
        await inMain(page).getByLabel('Client').selectOption({ index: 1 });
        await page.locator('#items-0-description').fill('E2E WP1 line');
        await page.locator('#items-0-unit_price').fill('12.50');
        await inMain(page).getByRole('button', { name: 'Create Invoice' }).click();

        await expect(page).toHaveURL(/\/billing\/invoices\/\d+$/);
        const invoicePath = new URL(page.url()).pathname;
        created.push(invoicePath);

        for (const { theme, viewport } of MODES) {
            const showTokens = await visit(page, invoicePath, theme, viewport);

            await expectSecondary(inMain(page).getByRole('link', { name: 'Edit', exact: true }), showTokens);
            await expectInk(inMain(page).getByRole('button', { name: 'Mark as Sent' }), showTokens);
            await expectDangerTrigger(inMain(page).getByRole('button', { name: 'Delete', exact: true }), showTokens);
            await expectInk(inMain(page).getByRole('button', { name: 'Record Payment' }), showTokens);

            // Approved name change 3: the payment notes field is named "Notes" (it was "e.g. Bank transfer").
            await expect(inMain(page).getByRole('textbox', { name: 'Notes', exact: true })).toHaveAttribute(
                'placeholder',
                'e.g. Bank transfer',
            );
            await expect(inMain(page).getByRole('textbox', { name: 'e.g. Bank transfer' })).toHaveCount(0);
            await expect(inMain(page).getByLabel('Amount')).toBeVisible();

            await expectControlContract(page, showTokens);
        }
    });
});

// ═══ System ═══════════════════════════════════════════════════════════════════════

test.describe('System', () => {
    test('users: list and the invite modal', async ({ page }) => {
        test.setTimeout(180_000);

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, '/admin/users', theme, viewport);

            await expectInk(inMain(page).getByRole('button', { name: 'Invite User' }), tokens);
            await expect(inMain(page).getByRole('searchbox', { name: 'Search by name or email…' })).toBeVisible();
            await expectSecondary(inMain(page).getByRole('button', { name: 'Search', exact: true }), tokens);
            await expectControlContract(page, tokens);

            // The hand-built modal's behaviour is unchanged: it opens on click, and its field keeps its id.
            await inMain(page).getByRole('button', { name: 'Invite User' }).click();
            await expect(page.locator('#invite-modal')).toBeVisible();
            await expectInk(inMain(page).getByRole('button', { name: 'Send Invitation' }), tokens);
            const email = inMain(page).getByLabel('Email address');
            await expect(email).toHaveAttribute('id', 'invite-email');
            await email.focus();
            await page.keyboard.press('Shift+Tab');
            await page.keyboard.press('Tab');
            expect(await style(email, 'outline-style')).toBe('solid');
            expect(await style(email, 'outline-color')).toBe(tokens.focus);
        }
    });

    test('roles: list and the permission group', async ({ page }) => {
        test.setTimeout(180_000);

        for (const { theme, viewport } of MODES) {
            let tokens = await visit(page, '/admin/roles', theme, viewport);
            await expectInk(inMain(page).getByRole('link', { name: 'New Role' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: 'New Role' }), viewport);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/admin/roles/create', theme, viewport);
            await expect(inMain(page).getByLabel('Role name')).toBeVisible();
            const group = inMain(page).getByRole('group', { name: 'Permissions' });
            await expect(group).toBeVisible();

            const boxes = group.getByRole('checkbox');
            expect(await boxes.count()).toBeGreaterThan(20);
            // The selection accent, not the retired indigo and not the primary-action ink.
            expect(await style(boxes.first(), 'accent-color')).toBe(tokens.accent);
            expect(await style(boxes.first(), 'accent-color')).not.toBe(tokens.ink);

            await expectInk(inMain(page).getByRole('button', { name: 'Create Role' }), tokens);
            await expectControlContract(page, tokens);
        }
    });

    test('pages: list, create form, and a page with its destructive trigger', async ({ page }) => {
        test.setTimeout(180_000);

        const token = await csrf(page);
        const location = await createViaForm(page, '/operator/cms', token, { title: 'E2E WP1 Page' });
        const editPath = pathOf(location);
        created.push(editPath.replace(/\/edit$/, ''));

        for (const { theme, viewport } of MODES) {
            let tokens = await visit(page, '/operator/cms', theme, viewport);
            await expectInk(inMain(page).getByRole('link', { name: '+ New Page' }), tokens);
            await expectHeaderActionIntact(inMain(page).getByRole('link', { name: '+ New Page' }), viewport);
            await expectControlContract(page, tokens);

            tokens = await visit(page, '/operator/cms/create', theme, viewport);
            await expect(inMain(page).getByLabel('Title')).toBeVisible();
            await expect(inMain(page).getByLabel('Slug')).toBeVisible();
            await expect(inMain(page).getByLabel('Content (HTML)')).toBeVisible();
            await expectInk(inMain(page).getByRole('button', { name: 'Create Page' }), tokens);
            await expectControlContract(page, tokens);

            tokens = await visit(page, editPath, theme, viewport);
            await expectInk(inMain(page).getByRole('button', { name: 'Publish' }), tokens);
            await expectInk(inMain(page).getByRole('button', { name: 'Save', exact: true }), tokens);
            await expectDangerTrigger(inMain(page).getByRole('button', { name: 'Delete Page' }), tokens);
            await expectControlContract(page, tokens);
        }
    });
});

// ═══ Pagination ═══════════════════════════════════════════════════════════════════

test.describe('Pagination', () => {
    test('the Direction D view keeps numbered navigation, aria-current and the query string, in both themes', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        const token = await csrf(page);
        const prefix = `E2E-WP1-PG-${Date.now()}`;

        // 26 rows page at 25 per page; creating through the application's own form route keeps the data legitimate.
        for (let number = 1; number <= 26; number++) {
            const location = await createViaForm(page, '/crm/companies', token, {
                name: `${prefix} ${String(number).padStart(2, '0')}`,
            });
            created.push(pathOf(location));
        }

        const list = `/crm/companies?search=${encodeURIComponent(prefix)}`;

        for (const { theme, viewport } of MODES) {
            const tokens = await visit(page, list, theme, viewport);
            const nav = inMain(page).getByRole('navigation', { name: 'Pagination' });

            await expect(nav).toBeVisible();
            expect(await hasHorizontalOverflow(page)).toBe(false);

            if (viewport.width === XL.width) {
                // Wide: the numbered pages and the summary, with the current page drawn as the ink segment.
                const current = nav.locator('[aria-current="page"]');
                await expect(current).toHaveText('1');
                expect(await style(current, 'background-color')).toBe(tokens.ink);
                expect(await style(current, 'color')).toBe(tokens.onInk);
                await expect(nav.getByText(/Showing\s+1\s+to\s+25\s+of\s+26\s+results/)).toBeVisible();
                await expect(nav.getByRole('link', { name: 'Go to page 2' })).toBeVisible();

                const next = nav.getByRole('link', { name: 'Next' }).first();
                expect(await style(next, 'border-top-color')).toBe(tokens.controlEdge);

                // Keyboard focus on a pagination link is the 2px focus outline.
                await nav.getByRole('link', { name: 'Go to page 2' }).focus();
                await page.keyboard.press('Shift+Tab');
                await page.keyboard.press('Tab');
                const focused = inMain(page).getByRole('link', { name: 'Go to page 2' });
                expect(await style(focused, 'outline-style')).toBe('solid');
                expect(await style(focused, 'outline-width')).toBe('2px');
                expect(await style(focused, 'outline-color')).toBe(tokens.focus);

                await nav.getByRole('link', { name: 'Go to page 2' }).click();
                // The query string survives the page change.
                await expect(page).toHaveURL(/page=2/);
                expect(new URL(page.url()).searchParams.get('search')).toBe(prefix);
                await expect(inMain(page).getByRole('navigation', { name: 'Pagination' }).locator('[aria-current="page"]')).toHaveText('2');
                await expect(inMain(page).getByRole('navigation', { name: 'Pagination' }).getByText(/Showing\s+26\s+to\s+26\s+of\s+26\s+results/)).toBeVisible();
            } else {
                // Compact: only Next on the first page, and it is a real, focusable link.
                const next = nav.getByRole('link', { name: /Next/ }).first();
                await expect(next).toBeVisible();
                await expect(nav.getByRole('link', { name: /Previous/ })).toHaveCount(0);
                await expect(nav.getByRole('link', { name: 'Go to page 2' })).toBeHidden();
                await expectSecondary(next, tokens);
            }
        }
    });
});
