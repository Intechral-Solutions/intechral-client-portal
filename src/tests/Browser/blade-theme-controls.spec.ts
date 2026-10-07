import { execFileSync } from 'node:child_process';

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
 * EPIC-016 WP2 (Status and Semantic State) extends this spec at the foot ("Semantic state", below): the
 * status, priority, overdue, internal-note, tag, alert and live-tracker presentation in light and dark at
 * 1440 and 390, measured as computed colour and contrast against the REAL effective background.
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
    // EPIC-016 WP3 (the WP2 review hardening): a measurement must never silently run in the other theme.
    expect(await page.locator('html').getAttribute('data-theme'), `${path}: the ${theme} theme is applied`).toBe(theme);
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


// ═══ Semantic state (EPIC-016 WP2) ════════════════════════════════════════════════
//
// Status, priority, overdue, internal-note, tag, alert and live presentation, in light and dark at 1440 and
// 390. Every mark is measured as computed colour against the REAL effective background (the first opaque
// ancestors composited, resolved through a canvas so oklch / color-mix legacy surfaces are read as sRGB), so
// a legacy pastel pill, hex or indigo fails by value, and the 4.5:1 (text) and 3:1 (glyph) bars are the
// WCAG numbers on what a user sees. The claim is scoped to WP2's surfaces.
//
// Two kinds of evidence. REAL pages and records, where the application's supported routes can create the
// state and delete it again (the seeded ticket TKT-E2E1, a draft invoice, a custom role, CMS pages). And a
// GALLERY of every state rendered by the PRODUCTION partials (tests/Support/semantic_state_gallery.php) and
// injected into a real page, for the states no supported route can create without leaving records (tickets
// have no delete route and cannot be made overdue; only a draft invoice can be deleted; nothing cancels
// one). The gallery is not a re-typed copy of the markup: it is the same Blade the pages include.

type Rgb = [number, number, number];

/** sRGB luminance and WCAG contrast of two colours. */
const relativeLuminance = ([r, g, b]: Rgb) => {
    const [x, y, z] = [r, g, b].map((channel) => {
        const c = channel / 255;

        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * x + 0.7152 * y + 0.0722 * z;
};
const contrastOf = (a: Rgb, b: Rgb) => {
    const [hi, lo] = [relativeLuminance(a), relativeLuminance(b)].sort((m, n) => n - m);

    return (hi + 0.05) / (lo + 0.05);
};

/**
 * An element's colour (`color`, or `fill` for an SVG shape) and the background it really sits on, both as
 * sRGB. The background is the stack of translucent and opaque layers from the nearest opaque ancestor inward,
 * composited over white; every colour is parsed by a canvas so any CSS colour syntax resolves.
 */
async function pairOf(locator: Locator, property: 'color' | 'fill' = 'color') {
    return locator.evaluate((element, prop) => {
        const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
        const parse = (css: string): [number, number, number, number] => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = css;
            context.fillRect(0, 0, 1, 1);
            const data = context.getImageData(0, 0, 1, 1).data;

            return [data[0], data[1], data[2], data[3] / 255];
        };

        const layers: [number, number, number, number][] = [];
        for (let node: Element | null = element; node; node = node.parentElement) {
            const layer = parse(getComputedStyle(node).backgroundColor);
            if (layer[3] > 0) {
                layers.push(layer);
            }
            if (layer[3] === 1) {
                break;
            }
        }

        let background: [number, number, number] = [255, 255, 255];
        for (const layer of layers.reverse()) {
            background = background.map((channel, index) => layer[index] * layer[3] + channel * (1 - layer[3])) as [
                number,
                number,
                number,
            ];
        }

        const foreground = parse(getComputedStyle(element).getPropertyValue(prop));
        const composited = foreground
            .slice(0, 3)
            .map((channel, index) => channel * foreground[3] + background[index] * (1 - foreground[3])) as Rgb;

        return { foreground: composited, background, css: getComputedStyle(element).getPropertyValue(prop) };
    }, property);
}

/**
 * A bordered element's boundary colour against what is INSIDE it (its own surface) and what is OUTSIDE it
 * (the parent's effective background): the two sides a non-text boundary must stand out from (WCAG 1.4.11).
 */
async function boundaryPair(locator: Locator) {
    return locator.evaluate((element) => {
        const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
        const parse = (css: string): [number, number, number, number] => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = css;
            context.fillRect(0, 0, 1, 1);
            const data = context.getImageData(0, 0, 1, 1).data;

            return [data[0], data[1], data[2], data[3] / 255];
        };
        const effective = (start: Element | null): [number, number, number] => {
            const layers: [number, number, number, number][] = [];
            for (let node = start; node; node = node.parentElement) {
                const layer = parse(getComputedStyle(node).backgroundColor);
                if (layer[3] > 0) {
                    layers.push(layer);
                }
                if (layer[3] === 1) {
                    break;
                }
            }
            let background: [number, number, number] = [255, 255, 255];
            for (const layer of layers.reverse()) {
                background = background.map((c, i) => layer[i] * layer[3] + c * (1 - layer[3])) as [number, number, number];
            }

            return background;
        };

        const edge = parse(getComputedStyle(element).borderTopColor).slice(0, 3) as [number, number, number];

        return { edge, inside: effective(element), outside: effective(element.parentElement) };
    });
}

