import { expect, signedIn, test } from './support/auth';
import { signIn } from './support/sign-in';
import {
    accountTrigger,
    drawerLink,
    hasHorizontalOverflow,
    openAccountMenu,
    railLink,
} from './support/shell';

/**
 * EPIC-013 WP4 — the Direction D operator shell in real Chromium (§25.3).
 *
 * Semantics over pixels, except where Direction D §5.1 fixes a dimension, which is measured.
 */

/** Press Tab until `predicate` holds, so focus is keyboard focus and `:focus-visible` applies. */
async function tabTo(page: import('@playwright/test').Page, predicate: () => Promise<boolean>) {
    for (let step = 0; step < 30; step++) {
        await page.keyboard.press('Tab');

        if (await predicate()) {
            return true;
        }
    }

    return false;
}

const XL = { width: 1440, height: 900 };
const L = { width: 1200, height: 900 };
const S = { width: 390, height: 844 };

test('shell geometry matches the Direction D contract in both themes', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    for (const theme of ['light', 'dark'] as const) {
        await page.evaluate((value) => {
            document.documentElement.dataset.theme = value;
            localStorage.setItem('theme', value);
        }, theme);

        await railLink(page, 'Projects').click();
        await expect(page).toHaveURL(/\/projects$/);

        const rail = page.locator('[data-shell-rail]');
        const drawer = page.locator('[data-shell-drawer]');
        const utility = page.locator('[data-shell-utility]');

        // Direction D §5.1: 64px rail, 248px drawer, 48px utility bar.
        expect((await rail.boundingBox())?.width).toBeCloseTo(64, 0);
        expect((await drawer.boundingBox())?.width).toBeCloseTo(248, 0);
        expect((await utility.boundingBox())?.height).toBeCloseTo(48, 0);

        // The docked drawer is in the layout flow, so the canvas starts after both columns rather
        // than under them.
        const canvas = await page.locator('[data-shell-canvas]').boundingBox();

        expect(canvas?.x).toBeCloseTo(64 + 248, 0);
        expect(await hasHorizontalOverflow(page)).toBe(false);
    }
});

test('rail navigation carries server-computed active state and the breadcrumb follows', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    for (const [workspace, url] of [
        ['Home', /\/dashboard$/],
        ['Projects', /\/projects$/],
        ['Tasks', /\/tasks$/],
        ['Time', /\/time$/],
    ] as const) {
        await railLink(page, workspace).click();
        await expect(page).toHaveURL(url);

        // Exactly one rail item is current, and it is the one the server marked (§12.3 rule 3).
        const current = page
            .getByRole('navigation', { name: 'Workspaces' })
            .locator('[aria-current="page"]');

        await expect(current).toHaveCount(1);
        await expect(current).toHaveText(workspace);

        // The breadcrumb is always present and ends with the current page (§8).
        await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toContainText(workspace);
    }
});

test('a document destination leaves React and the Blade shell takes over', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    // Helpdesk is `visit: document`, so this is a full page load, not a client visit.
    await railLink(page, 'Helpdesk').click();
    await expect(page).toHaveURL(/\/tickets$/);

    // React is gone: the Direction D shell is not on this page yet (Blade parity is WP5).
    await expect(page.locator('[data-shell="operational"]')).toHaveCount(0);

    // …and the theme survived the boundary, which is what the shared token layer exists for.
    await expect(page.locator('html')).toHaveAttribute('data-theme', /light|dark/);

    // Back into React through the Blade shell's own navigation.
    await page.getByRole('link', { name: 'Home', exact: true }).first().click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.locator('[data-shell="operational"]')).toHaveCount(1);
});

test('drawer state is remembered per workspace across a reload', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await railLink(page, 'Projects').click();
    // Projects defaults to open (Direction D §5.3).
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toBeVisible();

    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);

    await page.reload();
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);

    // Switching workspaces applies that workspace's own state, not the one just left. The URL wait
    // matters: Projects is collapsed here too, so the rail toggle is already on screen and clicking
    // it before the visit lands would toggle the workspace being left.
    await railLink(page, 'Tasks').click();
    await expect(page).toHaveURL(/\/tasks$/);
    await expect(page.getByRole('button', { name: 'Show workspace views' })).toBeVisible();
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await expect(page.getByRole('navigation', { name: 'Tasks views' })).toBeVisible();

    await railLink(page, 'Projects').click();
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);

    expect(
        await page.evaluate(() =>
            JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}'),
        ),
    ).toMatchObject({ projects: 'collapsed', tasks: 'open' });
});

