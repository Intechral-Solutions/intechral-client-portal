import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import {
    accountTrigger,
    drawerLink,
    hasHorizontalOverflow,
    openAccountMenu,
    railLink,
} from './support/shell';

/**
 * EPIC-013 WP5 — the Blade renderer of the Direction D shell, and renderer coexistence, in real
 * Chromium (§14, §25.3 flows 1, 6, 7, 8, 10, 12).
 *
 * Blade pages here are Helpdesk (`/operator/tickets`, `/tickets`) and Resources (`/pages`): both are
 * `document` destinations in the canonical contract, so every crossing below is a full page load.
 * Authentication comes from the shared per-worker persona sessions (support/auth.ts) — no test here
 * submits the login form.
 */

const XL = { width: 1440, height: 900 };
const L = { width: 1200, height: 900 };
const M = { width: 900, height: 900 };
const S = { width: 390, height: 844 };

const bladeShell = (page: Page) =>
    page.locator('[data-shell="operational"][data-shell-renderer="blade"]');
const reactShell = (page: Page) => page.locator('#app [data-shell="operational"]');
const currentWorkspace = (page: Page) =>
    page.getByRole('navigation', { name: 'Workspaces' }).locator('[aria-current="page"]');

async function box(page: Page, selector: string) {
    return page.locator(selector).boundingBox();
}

/** Press Tab until `predicate` holds, so focus is keyboard focus and `:focus-visible` applies. */
async function tabTo(page: Page, predicate: () => Promise<boolean>) {
    for (let step = 0; step < 40; step++) {
        await page.keyboard.press('Tab');

        if (await predicate()) {
            return true;
        }
    }

    return false;
}

test('the Blade shell has the Direction D geometry and server state in both themes', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/operator/tickets');

    for (const theme of ['light', 'dark'] as const) {
        await page.evaluate((value) => localStorage.setItem('theme', value), theme);
        await page.reload();

        await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await expect(bladeShell(page)).toHaveCount(1);
        await expect(page.locator('#app')).toHaveCount(0);

        // Direction D §5.1, the same numbers the React shell is held to: 64 / 248 / 48, canvas after
        // both docked columns. Helpdesk's server default is an open panel.
        expect((await box(page, '[data-shell-rail]'))?.width).toBeCloseTo(64, 0);
        expect((await box(page, '[data-shell-drawer]'))?.width).toBeCloseTo(248, 0);
        expect((await box(page, '[data-shell-utility]'))?.height).toBeCloseTo(48, 0);
        expect((await box(page, '[data-shell-canvas]'))?.x).toBeCloseTo(64 + 248, 0);
        expect(await hasHorizontalOverflow(page)).toBe(false);

        // Server-computed active state, rendered, never derived: one workspace, one view.
        await expect(currentWorkspace(page)).toHaveCount(1);
        await expect(currentWorkspace(page)).toHaveText('Helpdesk');
        await expect(
            page
                .getByRole('navigation', { name: 'Helpdesk views' })
                .locator('[aria-current="page"]'),
        ).toHaveText('Queue');
        await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText('Queue');
    }
});

test('React → Blade → React keeps theme, panel state, active state and history coherent', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/projects');
    await expect(reactShell(page)).toHaveCount(1);

    // Choices made in React…
    await openAccountMenu(page);
    await page.getByRole('radio', { name: 'dark' }).click();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);

    // …cross a document boundary into Blade: React is unloaded and the Blade shell renders with the
    // server's active workspace and the same theme.
    await railLink(page, 'Helpdesk').click();
    await expect(page).toHaveURL(/\/tickets$/);
    await expect(page.locator('#app')).toHaveCount(0);
    await expect(bladeShell(page)).toHaveCount(1);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(currentWorkspace(page)).toHaveText('Helpdesk');
    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeVisible();

    // A choice made in Blade…
    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Show workspace views' })).toBeFocused();

    // …and back into React through an `inertia` destination on the Blade rail: React mounts, with
    // the panel state React itself remembered and the theme Blade kept.
    await railLink(page, 'Projects').click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(reactShell(page)).toHaveCount(1);
    await expect(currentWorkspace(page)).toHaveText('Projects');
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    expect(
        await page.evaluate(() =>
            JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}'),
        ),
    ).toMatchObject({ projects: 'collapsed', helpdesk: 'collapsed' });

    // History works in both directions across the renderer boundary, with no stale shell.
    await page.goBack();
    await expect(page).toHaveURL(/\/tickets$/);
    await expect(bladeShell(page)).toHaveCount(1);
    await expect(currentWorkspace(page)).toHaveText('Helpdesk');
    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeHidden();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.goForward();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(reactShell(page)).toHaveCount(1);
    await expect(currentWorkspace(page)).toHaveText('Projects');
});

