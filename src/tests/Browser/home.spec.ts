import type { Page } from '@playwright/test';

import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-013 WP7 — Home and the page frame in a real browser (§19, §21, §25.2).
 *
 * The Vitest suite asserts which geometry `PageFrame` *names*; this asserts what that geometry
 * actually measures, because the widths live in `app.css` and jsdom loads no stylesheet. Splitting it
 * that way is the point: neither half can quietly assert nothing.
 *
 * **This file mutates nothing.** Home is read-only, it creates no fixture and starts no timer, so it
 * introduces no shared-resource hazard of the kind A11.15 records for the timer specs and needs no
 * ownership boundary. Board coverage deliberately lives in `board-migration.spec.ts` instead, with
 * the project fixtures and cleanup that already own that surface.
 */

const XL = { width: 1440, height: 900 };
const L = { width: 1200, height: 800 };
const M = { width: 900, height: 800 };
const S = { width: 390, height: 844 };

function frame(page: Page) {
    return page.locator('[data-page-frame]');
}

async function gutters(page: Page) {
    return frame(page).evaluate((node) => {
        const style = getComputedStyle(node);

        return { left: style.paddingLeft, right: style.paddingRight };
    });
}

test('Home is a grid frame whose supporting column appears only at XL', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/dashboard');

    await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();
    await expect(frame(page)).toHaveAttribute('data-page-frame', 'grid');

    // Direction D §9: `1fr + 340–360px`. The aside is the fixed side of that split.
    const aside = page.getByRole('complementary', { name: 'Shortcuts and directory' });
    const asideBox = (await aside.boundingBox())!;
    expect(asideBox.width).toBeCloseTo(340, 0);

    // The header spans the whole content region rather than stopping where the aside begins (§6),
    // so its rule is wider than the column beneath it.
    const headerBox = (await page.locator('[data-page-header]').boundingBox())!;
    const frameBox = (await frame(page).boundingBox())!;
    expect(headerBox.width).toBeGreaterThan(frameBox.width - asideBox.width - 80);

    // Below XL the drawer has already taken 248px, so the split would leave the main column
    // narrower than the aside. It stacks instead, and the aside runs the full content width.
    await page.setViewportSize(L);
    const stacked = (await aside.boundingBox())!;
    expect(stacked.width).toBeGreaterThan(asideBox.width);
    expect(await hasHorizontalOverflow(page)).toBe(false);
});

test('the page gutters step 40 / 32 / 24 / 16 through the width classes', async ({ page }) => {
    await signedIn(page, '/dashboard');

    // §19.2's four values, measured rather than assumed. `main` stays unpadded — the frame owns the
    // page padding, which is what lets a canvas page reclaim it.
    for (const [viewport, expected] of [
        [XL, '40px'],
        [L, '32px'],
        [M, '24px'],
        [S, '16px'],
    ] as const) {
        await page.setViewportSize(viewport);

        const pad = await gutters(page);
        expect(pad.left, `left gutter at ${viewport.width}px`).toBe(expected);
        expect(pad.right, `right gutter at ${viewport.width}px`).toBe(expected);
        expect(await hasHorizontalOverflow(page)).toBe(false);
    }

    const mainPadding = await page
        .locator('main')
        .evaluate((node) => getComputedStyle(node).paddingLeft);
    expect(mainPadding).toBe('0px');
});

test('Home shows its metrics as one figure band, not as four cards', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/dashboard');

    const figures = page.locator('dl').first().getByRole('term');
    await expect(figures.first()).toBeVisible();

    // Structure over boxes (L10): the figures are separated by hairlines drawn by the band, so no
    // figure carries a radius or a shadow of its own. Four rounded, shadowed boxes is exactly the
    // dashboard cliché §21.1 rules out.
    const boxed = await page.locator('dl').first().evaluate((list) => {
        return Array.from(list.children).filter((child) => {
            const style = getComputedStyle(child as HTMLElement);

            return style.borderRadius !== '0px' || style.boxShadow !== 'none';
        }).length;
    });
    expect(boxed).toBe(0);
});

test('Home reflows at 200% zoom and at 390px without clipping its heading', async ({ page }) => {
    await signedIn(page, '/dashboard');

    // 200% zoom halves the CSS viewport, which lands in the S width class (the same equivalence
    // shell.spec.ts uses).
    for (const viewport of [{ width: 640, height: 450 }, S]) {
        await page.setViewportSize(viewport);
        await page.goto('/dashboard');

        expect(await hasHorizontalOverflow(page), `overflow at ${viewport.width}px`).toBe(false);

        const heading = page.getByRole('heading', { level: 1, name: 'Home' });
        await expect(heading).toBeVisible();

        // Visible is not the same as legible: assert the heading is not clipped by its own box.
        const clipped = await heading.evaluate(
            (node) => node.scrollWidth > node.clientWidth + 1 || node.scrollHeight > node.clientHeight + 1,
        );
        expect(clipped, `heading clipped at ${viewport.width}px`).toBe(false);

        // The supporting column stacks under the content it supports, which is its reading order.
        await expect(page.getByRole('complementary', { name: 'Shortcuts and directory' })).toBeVisible();
    }
});

test('Home holds its structure in both themes', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/dashboard');

    // The figure band's numbers must be drawn from a token, not a literal colour — a literal would
    // render identically in both themes. Captured across the loop below and compared afterwards,
    // rather than asserted merely truthy in each iteration, which a hard-coded colour would also
    // satisfy.
    const colours: Record<'light' | 'dark', string> = { light: '', dark: '' };

    for (const theme of ['light', 'dark'] as const) {
        await page.evaluate((value) => localStorage.setItem('theme', value), theme);
        await page.reload();

        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await expect(page.getByRole('heading', { level: 1, name: 'Home' })).toBeVisible();

        colours[theme] = await page
            .locator('dl')
            .first()
            .getByRole('definition')
            .first()
            .evaluate((node) => getComputedStyle(node).color);

        expect(await hasHorizontalOverflow(page)).toBe(false);
    }

    expect(colours.light).toBeTruthy();
    expect(colours.dark).toBeTruthy();
    expect(colours.light).not.toBe(colours.dark);

    await page.evaluate(() => localStorage.setItem('theme', 'light'));
});

test('Home has exactly one h1 and one breadcrumb, and fabricates no section', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/dashboard');

    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    // The shell owns the breadcrumb (WP4/WP5). The page adds none, so there is one landmark.
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);

    // §21.1 marks each of these Future precisely because there is no data behind it. Home showing
    // one would mean it had been invented.
    for (const absent of ['Approvals', 'Awaiting you', 'Project health', 'SLA', 'My work']) {
        await expect(page.getByRole('heading', { name: absent })).toHaveCount(0);
    }

    // Direction D §17 forbids the strata motif on Home: it marks a record, and Home is not one.
    await expect(page.locator('[data-strata]')).toHaveCount(0);
});