/** Resolved `--ds-*` token colours of the current theme, as the `rgb(...)` strings the browser reports. */
function readDs(page: Page, names: string[]): Promise<Record<string, string>> {
    return page.evaluate((list) => {
        const probe = document.createElement('i');
        document.body.appendChild(probe);
        const out: Record<string, string> = {};
        for (const name of list) {
            probe.style.color = `var(--ds-${name})`;
            out[name] = getComputedStyle(probe).color;
        }
        probe.remove();

        return out;
    }, names);
}

const DS = [
    'accent', 'text', 'text-muted', 'text-secondary', 'success', 'success-glyph', 'danger', 'warning', 'warning-glyph',
    'warning-soft', 'live', 'live-text', 'rule-control', 'surface', 'control-edge', 'accent-soft', 'danger-soft',
];

/** The label and glyph tokens of a status tone (status.tsx: text-safe label token, shape-only glyph token). */
const TONE_TOKENS: Record<string, { label: string; glyph: string }> = {
    neutral: { label: 'text-muted', glyph: 'text-muted' },
    info: { label: 'accent', glyph: 'accent' },
    success: { label: 'success', glyph: 'success-glyph' },
    warning: { label: 'warning', glyph: 'warning-glyph' },
    danger: { label: 'danger', glyph: 'danger' },
    live: { label: 'live-text', glyph: 'live' },
};

/** The hard-coded colours the retired pills used (EPIC-016 §3.5, §9). None may appear at a migrated site. */
const RETIRED_PILL_COLOURS = [
    'rgb(37, 99, 235)', 'rgb(124, 58, 237)', 'rgb(22, 163, 74)', 'rgb(220, 38, 38)', 'rgb(234, 88, 12)',
    'rgb(202, 138, 4)', 'rgb(146, 64, 14)', 'rgb(217, 119, 6)',
];

/**
 * The hue of a saturated colour, in degrees, or null for a neutral. The retired status presentation was
 * saturated blue/indigo/violet (hue 220-290); Direction D's own muted greys carry a faint blue cast (hue
 * about 222, saturation about 5%), which is neutral, not a status colour.
 */
function hueOf([r, g, b]: Rgb) {
    const [x, y, z] = [r / 255, g / 255, b / 255];
    const max = Math.max(x, y, z);
    const min = Math.min(x, y, z);
    const lightness = (max + min) / 2;
    const delta = max - min;
    const saturation = delta === 0 ? 0 : delta / (1 - Math.abs(2 * lightness - 1));
    if (saturation < 0.25) {
        return null;
    }
    const hue = max === x ? ((y - z) / delta) % 6 : max === y ? (z - x) / delta + 2 : (x - y) / delta + 4;

    return (hue * 60 + 360) % 360;
}

/** A mark's label text and glyph are visible, its colours are the tone's tokens, and both clear their contrast bar. */
async function expectMark(
    mark: Locator,
    label: string,
    tone: keyof typeof TONE_TOKENS,
    glyph: string,
    ds: Record<string, string>,
    where: string,
) {
    await expect(mark, `${where}: mark is visible`).toBeVisible();
    await expect(mark.locator(':scope > span'), `${where}: visible text label`).toHaveText(label);

    const svg = mark.locator(':scope > svg');
    await expect(svg, `${where}: glyph is present`).toHaveAttribute('data-glyph', glyph);
    await expect(svg).toBeVisible();

    const text = await pairOf(mark.locator(':scope > span'));
    const shape = await pairOf(svg);
    const tokens = TONE_TOKENS[tone];

    expect(text.css, `${where}: label is the ${tone} text token`).toBe(ds[tokens.label]);
    expect(shape.css, `${where}: glyph is the ${tone} glyph token`).toBe(ds[tokens.glyph]);
    expect(contrastOf(text.foreground, text.background), `${where}: label contrast`).toBeGreaterThanOrEqual(4.5);
    expect(contrastOf(shape.foreground, shape.background), `${where}: glyph contrast`).toBeGreaterThanOrEqual(3);

    // No legacy pill: the retired hex colours and the blue/indigo hues never appear on a migrated mark.
    expect(RETIRED_PILL_COLOURS, `${where}: no retired pill colour`).not.toContain(text.css);
    const hue = hueOf(text.foreground);
    expect(hue === null || hue < 200 || hue > 300, `${where}: no blue or indigo hue (${hue})`).toBe(true);
    // A mark is inline glyph + label, never a filled pill.
    expect(await style(mark, 'background-color'), `${where}: no pill fill`).toBe('rgba(0, 0, 0, 0)');
}

let galleryCache: string | null = null;

/** The production partials for every state, rendered by PHP (read-only; no database). */
function galleryHtml(): string {
    galleryCache ??= (
        JSON.parse(
            execFileSync('php', ['tests/Support/semantic_state_gallery.php'], { cwd: process.cwd(), encoding: 'utf8' }),
        ) as { html: string }
    ).html;

    return galleryCache;
}