test('a corrupt stored preference falls back to the server default without breaking', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await page.evaluate(() => localStorage.setItem('shell.operational.panel', '{not json'));
    await page.goto('/projects');

    // The server hint wins, and nothing threw on the way.
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
});

test('the drawer floats as an overlay at L and pinning docks it', async ({ page }) => {
    await page.setViewportSize(L);
    await signedIn(page);

    await railLink(page, 'Projects').click();
    await expect(page).toHaveURL(/\/projects$/);

    // Below XL the panel starts collapsed whatever the workspace default says (§5.2), because it
    // floats here and would otherwise cover the canvas on load. The user opens it deliberately.
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Show workspace views' }).click();

    const drawer = page.getByRole('navigation', { name: 'Projects views' });

    await expect(drawer).toBeVisible();

    // Overlay: it floats over the canvas, so the canvas still starts right after the rail.
    const canvas = await page.locator('[data-shell-canvas]').boundingBox();

    expect(canvas?.x).toBeCloseTo(64, 0);
    // No scrim (§5.4): content stays visible behind it.
    await expect(page.locator('[data-shell-drawer] .bg-scrim')).toHaveCount(0);

    await page.getByRole('button', { name: 'Pin workspace views' }).click();

    // Pinned at L: it docks and the canvas moves over.
    await expect
        .poll(async () => (await page.locator('[data-shell-canvas]').boundingBox())?.x)
        .toBeCloseTo(64 + 248, 0);

    expect(
        await page.evaluate(() =>
            JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}'),
        ),
    ).toMatchObject({ projects: true });
});

test('Escape dismisses a floating drawer and returns focus to the rail toggle', async ({
    page,
}) => {
    // At L the panel floats, which is where Esc applies (Direction D §5.4, §14.3).
    await page.setViewportSize(L);
    await signedIn(page);

    await railLink(page, 'Tasks').click();
    await expect(page).toHaveURL(/\/tasks$/);

    const toggle = page.getByRole('button', { name: 'Show workspace views' });

    await toggle.click();
    await expect(page.getByRole('navigation', { name: 'Tasks views' })).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(page.getByRole('navigation', { name: 'Tasks views' })).toHaveCount(0);
    await expect(toggle).toBeFocused();
});

test('Escape leaves a docked drawer alone, so content clicks cannot shift the layout', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await railLink(page, 'Projects').click();
    await expect(page).toHaveURL(/\/projects$/);

    const drawer = page.getByRole('navigation', { name: 'Projects views' });

    await expect(drawer).toBeVisible();

    // A docked panel is part of the layout: dismissing it here would collapse a grid column under
    // the cursor, which is exactly what broke board drags and form clicks before this rule existed.
    await page.keyboard.press('Escape');
    await expect(drawer).toBeVisible();

    await page.getByRole('heading', { name: 'Projects', level: 1 }).click();
    await expect(drawer).toBeVisible();

    // `Ctrl+\` remains the way to collapse it (§14.3).
    await page.keyboard.press('Control+\\');
    await expect(drawer).toHaveCount(0);
});

test.describe('signing out', () => {
    // A real login, on purpose. Sessions are database-backed, so the sign-out below destroys the
    // session row it is presenting; if that were the shared baseline every later spec would run
    // unauthenticated. Logout transitions also belong on the real path on their own merits.
    //
    // Signs in as `e2e-signout@intechral.test` (DevSeeder), a dedicated fixture holding the same
    // `operator` role — not the reusable operator persona itself. Every other worker that needs the
    // operator persona mints its own real session too (support/auth.ts); adding this file's own real
    // login to that same identity's Fortify bucket would reintroduce a limiter collision the
    // per-worker session design exists to avoid. The assertion below — that a fully-permissioned
    // account's menu leaks no administration item — holds for any account holding every permission,
    // not specifically the shared one.
    test.use({ persona: 'anonymous' });

    test('the account menu is personal only and signs out', async ({ page }) => {
        await page.setViewportSize(XL);
        await signIn(page, 'e2e-signout@intechral.test');

        await openAccountMenu(page);

        for (const item of ['Profile', 'Security & MFA', 'Connected accounts', 'Sessions']) {
            await expect(page.getByRole('menuitem', { name: item })).toBeVisible();
        }

        // L15: administration lives under System. An operator holds every permission, so if the
        // boundary leaked at all it would leak here.
        for (const absent of ['Manage', 'Users', 'Roles', 'Organizations', 'Ticket Queue']) {
            await expect(page.getByRole('menu').getByText(absent, { exact: true })).toHaveCount(0);
        }

        await page.getByRole('menuitem', { name: 'Sign out' }).click();
        await expect(page).toHaveURL(/\/login$/);
    });
});

