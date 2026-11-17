import type { Locator, Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * Release 1.0 (DIR-1) — the three expanded administration lists (Directory → Organizations, System → Organization access, System → Users)
 * at a phone width and at a desktop width, as the operator persona.
 *
 * The tables have more columns than a 390px screen holds, so each card is a named, focusable, horizontally scrollable REGION. This proves
 * what the correction promises: no page-level horizontal overflow at either width; the region really scrolls at 390px and does not need to at
 * desktop; a keyboard user can Tab to it, sees the Direction D focus ring, and can scroll it; the first column and the row's action link
 * stay reachable and operable at 390px.
 *
 * It reads seeded fixtures (`E2E Customer Org`, `E2E Customer`). The Directory list is empty on a fresh seed, so the test creates ONE
 * Organization through the form and deletes it again in `finally`, the way blade-theme-controls does for its own records.
 */

const NARROW = { width: 390, height: 844 };
const DESKTOP = { width: 1440, height: 900 };
const COMPANY = `E2E DIR1 Narrow ${Date.now().toString(36)}`;

type ListCase = {
    label: string;
    path: string;
    region: string;
    /** The row the list shows for the fixture. */
    row: (page: Page) => Locator;
    /** The first-column link of that row, which must be visible at 390px without scrolling. */
    first: (row: Locator) => Locator;
    /** The last-column action link of that row, which must be reachable and operable at 390px. */
    action: (row: Locator) => Locator;
    destination: RegExp;
};

const CASES: ListCase[] = [
    {
        label: 'Directory → Organizations',
        path: `/crm/companies?search=${encodeURIComponent(COMPANY)}`,
        region: 'Organizations list',
        row: (page) => page.getByRole('row').filter({ hasText: COMPANY }),
        first: (row) => row.getByRole('link', { name: COMPANY, exact: true }),
        action: (row) => row.getByRole('link', { name: 'Edit', exact: true }),
        destination: /\/crm\/companies\/\d+\/edit$/,
    },
    {
        label: 'System → Organization access',
        path: '/organizations',
        region: 'Organizations with portal access',
        row: (page) => page.getByRole('row').filter({ hasText: 'E2E Customer Org' }),
        first: (row) => row.getByRole('link', { name: 'E2E Customer Org', exact: true }),
        action: (row) => row.getByRole('link', { name: 'Manage E2E Customer Org', exact: true }),
        destination: /\/organizations\/\d+$/,
    },
    {
        label: 'System → Users',
        path: '/admin/users?search=e2e-customer%40intechral.test',
        region: 'Users list',
        row: (page) => page.getByRole('row').filter({ hasText: 'e2e-customer@intechral.test' }),
        first: (row) => row.getByText('E2E Customer', { exact: true }).first(),
        action: (row) => row.getByRole('link', { name: 'View', exact: true }),
        destination: /\/admin\/users\/\d+$/,
    },
];

/** Tab from the top of the page until `target` has focus. False if it never does (it is not in the tab order). */
async function tabTo(page: Page, target: Locator) {
    for (let step = 0; step < 90; step++) {
        await page.keyboard.press('Tab');

        if (await target.evaluate((element) => element === document.activeElement)) {
            return true;
        }
    }

    return false;
}

const scrolls = (region: Locator) =>
    region.evaluate((element) => element.scrollWidth > element.clientWidth + 1);

async function createCompany(page: Page) {
    await page.goto('/crm/companies/create');
    await page.getByLabel('Organization name').fill(COMPANY);
    await page.getByRole('button', { name: 'Create Organization' }).click();
    await expect(page).toHaveURL(/\/crm\/companies\/\d+$/);

    return new URL(page.url()).pathname;
}

async function deleteCompany(page: Page, path: string) {
    await page.goto(`${path}/edit`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete Organization' }).click();
    await expect(page).toHaveURL(/\/crm\/companies$/);
}

test('the expanded administration lists stay usable at 390px and unchanged at desktop width', async ({
    page,
}) => {
    test.setTimeout(180_000);
    await signedIn(page);
    const company = await createCompany(page);

    try {
        for (const list of CASES) {
            // ── Desktop: the table fits, so the region has nothing to scroll and the action needs no scrolling ──
            await page.setViewportSize(DESKTOP);
            await page.goto(list.path);
            let region = page.getByRole('region', { name: list.region });
            await expect(region, `${list.label} desktop region`).toBeVisible();
            expect(
                await hasHorizontalOverflow(page),
                `${list.label} desktop: no page overflow`,
            ).toBe(false);
            expect(await scrolls(region), `${list.label} desktop: nothing to scroll`).toBe(false);
            await expect(
                list.action(list.row(page)),
                `${list.label} desktop: action visible`,
            ).toBeInViewport();

            // ── 390px: no page overflow; the region scrolls inside itself; first column visible without scrolling ──
            await page.setViewportSize(NARROW);
            await page.goto(list.path);
            region = page.getByRole('region', { name: list.region });
            await expect(region).toBeVisible();
            expect(await hasHorizontalOverflow(page), `${list.label} 390: no page overflow`).toBe(
                false,
            );
            expect(await scrolls(region), `${list.label} 390: the region scrolls`).toBe(true);

            const row = list.row(page);
            await expect(
                list.first(row),
                `${list.label} 390: first column visible`,
            ).toBeInViewport();

            // ── Keyboard: Tab reaches the region, it shows the focus ring, and arrow keys scroll it ──
            expect(await tabTo(page, region), `${list.label}: the region is in the tab order`).toBe(
                true,
            );
            const ring = await region.evaluate((element) => {
                const style = getComputedStyle(element);

                return {
                    focusVisible: element.matches(':focus-visible'),
                    style: style.outlineStyle,
                    width: style.outlineWidth,
                };
            });
            expect(ring, `${list.label}: visible keyboard focus ring`).toEqual({
                focusVisible: true,
                style: 'solid',
                width: '2px',
            });

            for (let press = 0; press < 12; press++) {
                await page.keyboard.press('ArrowRight');
            }
            expect(
                await region.evaluate((element) => element.scrollLeft),
                `${list.label}: arrow keys scroll the region`,
            ).toBeGreaterThan(0);

            // ── The row's action link is reachable and operable by keyboard, and no page overflow appears ──
            const action = list.action(list.row(page));
            await action.focus();
            await expect(action, `${list.label} 390: action visible once focused`).toBeInViewport();
            expect(
                await hasHorizontalOverflow(page),
                `${list.label} 390 after scrolling: no page overflow`,
            ).toBe(false);
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(list.destination);
        }
    } finally {
        await page.setViewportSize(DESKTOP);
        await deleteCompany(page, company);
    }
});