test('a Blade page paints with the remembered theme and panel state, without layout shift', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    // Before any page script: remember dark + a collapsed Helpdesk panel, and record what the first
    // animation frame sees and every layout shift the page reports.
    await page.addInitScript(() => {
        localStorage.setItem('theme', 'dark');
        localStorage.setItem('shell.operational.panel', JSON.stringify({ helpdesk: 'collapsed' }));

        const probe = { firstFrame: '', shift: 0 };
        (window as unknown as { __probe: typeof probe }).__probe = probe;

        requestAnimationFrame(() => {
            const root = document.documentElement;
            probe.firstFrame = `${root.dataset.theme}/${root.dataset.drawer}`;
        });

        new PerformanceObserver((list) => {
            for (const entry of list.getEntries() as unknown as { value: number }[]) {
                probe.shift += entry.value;
            }
        }).observe({ type: 'layout-shift', buffered: true });
    });

    await page.goto('/operator/tickets');
    await expect(bladeShell(page)).toHaveCount(1);
    await page.waitForLoadState('load');

    const probe = await page.evaluate(
        () => (window as unknown as { __probe: { firstFrame: string; shift: number } }).__probe,
    );

    expect(probe.firstFrame).toBe('dark/collapsed');
    expect(probe.shift).toBe(0);
    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeHidden();
    expect((await box(page, '[data-shell-canvas]'))?.x).toBeCloseTo(64, 0);
});

test('at L the Blade panel docks only when opened, and never floats', async ({ page }) => {
    await page.setViewportSize(L);
    await signedIn(page, '/operator/tickets');

    // Below XL the panel starts collapsed whatever the default (the shared WP4 rule, A8.12 #4).
    const views = page.getByRole('navigation', { name: 'Helpdesk views' });
    const toggle = page.getByRole('button', { name: 'Show workspace views' });

    await expect(views).toBeHidden();
    expect((await box(page, '[data-shell-canvas]'))?.x).toBeCloseTo(64, 0);

    await toggle.click();

    // Blade has no overlay (§14.2): opened, it docks and the canvas moves over.
    await expect(views).toBeVisible();
    expect((await box(page, '[data-shell-canvas]'))?.x).toBeCloseTo(64 + 248, 0);
    await expect(drawerLink(page, 'Helpdesk', 'Queue')).toBeFocused();
    await expect(page.getByRole('button', { name: 'Pin workspace views' })).toHaveCount(0);

    // Docked, so Escape and a content click leave it alone — the WP4 lesson (A8.12 #3).
    await page.keyboard.press('Escape');
    await page.locator('main').click({ position: { x: 20, y: 20 } });
    await expect(views).toBeVisible();

    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(views).toBeHidden();
    await expect(toggle).toBeFocused();

    // `Ctrl+\` toggles it too, as in React.
    await page.keyboard.press('Control+\\');
    await expect(views).toBeVisible();
    await page.keyboard.press('Control+\\');
    await expect(views).toBeHidden();
    expect(await hasHorizontalOverflow(page)).toBe(false);
});

test('at M the panel content is reached through the nav sheet', async ({ page }) => {
    await page.setViewportSize(M);
    await signedIn(page, '/operator/tickets');

    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeHidden();
    await expect(page.getByRole('button', { name: 'Show workspace views' })).toBeHidden();

    const trigger = page.getByRole('button', { name: 'Open navigation' });

    await trigger.click();

    const sheet = page.getByRole('dialog', { name: 'Navigation' });

    await expect(sheet).toBeVisible();
    await expect(
        sheet.getByRole('navigation', { name: 'Helpdesk views' }).locator('[aria-current="page"]'),
    ).toHaveText('Queue');

    // Native modal dialog: Escape closes it and focus returns to the trigger.
    await page.keyboard.press('Escape');
    await expect(sheet).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    expect(await hasHorizontalOverflow(page)).toBe(false);
});

test('the narrow Blade shell is a top bar with a nav sheet and reachable targets', async ({
    page,
}) => {
    await page.setViewportSize(S);
    await signedIn(page, '/operator/tickets');

    expect((await box(page, '[data-shell-rail]'))?.height).toBeCloseTo(56, 0);
    await expect(page.getByRole('navigation', { name: 'Workspaces' })).toBeHidden();
    await expect(accountTrigger(page)).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);

    // The account menu opens on top of the page, not underneath the sticky utility bar.
    await openAccountMenu(page);

    const profile = page.getByRole('menuitem', { name: 'Profile' });
    const target = await profile.boundingBox();

    expect(
        await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.textContent?.trim(), {
            x: (target?.x ?? 0) + 10,
            y: (target?.y ?? 0) + (target?.height ?? 0) / 2,
        }),
    ).toBe('Profile');
    await page.keyboard.press('Escape');
    await expect(accountTrigger(page)).toBeFocused();

    await page.getByRole('button', { name: 'Open navigation' }).click();

    const sheet = page.getByRole('dialog', { name: 'Navigation' });

    await expect(sheet.getByRole('navigation', { name: 'Workspaces' })).toBeVisible();
    await expect(
        sheet.getByRole('navigation', { name: 'Workspaces' }).locator('[aria-current="page"]'),
    ).toHaveText('Helpdesk');

    // Touch targets ≥ 44px at S (§16).
    const row = await sheet.getByRole('link', { name: 'Projects', exact: true }).boundingBox();

    expect(row?.height ?? 0).toBeGreaterThanOrEqual(44);

    // An `inertia` destination from the sheet boots React.
    await sheet.getByRole('link', { name: 'Projects', exact: true }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(reactShell(page)).toHaveCount(1);
});