/**
 * Inject the gallery into the real page's own card (the queue's table card, `bg-surface`), so the
 * effective background every mark is measured against is the one a user really sees there, not the canvas.
 * Fails loudly if the page has no card to host it.
 */
async function injectGallery(page: Page) {
    const hosted = await page.evaluate((html) => {
        const host = document.querySelector('#main-content .rounded-lg.border.bg-surface');
        if (!host) {
            return false;
        }
        const wrapper = document.createElement('div');
        wrapper.id = 'wp2-gallery';
        wrapper.innerHTML = html;
        host.appendChild(wrapper);

        return true;
    }, galleryHtml());
    expect(hosted, 'the page has a card to host the state gallery').toBe(true);

    return page.locator('#wp2-gallery');
}

test.describe('Semantic state: every state, rendered by the production partials', () => {
    test('status, priority, overdue, internal note, tags, alerts and the live tracker, in both themes at both widths', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            const tokens = await visit(page, '/operator/tickets', theme, viewport);
            const gallery = await injectGallery(page);
            const ds = await readDs(page, DS);
            const at = (name: string) => gallery.locator(`[data-case="${name}"]`);

            // Ticket lifecycle (§9.1): label, tone, glyph. Pending uses the recorded P6 substitute.
            const tickets: [string, string, string, string][] = [
                ['open', 'Open', 'info', 'circle'],
                ['in_progress', 'In Progress', 'info', 'half'],
                ['pending_user', 'Pending', 'neutral', 'dashed'],
                ['resolved', 'Resolved', 'success', 'check'],
                ['closed', 'Closed', 'neutral', 'check'],
            ];
            for (const [status, label, tone, glyph] of tickets) {
                await expectMark(at(`ticket-status:${status}`).locator('> span'), label, tone, glyph, ds, `${where} ticket ${status}`);
            }

            // Invoice lifecycle (§9.3): Sent and Overdue use the recorded P11 substitutes.
            const invoices: [string, string, string, string][] = [
                ['draft', 'Draft', 'neutral', 'dashed'],
                ['sent', 'Sent', 'info', 'half'],
                ['paid', 'Paid', 'success', 'check'],
                ['overdue', 'Overdue', 'danger', 'square'],
                ['cancelled', 'Cancelled', 'neutral', 'circle'],
            ];
            for (const [status, label, tone, glyph] of invoices) {
                await expectMark(at(`invoice-status:${status}`).locator('> span'), label, tone, glyph, ds, `${where} invoice ${status}`);
            }

            // CMS state (§9.6) and MFA Enabled / Disabled (§9.7; Disabled by owner ruling A2.12 #7).
            await expectMark(at('cms:published').locator('> span'), 'Published', 'success', 'check', ds, `${where} cms published`);
            await expectMark(at('cms:draft').locator('> span'), 'Draft', 'neutral', 'dashed', ds, `${where} cms draft`);
            await expectMark(at('mfa:enabled').locator('> span'), 'Enabled', 'success', 'check', ds, `${where} mfa`);
            await expectMark(at('mfa:disabled').locator('> span'), 'Disabled', 'neutral', 'dashed', ds, `${where} mfa disabled`);

            // Priority (§9.2): bars + label. Only critical is danger; unfilled bars sit on rule-control.
            const priorities: [string, string, number][] = [['low', 'Low', 1], ['medium', 'Medium', 2], ['high', 'High', 3], ['critical', 'Critical', 3]];
            for (const [priority, label, bars] of priorities) {
                const mark = at(`ticket-priority:${priority}`).locator('> span');
                const expected = priority === 'critical' ? 'danger' : 'text-secondary';

                await expect(mark.locator(':scope > span'), `${where} ${priority} label`).toHaveText(label);
                await expect(mark.locator('svg rect[data-bar="filled"]')).toHaveCount(bars);
                await expect(mark.locator('svg rect[data-bar="empty"]')).toHaveCount(3 - bars);

                const text = await pairOf(mark.locator(':scope > span'));
                const filled = await pairOf(mark.locator('svg rect[data-bar="filled"]').first(), 'fill');
                expect(text.css, `${where} ${priority} label colour`).toBe(ds[expected]);
                expect(filled.css, `${where} ${priority} bar colour follows the label`).toBe(ds[expected]);
                expect(contrastOf(text.foreground, text.background), `${where} ${priority} label contrast`).toBeGreaterThanOrEqual(4.5);
                expect(contrastOf(filled.foreground, filled.background), `${where} ${priority} bar contrast`).toBeGreaterThanOrEqual(3);
                expect(RETIRED_PILL_COLOURS).not.toContain(text.css);
                expect(await style(mark, 'background-color'), `${where} ${priority}: no pastel pill`).toBe('rgba(0, 0, 0, 0)');

                if (bars < 3) {
                    const empty = await style(mark.locator('svg rect[data-bar="empty"]').first(), 'fill');
                    expect(empty, `${where} ${priority} unfilled bars use rule-control`).toBe(ds['rule-control']);
                }
            }

            // Overdue SLA cell (§9.4): due date in danger + the Overdue status, legible on the real dark card.
            const sla = at('overdue-sla');
            const due = await pairOf(sla);
            expect(due.css).toBe(ds['danger']);
            expect(await style(sla, 'font-weight')).toBe('500');
            expect(contrastOf(due.foreground, due.background), `${where} overdue date contrast`).toBeGreaterThanOrEqual(4.5);
            await expectMark(sla.locator('> span'), 'Overdue', 'danger', 'square', ds, `${where} overdue status`);

            // Internal note (§9.5): dashed warning boundary, warning-soft surface, lock glyph, "Internal Note".
            const note = at('internal-note');
            expect(await style(note, 'border-top-style'), `${where} note boundary is dashed`).toBe('dashed');
            expect(await style(note, 'border-top-color'), `${where} note boundary is warning-glyph`).toBe(ds['warning-glyph']);
            expect(await style(note, 'background-color'), `${where} note surface is warning-soft`).toBe(ds['warning-soft']);
            const marker = note.locator('[data-internal-note]');
            await expect(marker.locator('> span')).toHaveText('Internal Note');
            await expect(marker.locator('> svg')).toBeVisible();
            const markerText = await pairOf(marker.locator('> span'));
            expect(markerText.css).toBe(ds['warning']);
            expect(contrastOf(markerText.foreground, markerText.background), `${where} note label contrast`).toBeGreaterThanOrEqual(4.5);
            const body = await pairOf(note.locator('> div').last());
            expect(contrastOf(body.foreground, body.background), `${where} note body contrast`).toBeGreaterThanOrEqual(4.5);
            // The dashed boundary is a non-text mark: 3:1 against the card inside it and the page behind it.
            const { edge, inside, outside } = await boundaryPair(note);
            expect(contrastOf(edge, inside), `${where} note boundary vs the card`).toBeGreaterThanOrEqual(3);
            expect(contrastOf(edge, outside), `${where} note boundary vs the page`).toBeGreaterThanOrEqual(3);

            // Tags (§9.7): mono uppercase kind markers on the rule-control edge; never accent, never a status.
            const tags = at('tags').locator('> span');
            await expect(tags).toHaveCount(4);
            for (const tag of await tags.all()) {
                const text = await pairOf(tag);
                const name = (await tag.textContent()) ?? '';

                expect(text.css, `${where} tag ${name} colour`).toBe(ds['text-muted']);
                expect(await style(tag, 'border-top-color'), `${where} tag ${name} edge`).toBe(ds['rule-control']);
                expect(await style(tag, 'text-transform')).toBe('uppercase');
                expect(await style(tag, 'font-family')).toMatch(/mono/i);
                expect(await style(tag, 'background-color')).toBe('rgba(0, 0, 0, 0)');
                expect(contrastOf(text.foreground, text.background), `${where} tag ${name} contrast`).toBeGreaterThanOrEqual(4.5);
            }

            // Alerts (§9.7): the right role per meaning, a glyph, an sr-only kind, and legible text on the tint.
            const roles: Record<string, string | null> = { neutral: null, info: 'status', success: 'status', warning: 'status', danger: 'alert' };
            const kinds: Record<string, string> = { info: 'Notice', success: 'Success', warning: 'Warning', danger: 'Error' };
            const glyphToken: Record<string, string> = { info: 'accent', success: 'success', warning: 'warning', danger: 'danger' };
            for (const [variant, role] of Object.entries(roles)) {
                const alert = at(`alert:${variant}`);

                await expect(alert, `${where} alert ${variant} is visible`).toBeVisible();
                if (role === null) {
                    await expect(alert).not.toHaveAttribute('role', /.+/);
                } else {
                    await expect(alert).toHaveAttribute('role', role);
                }

                const text = await pairOf(variant === 'neutral' ? alert : alert.locator('[data-alert-body]'));
                expect(text.css, `${where} alert ${variant} text is ink`).toBe(ds['text']);
                expect(contrastOf(text.foreground, text.background), `${where} alert ${variant} text contrast`).toBeGreaterThanOrEqual(4.5);

                if (variant !== 'neutral') {
                    await expect(alert.locator('> svg')).toBeVisible();
                    const glyph = await pairOf(alert.locator('> svg'));
                    expect(glyph.css, `${where} alert ${variant} glyph colour`).toBe(ds[glyphToken[variant]]);
                    expect(contrastOf(glyph.foreground, glyph.background), `${where} alert ${variant} glyph contrast`).toBeGreaterThanOrEqual(3);
                    // The kind is announced to assistive technology, and not drawn.
                    expect(await alert.textContent()).toContain(`${kinds[variant]}:`);
                    expect((await alert.locator('.sr-only').boundingBox())?.width ?? 0).toBeLessThanOrEqual(1);
                }
            }

            // The tracker's RUNNING state (§9.9): a live dot and label, and Stop as a NON-destructive secondary control.
            const tracker = at('tracker-running');
            await expectMark(tracker.locator('[data-time-tracker-running]'), 'Timer running', 'live', 'dot', ds, `${where} tracker running`);
            const stop = tracker.getByRole('button', { name: 'Stop', exact: true });
            await expectSecondary(stop, tokens);
            expect(await style(stop, 'color'), `${where} Stop is not danger`).not.toBe(tokens.danger);
            expect(await style(stop, 'border-top-color'), `${where} Stop edge is not danger`).not.toBe(tokens.danger);

            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});

// ── Real pages and records ──────────────────────────────────────────────────────────

test.describe('Semantic state on real Helpdesk pages (operator)', () => {
    test('the queue and a ticket page draw the seeded ticket with the shared marks and no red or pastel treatment', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        let ticketPath = '';

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            await visit(page, '/operator/tickets', theme, viewport);
            const ds = await readDs(page, DS);

            const row = inMain(page).getByRole('row').filter({ hasText: 'TKT-E2E1' });
            await expectMark(row.locator('[data-ticket-status]'), 'Open', 'info', 'circle', ds, `${where} queue status`);

            const priority = row.locator('[data-ticket-priority]');
            await expect(priority.locator(':scope > span')).toHaveText('Low');
            await expect(priority.locator('svg rect[data-bar="filled"]')).toHaveCount(1);
            expect((await pairOf(priority.locator(':scope > span'))).css).toBe(ds['text-secondary']);

            // No legacy red row, in either theme: no row of the queue is drawn on a light tint.
            for (const queueRow of await inMain(page).locator('tbody tr').all()) {
                const { background } = await pairOf(queueRow);
                if (theme === 'dark') {
                    expect(relativeLuminance(background), `${where} queue row stays dark in the dark theme`).toBeLessThan(0.2);
                }
            }

            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);

            ticketPath ||= new URL((await row.getByRole('link', { name: 'View' }).getAttribute('href')) ?? '', 'http://localhost').pathname;
        }

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            await visit(page, ticketPath, theme, viewport);
            const ds = await readDs(page, DS);

            const header = inMain(page).locator('[data-ticket-status]').first();
            await expectMark(header, 'Open', 'info', 'circle', ds, `${where} ticket detail status`);
            await expect(inMain(page).locator('[data-ticket-priority] > span').first()).toHaveText('Low');
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});