test('the skip link is the first tab stop and lands in main', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await page.keyboard.press('Tab');

    const skip = page.getByRole('link', { name: 'Skip to content' });

    await expect(skip).toBeFocused();
    // Visible on focus, never removed (Direction D §14.1).
    await expect(skip).toBeVisible();

    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
});

test('the rail and drawer are reachable by plain Tab with visible focus', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    await railLink(page, 'Projects').click();
    // Tab order is only meaningful once the panel is actually rendered: `tabTo` walks up to 30 stops
    // and would otherwise be racing the Inertia visit rather than asserting the order.
    await expect(page.getByRole('navigation', { name: 'Projects views' })).toBeVisible();

    // L11: plain links in normal Tab order, so no rail or drawer link may carry a tabindex.
    expect(
        await page.evaluate(
            () =>
                Array.from(
                    document.querySelectorAll(
                        '[data-shell-rail] a[tabindex], [data-shell-drawer] a[tabindex]',
                    ),
                ).length,
        ),
    ).toBe(0);

    // Focus must be reached by the keyboard, not `.focus()`: `:focus-visible` deliberately does not
    // match programmatic focus, so a scripted focus would report no outline and prove nothing.
    const reached = await tabTo(page, () =>
        page.evaluate(() =>
            Boolean(
                document.activeElement?.closest('[data-shell-rail] nav[aria-label="Workspaces"]'),
            ),
        ),
    );

    expect(reached).toBe(true);

    const outline = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement as Element);

        return { style: style.outlineStyle, width: style.outlineWidth };
    });

    // Direction D §14.1: a 2px `focus` outline, never removed.
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBe('2px');

    const drawerReached = await tabTo(page, () =>
        page.evaluate(() => Boolean(document.activeElement?.closest('[data-shell-drawer]'))),
    );

    expect(drawerReached).toBe(true);
});

test.describe('as a member', () => {
    // These two assert what this actor may and may not reach, so the persona IS the test. It must not
    // be collapsed onto the operator to save a login: that would delete the authorization coverage.
    test.use({ persona: 'member' });

    test('capability filtering holds on the shell, and visibility is not authorization', async ({
        page,
    }) => {
        await page.setViewportSize(XL);
        await signedIn(page);

        const rail = page.getByRole('navigation', { name: 'Workspaces' });

        // A `user`-role actor never sees System or Directory (§27).
        await expect(rail.getByRole('link', { name: 'System', exact: true })).toHaveCount(0);
        await expect(rail.getByRole('link', { name: 'Directory', exact: true })).toHaveCount(0);
        // …and does keep the transitional G3 viewer workspace.
        await expect(rail.getByRole('link', { name: 'Resources', exact: true })).toBeVisible();

        // The shell only decides what to draw: the route still refuses.
        const response = await page.goto('/admin/users');

        expect(response?.status()).toBe(403);
    });

    test('the G3 Resources workspace is a document destination with no drawer', async ({
        page,
    }) => {
        await page.setViewportSize(XL);
        await signedIn(page);

        await railLink(page, 'Resources').click();
        await expect(page).toHaveURL(/\/pages$/);

        // A single surface with no views: a full page load into Blade, and no panel anywhere.
        await expect(page.locator('[data-shell="operational"]')).toHaveCount(0);
    });
});

test('the narrow shell is usable and the wide canvas reclaims the viewport', async ({ page }) => {
    await signedIn(page);

    await page.setViewportSize(S);
    await page.goto('/tasks');

    // S: the rail is a 56px top bar and the drawer is not rendered at all (§16).
    expect((await page.locator('[data-shell-rail]').boundingBox())?.height).toBeCloseTo(56, 0);
    await expect(page.getByRole('navigation', { name: 'Tasks views' })).toBeHidden();
    expect(await hasHorizontalOverflow(page)).toBe(false);

    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    // Focus returns to the trigger, which Radix's Dialog guarantees.
    await expect(page.getByRole('button', { name: 'Open navigation' })).toBeFocused();

    // XL with the panel collapsed: the canvas is the viewport minus the rail (§25.3 flow 11).
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/tasks');
    expect((await page.locator('[data-shell-canvas]').boundingBox())?.width).toBeCloseTo(
        1440 - 64,
        0,
    );
});

test('the shell reflows at 200% zoom without horizontal scrolling', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page);

    // 200% zoom is equivalent to halving the CSS viewport, which lands in the S width class.
    await page.setViewportSize({ width: 640, height: 450 });
    await page.goto('/projects');

    expect(await hasHorizontalOverflow(page)).toBe(false);
    await expect(page.getByRole('button', { name: 'Open navigation' })).toBeVisible();
    await expect(accountTrigger(page)).toBeVisible();
});