test('the Blade shell reflows at 200% zoom without horizontal scrolling', async ({ page }) => {
    // 200% zoom at 1280 is a 640px CSS viewport.
    await page.setViewportSize({ width: 640, height: 450 });
    await signedIn(page, '/operator/tickets');

    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expect(page.getByRole('button', { name: 'Open navigation' })).toBeVisible();
    await expect(accountTrigger(page)).toBeVisible();
});

test('the Blade account menu is personal, keyboard-operable, and owns Appearance', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/operator/tickets');
    await page.evaluate(() => localStorage.setItem('theme', 'light'));
    await page.reload();

    const trigger = accountTrigger(page);

    await expect(trigger).toHaveAttribute('aria-haspopup', 'menu');
    await trigger.focus();
    await page.keyboard.press('Enter');

    const menu = page.getByRole('menu');

    await expect(menu).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByRole('menuitem', { name: 'Profile' })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('menuitem', { name: 'Security & MFA' })).toBeFocused();

    // L15: an operator holds every permission, so any leak would show here.
    for (const absent of [
        'Manage',
        'Users',
        'Roles',
        'Organizations',
        'Ticket Queue',
        'Time Reports',
    ]) {
        await expect(menu.getByText(absent, { exact: true })).toHaveCount(0);
    }

    await expect(page.getByRole('menuitem', { name: /Notifications/ })).toHaveAttribute(
        'aria-disabled',
        'true',
    );

    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(trigger).toBeFocused();

    // Appearance in Blade writes the one theme contract React reads.
    await openAccountMenu(page);
    await page.getByRole('menuitemradio', { name: 'dark' }).click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(page.getByRole('menuitemradio', { name: 'dark' })).toHaveAttribute(
        'aria-checked',
        'true',
    );

    await railLink(page, 'Home').click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(reactShell(page)).toHaveCount(1);
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await openAccountMenu(page);
    await expect(page.getByRole('radio', { name: 'dark' })).toHaveAttribute('aria-checked', 'true');
});

test('keyboard: the skip link is first, and the rail and panel are plain Tab stops', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/operator/tickets');

    await page.keyboard.press('Tab');

    const skip = page.getByRole('link', { name: 'Skip to content' });

    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();
    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();

    await page.reload();

    const reachedRail = await tabTo(page, () =>
        page.evaluate(() =>
            Boolean(document.activeElement?.closest('nav[aria-label="Workspaces"]')),
        ),
    );

    expect(reachedRail).toBe(true);

    const outline = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement as Element);

        return { style: style.outlineStyle, width: style.outlineWidth };
    });

    expect(outline.style).not.toBe('none');
    expect(outline.width).toBe('2px');

    const reachedPanel = await tabTo(page, () =>
        page.evaluate(() => Boolean(document.activeElement?.closest('[data-shell-drawer]'))),
    );

    expect(reachedPanel).toBe(true);
    // L11: no roving tabindex on the rail or panel links.
    expect(
        await page.evaluate(
            () =>
                document.querySelectorAll(
                    // Navigation links only: the account menu's items are a composite widget whose
                    // roving `tabindex="-1"` is correct (L11 exempts genuine composites).
                    '[data-shell-rail] nav a[tabindex], [data-shell-drawer] nav a[tabindex]',
                ).length,
        ),
    ).toBe(0);
});

test.describe('as a member', () => {
    // The authorization assertions are about this actor's own capability profile.
    test.use({ persona: 'member' });

    test('Blade reveals nothing the server withheld, keeps Resources, and 403 still holds', async ({
        page,
    }) => {
        await page.setViewportSize(XL);
        await signedIn(page, '/tickets');

        await expect(bladeShell(page)).toHaveCount(1);

        const rail = page.getByRole('navigation', { name: 'Workspaces' });

        await expect(rail.getByRole('link', { name: 'System', exact: true })).toHaveCount(0);
        await expect(rail.getByRole('link', { name: 'Directory', exact: true })).toHaveCount(0);
        await expect(
            page.getByRole('navigation', { name: 'Helpdesk views' }).getByRole('link'),
        ).toHaveText(['My requests']);

        // The ninth workspace, a single Blade surface with no panel.
        await railLink(page, 'Resources').click();
        await expect(page).toHaveURL(/\/pages$/);
        await expect(currentWorkspace(page)).toHaveText('Resources');
        await expect(bladeShell(page)).toHaveAttribute('data-panel', 'false');
        await expect(page.locator('[data-shell-drawer]')).toHaveCount(0);

        // Visibility is not authorization: the route still refuses, inside the same shell.
        const response = await page.goto('/admin/users');

        expect(response?.status()).toBe(403);
        await expect(bladeShell(page)).toHaveCount(1);
        await expect(rail.getByRole('link', { name: 'System', exact: true })).toHaveCount(0);
    });
});