// The seeded ticket TKT-E2E1 belongs to the operator persona, so its own request page (`/tickets/{id}`, the
// only host of the embedded tracker) is reached as the operator, exactly as time-migration.spec.ts does.
test.describe('Semantic state on the operator\'s own request page: the embedded time tracker', () => {
    test('the tracker is server-rendered with data hooks; the running state it clones is live and Stop is not destructive', async ({
        page,
    }) => {
        test.setTimeout(180_000);

        // The request page's path, found once through the list as a user reaches it. Each mode then loads that
        // path directly: following the link would reload the document and drop the theme `visit` applied.
        await visit(page, '/tickets', 'light', XL);
        const href = await inMain(page).getByRole('row').filter({ hasText: 'TKT-E2E1' }).getByRole('link', { name: 'View' }).getAttribute('href');
        const requestPath = new URL(href ?? '', 'http://localhost').pathname;
        expect(requestPath).toMatch(/^\/tickets\/\d+$/);

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            const tokens = await visit(page, requestPath, theme, viewport);
            expect(await page.locator('html').getAttribute('data-theme'), `${where}: the theme is applied`).toBe(theme);
            const ds = await readDs(page, DS);

            // The request page shows the same shared marks.
            await expectMark(inMain(page).locator('[data-ticket-status]').first(), 'Open', 'info', 'circle', ds, `${where} request page status`);

            // The tracker's own markup: one control row, one server-rendered template, no script-built markup.
            const controls = inMain(page).locator('[data-time-tracker-controls]');
            await expect(controls).toHaveCount(1);
            await expect(inMain(page).locator('template[data-time-tracker-running-template]')).toHaveCount(1);

            // Stopped state (when no other spec has left a timer running on this ticket): `Start Timer` is a
            // secondary small button under its stable accessible name, which time-migration.spec.ts depends on.
            const start = controls.getByRole('button', { name: /Start Timer/ });
            if ((await start.count()) > 0) {
                await expectSecondary(start, tokens);
                expect(await style(start, 'height')).toBe('32px'); // size sm (the browser's pointer is fine, so no touch step)
            }

            // The running state, cloned from the template exactly as the script does it but without starting a
            // timer (a started timer would race time-migration.spec.ts, the single owner of timers).
            await page.evaluate(() => {
                const template = document.querySelector('[data-time-tracker-running-template]') as HTMLTemplateElement;
                const probe = document.createElement('div');
                probe.id = 'wp2-tracker-probe';
                probe.className = 'flex items-center gap-2';
                probe.appendChild(template.content.cloneNode(true));
                document.querySelector('[data-time-tracker-controls]')!.after(probe);
            });
            const probe = page.locator('#wp2-tracker-probe');
            await expectMark(probe.locator('[data-time-tracker-running]'), 'Timer running', 'live', 'dot', ds, `${where} tracker running`);

            const stop = probe.getByRole('button', { name: 'Stop', exact: true });
            await expectSecondary(stop, tokens);
            expect(await style(stop, 'color'), `${where} Stop text is not danger`).not.toBe(tokens.danger);
            expect(await style(stop, 'border-top-color'), `${where} Stop edge is not danger`).not.toBe(tokens.danger);
            expect(await style(stop, 'height')).toBe('32px');

            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});

test.describe('Semantic state on real Finance pages', () => {
    test('a draft invoice is Draft (neutral, dashed) on the list and on its page', async ({ page }) => {
        test.setTimeout(240_000);

        await visit(page, '/billing/invoices/create', 'light', XL);
        await inMain(page).getByLabel('Client').selectOption({ index: 1 });
        await page.locator('#items-0-description').fill('E2E WP2 line');
        await page.locator('#items-0-unit_price').fill('12.50');
        await inMain(page).getByRole('button', { name: 'Create Invoice' }).click();
        await expect(page).toHaveURL(/\/billing\/invoices\/\d+$/);
        const invoicePath = new URL(page.url()).pathname;
        created.push(invoicePath);

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;

            await visit(page, '/billing/invoices', theme, viewport);
            let ds = await readDs(page, DS);
            const row = inMain(page).getByRole('row').filter({ has: page.locator(`a[href$="${invoicePath}"]`) });
            await expectMark(row.locator('[data-invoice-status]'), 'Draft', 'neutral', 'dashed', ds, `${where} invoice list`);
            expect(await style(row.locator('td').nth(4), 'text-decoration-line'), `${where}: a draft amount is not struck through`).toBe('none');
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);

            await visit(page, invoicePath, theme, viewport);
            ds = await readDs(page, DS);
            await expectMark(inMain(page).locator('[data-invoice-status]'), 'Draft', 'neutral', 'dashed', ds, `${where} invoice page`);
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});

test.describe('Semantic state on real System pages', () => {
    test('role types are tags, and a created role flashes a polite status alert; a failed one an assertive alert', async ({
        page,
    }) => {
        test.setTimeout(240_000);

        const roleName = `e2e-wp2-role-${Date.now().toString(36)}`;
        const token = await csrf(page);

        // Create a custom role through the application's own route. The flash it writes is rendered by the
        // next /admin/roles request, which the first mode below makes: that is the REAL success alert.
        const response = await page.request.post('/admin/roles', {
            form: { _token: token, name: roleName, 'permissions[]': 'tickets.view' },
            headers: { 'X-CSRF-TOKEN': token },
            maxRedirects: 0,
        });
        expect(response.status(), 'POST /admin/roles').toBe(302);

        let first = true;
        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            await visit(page, '/admin/roles', theme, viewport);
            const ds = await readDs(page, DS);

            if (first) {
                const flash = inMain(page).locator('[data-variant="success"]');
                await expect(flash, 'the created-role flash is a success alert').toBeVisible();
                await expect(flash).toHaveAttribute('role', 'status');
                await expect(flash.locator('[data-alert-body]')).not.toHaveText('');
                expect((await pairOf(flash.locator('[data-alert-body]'))).css).toBe(ds['text']);
                expect((await pairOf(flash.locator('> svg'))).css).toBe(ds['success']);

                const edit = inMain(page).getByRole('row').filter({ hasText: roleName }).getByRole('link', { name: 'Edit' });
                created.push(new URL((await edit.getAttribute('href')) ?? '', 'http://localhost').pathname.replace(/\/edit$/, ''));
                first = false;
            }

            const custom = inMain(page).getByRole('row').filter({ hasText: roleName }).locator('.rounded-tag');
            await expect(custom).toHaveText('Custom');

            for (const tag of [custom, inMain(page).locator('.rounded-tag', { hasText: 'Built-in' }).first()]) {
                const text = await pairOf(tag);
                expect(text.css, `${where}: a role type is muted mono text, not accent`).toBe(ds['text-muted']);
                expect(await style(tag, 'border-top-color')).toBe(ds['rule-control']);
                expect(contrastOf(text.foreground, text.background), `${where}: tag contrast`).toBeGreaterThanOrEqual(4.5);
            }
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }

        // A failed submission is a real validation error: the banner is an assertive danger alert.
        const failed = await page.request.post('/admin/roles', {
            form: { _token: token, name: '' },
            headers: { 'X-CSRF-TOKEN': token, Referer: new URL('/admin/roles', page.url()).toString() },
            maxRedirects: 0,
        });
        expect(failed.status()).toBe(302);

        for (const { theme, viewport } of MODES) {
            await visit(page, '/admin/roles', theme, viewport);
            const ds = await readDs(page, DS);
            const banner = inMain(page).locator('[data-variant="danger"]');

            if (theme === 'light' && viewport.width === XL.width) {
                await expect(banner, 'the validation banner is a danger alert').toBeVisible();
                await expect(banner).toHaveAttribute('role', 'alert');
                expect((await pairOf(banner.locator('> svg'))).css).toBe(ds['danger']);
                expect((await pairOf(banner.locator('[data-alert-body]'))).css).toBe(ds['text']);
            }
        }
    });

    test('a page is Draft, then Published, with the shared state mark in both themes', async ({ page }) => {
        test.setTimeout(240_000);

        const token = await csrf(page);
        const location = await createViaForm(page, '/operator/cms', token, { title: 'E2E WP2 Page' });
        const editPath = pathOf(location);
        created.push(editPath.replace(/\/edit$/, ''));

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            await visit(page, '/operator/cms', theme, viewport);
            const ds = await readDs(page, DS);
            const row = inMain(page).getByRole('row').filter({ hasText: 'E2E WP2 Page' });

            await expectMark(row.locator('[data-page-state]'), 'Draft', 'neutral', 'dashed', ds, `${where} list`);

            await visit(page, editPath, theme, viewport);
            await expectMark(inMain(page).locator('[data-page-state]'), 'Draft', 'neutral', 'dashed', ds, `${where} edit`);
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }

        await visit(page, editPath, 'light', XL);
        await inMain(page).getByRole('button', { name: 'Publish' }).click();
        await expect(inMain(page).locator('[data-page-state="published"]')).toBeVisible();

        for (const { theme, viewport } of MODES) {
            const where = `${theme} ${viewport.width}`;
            await visit(page, '/operator/cms', theme, viewport);
            const ds = await readDs(page, DS);
            const row = inMain(page).getByRole('row').filter({ hasText: 'E2E WP2 Page' });

            await expectMark(row.locator('[data-page-state]'), 'Published', 'success', 'check', ds, `${where} list`);
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});


// ═══ Theme normalization (EPIC-016 WP3) ═══════════════════════════════════════════
//
// §18.4 "palette conformance (from PR 3)": every computed text, background and border colour in `main` equals
// one of the CURRENT theme's Direction D token values, resolved from the live `--ds-*` properties, at rest.
// It catches a legacy Tailwind gray, an indigo, a hex or a raw legacy variable by VALUE, whatever syntax
// produced it. Alpha is ignored (an alert edge such as `border-danger/50` is the danger token at 50%); a fully
// transparent colour paints nothing and is skipped. Colour that a page inherits is checked where it is
// painted: a text colour is read on elements that own a text node.
//
// The routes cover the four target workspaces and `errors/403`, in light and dark, at 1440 and 390, along with
// the document-level overflow check. Records the spec needs are created through the application's own routes
// and removed in `afterEach`, exactly as the WP1 tests do. The claim is scoped to the target pages; it is not a
// product-wide dark-mode claim.

const DIRECTION_D_COLOURS = [
    'canvas', 'rail', 'drawer', 'surface', 'surface-sunken', 'surface-hover', 'surface-selected', 'rule', 'rule-control',
    'control-edge', 'rule-strong', 'text', 'text-secondary', 'text-muted', 'text-faint', 'accent', 'accent-hover',
    'accent-soft', 'accent-line', 'live', 'live-soft', 'live-text', 'ink', 'on-ink', 'danger', 'danger-soft', 'warning',
    'warning-glyph', 'warning-soft', 'success', 'success-glyph', 'progress-fill', 'progress-track', 'stage-future',
    'focus', 'scrim',
];

/** Colours in `main` that are not a Direction D token of the current theme (none is expected). */
async function paletteOffenders(page: Page) {
    return page.evaluate((names) => {
        const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true })!;
        const rgbOf = (css: string): [number, number, number, number] => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = css;
            context.fillRect(0, 0, 1, 1);
            const data = context.getImageData(0, 0, 1, 1).data;

            return [data[0], data[1], data[2], data[3] / 255];
        };

        const probe = document.createElement('i');
        document.body.appendChild(probe);
        const tokens = new Set<string>();
        for (const name of names) {
            probe.style.color = `var(--ds-${name})`;
            tokens.add(rgbOf(getComputedStyle(probe).color).slice(0, 3).join(','));
        }
        probe.remove();

        const offenders: string[] = [];
        const check = (element: Element, what: string, css: string) => {
            const [r, g, b, a] = rgbOf(css);
            if (a === 0 || tokens.has(`${r},${g},${b}`)) {
                return;
            }
            const label = `${element.tagName.toLowerCase()}.${String(element.getAttribute('class') ?? '').split(/\s+/).slice(0, 4).join('.')}`;
            offenders.push(`${what} ${css} on ${label}`);
        };

        for (const element of document.querySelectorAll('#main-content, #main-content *')) {
            const computed = getComputedStyle(element);
            if (computed.display === 'none' || computed.visibility === 'hidden') {
                continue;
            }
            const ownText = [...element.childNodes].some((node) => node.nodeType === 3 && (node.textContent ?? '').trim() !== '');
            if (ownText) {
                check(element, 'text', computed.color);
            }
            check(element, 'background', computed.backgroundColor);
            for (const side of ['Top', 'Right', 'Bottom', 'Left'] as const) {
                const width = parseFloat(computed.getPropertyValue(`border-${side.toLowerCase()}-width`));
                const borderStyle = computed.getPropertyValue(`border-${side.toLowerCase()}-style`);
                if (width > 0 && borderStyle !== 'none') {
                    check(element, `border-${side.toLowerCase()}`, computed.getPropertyValue(`border-${side.toLowerCase()}-color`));
                }
            }
        }

        return [...new Set(offenders)];
    }, DIRECTION_D_COLOURS);
}

/** Open `path` in every mode and require the palette, the theme and the overflow check to hold. */
async function expectNormalized(page: Page, path: string, label: string) {
    for (const { theme, viewport } of MODES) {
        const where = `${label} ${theme} ${viewport.width}`;

        await visit(page, path, theme, viewport);
        expect(await page.locator('html').getAttribute('data-theme'), `${where}: the theme is applied`).toBe(theme);
        // Polled: the theme switch starts a 120ms colour transition, and a loaded machine can be slow to settle it.
        // A real offender is still there at the end of the window, so this cannot hide one.
        await expect
            .poll(() => paletteOffenders(page), { message: `${where}: every colour in main is a Direction D token`, timeout: 6000 })
            .toEqual([]);
        expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
    }
}

test.describe('Theme normalization: Helpdesk, Finance, System as an operator', () => {
    test('queue, ticket, reports, invoices, users, roles and pages draw only Direction D colours', async ({ page }) => {
        test.setTimeout(420_000);

        await visit(page, '/operator/tickets', 'light', XL);
        const href = await inMain(page).getByRole('row').filter({ hasText: 'TKT-E2E1' }).getByRole('link', { name: 'View' }).getAttribute('href');
        const ticketPath = new URL(href ?? '', 'http://localhost').pathname;

        // The seeded ticket also has a request page (`/tickets/{id}`, which hosts the embedded time tracker); the
        // operator owns it, so it is reached through their own list, the real production route (independent review Y2A).
        await visit(page, '/tickets', 'light', XL);
        const requestHref = await inMain(page).getByRole('row').filter({ hasText: 'TKT-E2E1' }).getByRole('link', { name: 'View' }).getAttribute('href');
        const requestPath = new URL(requestHref ?? '', 'http://localhost').pathname;
        expect(requestPath).toMatch(/^\/tickets\/\d+$/);

        for (const [label, path] of [
            ['queue', '/operator/tickets'],
            ['ticket', ticketPath],
            ['request page', requestPath],
            ['reports', '/operator/tickets/reports'],
            ['invoices', '/billing/invoices'],
            ['invoice form', '/billing/invoices/create'],
            ['users', '/admin/users'],
            ['roles', '/admin/roles'],
            ['role form', '/admin/roles/create'],
            ['pages', '/operator/cms'],
            ['page form', '/operator/cms/create'],
        ] as const) {
            await expectNormalized(page, path, label);
        }
    });

    test('the Directory and a created invoice draw only Direction D colours', async ({ page }) => {
        test.setTimeout(420_000);

        const token = await csrf(page);
        const company = pathOf(await createViaForm(page, '/crm/companies', token, { name: 'E2E WP3 Company' }));
        const contact = pathOf(await createViaForm(page, '/crm/contacts', token, { first_name: 'E2E', last_name: 'WP3 Contact' }));
        created.push(company, contact);

        await visit(page, '/billing/invoices/create', 'light', XL);
        await inMain(page).getByLabel('Client').selectOption({ index: 1 });
        await page.locator('#items-0-description').fill('E2E WP3 line');
        await page.locator('#items-0-unit_price').fill('20.00');
        await inMain(page).getByRole('button', { name: 'Create Invoice' }).click();
        await expect(page).toHaveURL(/\/billing\/invoices\/\d+$/);
        const invoice = new URL(page.url()).pathname;
        created.push(invoice);

        for (const [label, path] of [
            ['contacts', '/crm/contacts'],
            ['contact', contact],
            ['contact form', `${contact}/edit`],
            ['companies', '/crm/companies'],
            ['company', company],
            ['company form', `${company}/edit`],
            ['organizations', '/organizations'],
            ['invoice', invoice],
            ['invoice edit', `${invoice}/edit`],
        ] as const) {
            await expectNormalized(page, path, label);
        }
    });
});

test.describe('Theme normalization: Helpdesk and Finance as a member, and errors/403', () => {
    test.use({ persona: 'member' });

    test('my requests, the new-ticket form, my invoices and the 403 page draw only Direction D colours', async ({ page }) => {
        test.setTimeout(300_000);

        for (const [label, path] of [
            ['my requests', '/tickets'],
            ['new ticket', '/tickets/create'],
            ['my invoices', '/my/invoices'],
        ] as const) {
            await expectNormalized(page, path, label);
        }

        // errors/403: the member requests /admin/users and receives HTTP 403 inside the Blade shell.
        for (const { theme, viewport } of MODES) {
            const where = `403 ${theme} ${viewport.width}`;

            await page.setViewportSize(viewport);
            await signedIn(page);
            await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
            const response = await page.goto('/admin/users');
            expect(response?.status(), `${where}: the member is forbidden`).toBe(403);
            await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme);
            await page.waitForTimeout(400);

            await expect(page.getByRole('heading', { name: 'Access Denied' })).toBeVisible();
            await expect
                .poll(() => paletteOffenders(page), { message: `${where}: every colour in main is a Direction D token`, timeout: 6000 })
                .toEqual([]);
            expect(await hasHorizontalOverflow(page), `${where}: no document-level horizontal overflow`).toBe(false);
        }
    });
});
