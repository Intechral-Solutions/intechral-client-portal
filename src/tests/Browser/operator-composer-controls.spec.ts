import { randomBytes } from 'node:crypto';

import type { Locator, Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import { applyTheme } from './support/theme';

/**
 * Release 1.0 (HD-C1) — the operator Helpdesk composer in real Chromium: the Visible/Hidden toggle and the inline due-date control.
 *
 * jsdom has no native picker, no layout and no focus ring, so what only a browser can show is held here: pointer and keyboard use
 * of the toggle and its tooltip, the editor swapping in place, Enter / Escape / blur, the native picker not collapsing the editor,
 * the pending state, urgency colours (computed values against the theme tokens, in both themes), the live clock, and the layout at
 * phone width. The arithmetic and the inference of the server fields are unit-tested in
 * `resources/js/shell/blade-ticket-composer.test.ts`; the server contract in `OperatorComposerControlsTest.php` and
 * `TicketDueDateOverrideTest.php`; the full post flow in `operator-due-date.spec.ts`.
 *
 * **Fixtures.** Every ticket is made through the real customer form with a literal `E2E-` prefix and a random token, and is
 * addressed only by the number captured from the redirect. Cleanup is the suite-wide one (`globalTeardown`). The app zone is UTC.
 */

test.use({ persona: 'customer', viewport: { width: 1280, height: 900 } });

const DESKTOP = { width: 1280, height: 900 };
const PHONE = { width: 360, height: 800 };
const HOUR = 60 * 60 * 1000;

function ticketTitle(label: string) {
    return `E2E-${randomBytes(8).toString('hex')} ${label}`;
}

/** A `datetime-local` value (UTC wall clock) this many milliseconds from now, to the minute. */
function inFuture(ms: number) {
    return new Date(Math.ceil((Date.now() + ms) / 60_000) * 60_000).toISOString().slice(0, 16);
}

async function createTicket(page: Page, title: string) {
    await signedIn(page, '/tickets/create');
    await page.getByLabel(/^Subject/).fill(title);
    await page.getByLabel(/^Description/).fill('Composer control check.');
    await page.getByRole('button', { name: 'Submit request' }).click();
    await expect(page).toHaveURL(/\/tickets\/TK-[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/);

    return (await page.locator('header p.font-mono').innerText()).trim();
}

async function openOperatorTicket(page: Page, number: string) {
    await signedIn(page, `/operator/tickets/${number}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
}

const toggle = (page: Page) => page.locator('[data-visibility-button]');
const trigger = (page: Page) => page.locator('[data-due-trigger]');
const dueInput = (page: Page) => page.getByLabel(/^Due date and time/);
const message = (page: Page) => page.getByPlaceholder(/Write your reply/);

const css = (locator: Locator, property: string) =>
    locator.evaluate((el, name) => getComputedStyle(el).getPropertyValue(name), property);

/** The computed colour of a throwaway element wearing a theme utility, so a state is checked against the TOKEN, not a literal. */
function tokenColour(page: Page, utility: string, property: 'color' | 'background-color' | 'border-top-color') {
    return page.evaluate(
        ([cls, prop]) => {
            const probe = document.createElement('span');
            probe.className = cls ?? '';
            document.body.append(probe);
            const value = getComputedStyle(probe).getPropertyValue(prop ?? '');
            probe.remove();

            return value;
        },
        [utility, property],
    );
}

async function pickDue(page: Page, local: string) {
    await trigger(page).click();
    await dueInput(page).fill(local);
    await dueInput(page).press('Enter');
}

test.describe('the Visible / Hidden toggle', () => {
    test('is a pressed-state button, with a tooltip on hover and on keyboard focus', async ({ page, contextFor }) => {
        const number = await createTicket(page, ticketTitle('toggle'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            const button = toggle(operator);
            const tip = operator.locator('#visibility-toggle-tip');

            await expect(button).toHaveAttribute('aria-pressed', 'false');
            await expect(button).toContainText('Visible');
            await expect(tip).toBeHidden();
            // The accessible name is the visible word plus a screen-reader tail, and it agrees with the pressed state.
            await expect(operator.getByRole('button', { name: 'Visible to submitter', pressed: false })).toBeVisible();

            // Pointer: the tooltip says what the state means; pressing hides the reply from the submitter.
            await button.hover();
            await expect(tip).toBeVisible();
            await expect(tip).toHaveText('Visible to submitter', { useInnerText: true });

            // Hoverable (WCAG 1.4.13): the pointer can travel from the button onto the tooltip without it going away.
            const tipBox = await tip.boundingBox();
            await operator.mouse.move((tipBox?.x ?? 0) + 4, (tipBox?.y ?? 0) + (tipBox?.height ?? 0) / 2, { steps: 12 });
            await expect(tip).toBeVisible();
            await button.hover();
            await button.click();
            await expect(operator.getByRole('button', { name: 'Hidden from submitter', pressed: true })).toBeVisible();
            await expect(button).toHaveAttribute('aria-pressed', 'true');
            await expect(operator.locator('[data-visibility-input]')).toHaveValue('1');
            await expect(button).toContainText('Hidden');
            await expect(tip).toHaveText('Not visible to submitter', { useInnerText: true });
            await expect(button).toHaveCSS('border-style', 'dashed');

            // Keyboard: Tab reaches it, the tooltip follows focus, Space toggles, Escape dismisses the tooltip.
            await operator.mouse.move(0, 0);
            await message(operator).focus();
            await operator.keyboard.press('Tab');
            await expect(button).toBeFocused();
            await expect(tip).toBeVisible();
            await expect(tip).toHaveText('Not visible to submitter', { useInnerText: true });
            await operator.keyboard.press('Space');
            await expect(button).toHaveAttribute('aria-pressed', 'false');
            await expect(operator.locator('[data-visibility-input]')).toHaveValue('0');
            await expect(tip).toHaveText('Visible to submitter', { useInnerText: true });
            await operator.keyboard.press('Escape');
            await expect(tip).toBeHidden();
            await expect(button).toBeFocused();
        } finally {
            await context.close();
        }
    });

    test('Hidden is distinct in words, glyph and the internal-note tokens, in both themes', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('toggle themes'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            for (const theme of ['light', 'dark'] as const) {
                await applyTheme(operator, theme, `toggle ${theme}`);
                const button = toggle(operator);

                if ((await button.getAttribute('aria-pressed')) === 'true') {
                    await button.click();
                }

                await expect(button.locator('svg[data-glyph="eye"]')).toBeVisible();
                await expect(button.locator('svg[data-glyph="eye-off"]')).toBeHidden();
                await operator.mouse.move(0, 0); // not hovered: the resting colours are the subject
                await expect
                    .poll(() => css(button, 'background-color'))
                    .toBe(await tokenColour(operator, 'bg-surface', 'background-color'));

                await button.click();
                await operator.mouse.move(0, 0);
                await expect(button.locator('svg[data-glyph="eye-off"]')).toBeVisible();
                await expect(button.locator('svg[data-glyph="eye"]')).toBeHidden();
                // Colours transition (150 ms): compare once settled.
                await expect
                    .poll(() => css(button, 'background-color'))
                    .toBe(await tokenColour(operator, 'bg-warning-soft', 'background-color'));
                await expect
                    .poll(() => css(button, 'border-top-color'))
                    .toBe(await tokenColour(operator, 'border-warning-glyph', 'border-top-color'));
                await expect.poll(() => css(button, 'color')).toBe(await tokenColour(operator, 'text-warning', 'color'));
            }
        } finally {
            await context.close();
        }
    });
});

test.describe('the inline due-date control', () => {
    test('swaps in place; Enter finishes the edit without posting; Escape cancels; the date stays pending', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due editor'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);
            const url = operator.url();
            const original = (await trigger(operator).locator('[data-due-text]').innerText()).trim();
            const typed = `Not posted yet ${randomBytes(4).toString('hex')}`;
            await message(operator).fill(typed);

            // Collapsed: a button with the date and the zone; the editor is not there.
            await expect(dueInput(operator)).toBeHidden();
            await expect(trigger(operator)).toContainText('UTC');
            const box = await trigger(operator).boundingBox();

            // Open: the field takes the button's place and holds focus; the zone is named beside it.
            await trigger(operator).click();
            await expect(dueInput(operator)).toBeFocused();
            await expect(trigger(operator)).toBeHidden();
            await expect(operator.locator('#due-date-editor-help')).toContainText('UTC');
            const editorBox = await operator.locator('[data-due-editor]').boundingBox();
            expect(Math.abs((editorBox?.y ?? 0) - (box?.y ?? 0))).toBeLessThan(2);

            // Escape cancels the edit: the original stands, nothing pending, focus returns to the button.
            await dueInput(operator).fill(inFuture(10 * HOUR));
            await operator.keyboard.press('Escape');
            await expect(dueInput(operator)).toBeHidden();
            await expect(trigger(operator)).toBeFocused();
            await expect(trigger(operator).locator('[data-due-text]')).toHaveText(original);
            await expect(operator.locator('[data-due-action]')).toHaveValue('keep');
            await expect(trigger(operator)).not.toHaveAttribute('data-pending', /.*/);

            // Enter finishes the local edit and does NOT submit the form.
            const wanted = inFuture(30 * HOUR);
            await pickDue(operator, wanted);
            await expect(trigger(operator)).toBeFocused();
            await expect(operator).toHaveURL(url);
            await expect(message(operator)).toHaveValue(typed);
            await expect(operator.locator('[data-due-action]')).toHaveValue('set');
            await expect(operator.locator('[data-due-at]')).toHaveValue(wanted);
            await expect(trigger(operator)).toHaveAttribute('data-pending', 'set');
            await expect(trigger(operator)).toContainText('Pending');

            // Nothing was saved: a fresh load still shows the original date.
            await openOperatorTicket(operator, number);
            await expect(trigger(operator).locator('[data-due-text]')).toHaveText(original);
            await expect(operator.locator('[data-due-action]')).toHaveValue('keep');
        } finally {
            await context.close();
        }
    });

    test('a click straight onto "Post Update" after typing a date posts it: the collapse does not move the button away', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due click-through'));
        const context = await contextFor('operator', { viewport: PHONE });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            const wanted = inFuture(36 * HOUR);
            await message(operator).fill(`Click through ${randomBytes(4).toString('hex')}.`);
            await trigger(operator).click();
            await dueInput(operator).fill(wanted); // the editor stays OPEN: no Enter, no blur

            // One real press and release on the button, with the editor still open when the mouse goes down.
            await operator.getByRole('button', { name: 'Post Update' }).click();

            await expect(operator.getByText('Reply added and due date updated.')).toBeVisible();
            await expect(operator.locator('[data-due-date-change]')).toHaveCount(1);
        } finally {
            await context.close();
        }
    });

    test('commits when focus leaves, and returning to the original date clears the pending change', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due blur'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);
            const originalWall = await operator.locator('[data-due-control]').getAttribute('data-original-wall');

            await trigger(operator).click();
            await dueInput(operator).fill(inFuture(40 * HOUR));
            await message(operator).focus(); // focus leaves the editor
            await expect(dueInput(operator)).toBeHidden();
            await expect(operator.locator('[data-due-action]')).toHaveValue('set');

            await trigger(operator).click();
            await dueInput(operator).fill(originalWall ?? '');
            await dueInput(operator).press('Enter');
            await expect(operator.locator('[data-due-action]')).toHaveValue('keep');
            await expect(operator.locator('[data-due-at]')).toHaveValue('');
            await expect(trigger(operator)).not.toHaveAttribute('data-pending', /.*/);
        } finally {
            await context.close();
        }
    });

    test('does not collapse while the native picker is open, and keeps a refused value open with the reason', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due picker'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            await trigger(operator).click();
            await dueInput(operator).evaluate((el) => (el as HTMLInputElement).showPicker());
            await operator.waitForTimeout(400);
            await expect(dueInput(operator)).toBeVisible();
            await expect(dueInput(operator)).toBeFocused();
            await operator.keyboard.press('Escape'); // closes the picker (or, if it is already gone, cancels the edit)

            // A past date is not silently dropped or accepted: the editor stays open and says why.
            if (!(await dueInput(operator).isVisible())) {
                await trigger(operator).click();
            }

            await dueInput(operator).fill('2020-01-01T09:00');
            await dueInput(operator).press('Enter');
            await expect(dueInput(operator)).toBeVisible();
            await expect(operator.locator('#due-date-editor-message')).toContainText('future');
            await expect(dueInput(operator)).toHaveAttribute('aria-invalid', 'true');
            await expect(operator.locator('[data-due-action]')).toHaveValue('keep');
        } finally {
            await context.close();
        }
    });

    test('urgency by the pending deadline: glyph and wording as well as colour, in both themes', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due urgency'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            const states = [
                { ms: 2 * HOUR, urgency: 'soon', glyph: 'triangle-alert', colour: 'text-warning', edge: 'border-warning-glyph', wording: /^Due in 1 hour|^Due in 2 hours/ },
                { ms: 8 * HOUR, urgency: 'ok', glyph: 'clock', colour: 'text-success', edge: 'border-success', wording: /^Due in (7|8) hours/ },
            ] as const;

            for (const theme of ['light', 'dark'] as const) {
                await applyTheme(operator, theme, `urgency ${theme}`);

                for (const state of states) {
                    await pickDue(operator, inFuture(state.ms));
                    const button = trigger(operator);

                    await expect(button).toHaveAttribute('data-urgency', state.urgency);
                    await expect(button.locator(`svg[data-glyph="${state.glyph}"]`)).toBeVisible();
                    await expect.poll(() => css(button, 'color')).toBe(await tokenColour(operator, state.colour, 'color'));
                    await expect
                        .poll(() => css(button, 'border-top-color'))
                        .toBe(await tokenColour(operator, state.edge, 'border-top-color'));
                    // Pending reads as pending: a dashed edge and the word, not only the colour.
                    await expect(button).toHaveCSS('border-top-style', 'dashed');
                    await expect(button).toContainText('Pending');

                    // The exact relative time is the description, on hover and on keyboard focus.
                    await button.focus();
                    await expect(operator.locator('#due-date-tip')).toBeVisible();
                    await expect(operator.locator('#due-date-tip')).toContainText(state.wording);
                }
            }
        } finally {
            await context.close();
        }
    });

    test('the urgency moves with the clock while the page stays open: green, yellow at 4 hours, red after 1 hour overdue', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due clock'));
        const context = await contextFor('operator', { viewport: DESKTOP });

        try {
            const operator = await context.newPage();
            await operator.clock.install();
            await openOperatorTicket(operator, number);

            const control = operator.locator('[data-due-control]');
            const deadline = Number(await control.getAttribute('data-original-epoch'));
            const serverNow = Number(await control.getAttribute('data-server-now'));
            const remaining = deadline - serverNow;
            test.skip(remaining <= 5 * HOUR, 'the new request has less than 5 hours of SLA, so the green start cannot be observed');

            await expect(trigger(operator)).toHaveAttribute('data-urgency', 'ok');

            await operator.clock.fastForward(remaining - 4 * HOUR + 60_000); // 4 h minus a minute left
            await expect(trigger(operator)).toHaveAttribute('data-urgency', 'soon');
            await expect(operator.locator('#due-date-tip')).toContainText(/^Due in 3 hours 59 minutes|^Due in 3 hours/);

            await operator.clock.fastForward(4 * HOUR + 30 * 60_000); // 30 minutes overdue
            await expect(trigger(operator)).toHaveAttribute('data-urgency', 'soon');
            await expect(operator.locator('#due-date-tip')).toContainText(/^Overdue by (29|30|31) minutes/);

            await operator.clock.fastForward(40 * 60_000); // 70 minutes overdue
            await expect(trigger(operator)).toHaveAttribute('data-urgency', 'late');
            await expect(trigger(operator).locator('svg[data-glyph="octagon-alert"]')).toBeVisible();
            await expect
                .poll(() => css(trigger(operator), 'color'))
                .toBe(await tokenColour(operator, 'text-danger', 'color'));
            await trigger(operator).focus();
            await expect(operator.locator('#due-date-tip')).toContainText(/^Overdue by 1 hour/);
        } finally {
            await context.close();
        }
    });

    test('wraps without clipping or horizontal scroll at phone width, editor open or closed, in both themes', async ({
        page,
        contextFor,
    }) => {
        const number = await createTicket(page, ticketTitle('due layout'));
        const context = await contextFor('operator', { viewport: PHONE });

        try {
            const operator = await context.newPage();
            await openOperatorTicket(operator, number);

            // What sticks out of the viewport INSIDE the reply form (the ticket header card above it has its own, older, width
            // behaviour that this package does not touch), named so a failure says where.
            const overflow = () =>
                operator.evaluate(() =>
                    [...document.querySelectorAll('form[action$="/replies"], form[action$="/replies"] *')]
                        .filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1)
                        .slice(0, 6)
                        .map((el) => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}.${String(el.getAttribute('class') ?? '').slice(0, 60)}`),
                );

            for (const theme of ['light', 'dark'] as const) {
                await applyTheme(operator, theme, `layout ${theme}`);

                const heights = await Promise.all([toggle(operator).boundingBox(), trigger(operator).boundingBox()]);
                expect(Math.abs((heights[0]?.height ?? 0) - (heights[1]?.height ?? 1))).toBeLessThan(2);
                expect(await overflow()).toEqual([]);

                await trigger(operator).click();
                expect(await overflow()).toEqual([]);
                const editor = await operator.locator('[data-due-editor]').boundingBox();
                expect((editor?.x ?? 0) + (editor?.width ?? 0)).toBeLessThanOrEqual(PHONE.width);
                await operator.keyboard.press('Escape');
            }
        } finally {
            await context.close();
        }
    });
});
