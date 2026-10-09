import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import { test as testWithCleanup } from './support/e2e-fixtures';
import { accountTrigger, hasHorizontalOverflow, openAccountMenu } from './support/shell';
import { settleTheme, type Theme } from './support/theme';

/**
 * EPIC-017 WP1 — the Focused customer shell, in real Chromium (§25.3 journeys 1, 2 and 11).
 *
 * The customer is `e2e-customer@intechral.test` (role `user`, no operator capability), so every
 * page below is rendered in the Focused presentation: a 60px top bar, one centred column, no rail,
 * drawer, utility bar, breadcrumb or global timer. The routes cover both renderers on purpose:
 * `/dashboard`, `/profile`, `/projects` and (EPIC-017 WP2) `/tickets` are React, while `/my/invoices` and
 * `/pages` are Blade, and the Blade frame must be the same product.
 *
 * Signing out is owned by `auth-migration.spec.ts`: sessions are database-backed, so it destroys the
 * session this persona shares across the worker's tests.
 */

test.use({ persona: 'customer' });
// The one journey that creates data uses the cleanup-enabled test; the shell checks create none.
testWithCleanup.use({ persona: 'customer' });

const WIDTHS = [
    { name: '1280', width: 1280, height: 900 },
    { name: '768', width: 768, height: 900 },
    { name: '390', width: 390, height: 844 },
] as const;

const THEMES: Theme[] = ['light', 'dark'];

/** Route, renderer and the nav entry the server marks current (none for Profile). */
const ROUTES = [
    { path: '/dashboard', renderer: 'react', current: 'Home' },
    { path: '/profile', renderer: 'react', current: null },
    { path: '/projects', renderer: 'react', current: 'Projects' },
    { path: '/tickets', renderer: 'react', current: 'Support' },
    { path: '/my/invoices', renderer: 'blade', current: 'Invoices' },
    { path: '/pages', renderer: 'blade', current: 'Resources' },
] as const;

const LABELS = ['Home', 'Support', 'Projects', 'Invoices', 'Resources'];

const focusedShell = (page: Page) => page.locator('[data-shell="focused"]');
const topNav = (page: Page) => page.getByRole('navigation', { name: 'Main' });
const sheetTrigger = (page: Page) => page.getByRole('button', { name: 'Open navigation' });

async function setTheme(page: Page, theme: Theme, label: string) {
    await page.evaluate((value) => {
        localStorage.setItem('theme', value);
        document.documentElement.dataset.theme = value;
    }, theme);
    await settleTheme(page, theme, label);
}

async function box(page: Page, selector: string) {
    return page.locator(selector).first().boundingBox();
}

/** The computed colour of `--ds-accent-line`, for comparing against a painted border. */
function accentLine(page: Page) {
    return page.evaluate(() => {
        const probe = document.createElement('i');
        document.body.appendChild(probe);
        probe.style.color = 'var(--ds-accent-line)';
        const value = getComputedStyle(probe).color;
        probe.remove();

        return value;
    });
}

test('a customer signs in to the Focused Home: a top bar, one h1, the five customer entries', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page, '/dashboard');

    await expect(focusedShell(page)).toHaveCount(1);
    await expect(page.locator('[data-shell="operational"]')).toHaveCount(0);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Home');
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(topNav(page).getByRole('link')).toHaveText(LABELS);
    await expect(topNav(page).locator('[aria-current="page"]')).toHaveText('Home');
    await expect(page.getByRole('link', { name: 'New request' })).toBeVisible();

    // Tasks and Time are not customer navigation (O1), but their routes stay reachable.
    await expect(page.getByRole('link', { name: /^(Tasks|Time)$/ })).toHaveCount(0);
});

test('the Home reads in the customer order and shows the seeded request that is waiting on this customer', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page, '/dashboard');

    await expect(page.getByRole('heading', { level: 2 })).toHaveText([
        'Waiting on you',
        'Your open requests',
        'Invoices',
        'Your projects',
    ]);

    // `TKT-E2E2` (DevSeeder) is owned by this customer and waiting on them.
    const waiting = page.getByRole('region', { name: 'Waiting on you' });
    const row = waiting.getByRole('link', { name: /E2E fixture: waiting on the customer/ });

    await expect(row).toBeVisible();
    await expect(row).toContainText('TKT-E2E2');
    await expect(row).toHaveAttribute('href', /\/tickets\/\d+$/);

    // Nothing operational: no priority, SLA, queue or requester vocabulary on a customer page.
    await expect(page.getByRole('main')).not.toContainText(
        /\b(SLA|queue|overdue|requester|priority)\b/i,
    );
});

for (const route of ROUTES) {
    test(`${route.path} (${route.renderer}) holds the Focused frame at 390, 768 and 1280, in both themes`, async ({
        page,
    }) => {
        await signedIn(page, route.path);

        for (const theme of THEMES) {
            for (const size of WIDTHS) {
                const label = `${route.path} ${size.name} ${theme}`;

                await page.setViewportSize({ width: size.width, height: size.height });
                await page.goto(route.path);
                await setTheme(page, theme, label);

                await expect(focusedShell(page), label).toHaveCount(1);

                // Never an operator frame, in either renderer.
                for (const hook of [
                    '[data-shell-rail]',
                    '[data-shell-drawer]',
                    '[data-shell-utility]',
                    '[data-shell-timer]',
                    '[data-shell="operational"]',
                ]) {
                    await expect(page.locator(hook), `${label} ${hook}`).toHaveCount(0);
                }

                await expect(
                    page.getByRole('navigation', { name: 'Breadcrumb' }),
                    label,
                ).toHaveCount(0);

                expect(await hasHorizontalOverflow(page), `${label}: no document overflow`).toBe(
                    false,
                );

                // Geometry: a 60px bar and a centred column of at most 1080px.
                expect(
                    (await box(page, '[data-shell-topbar]'))?.height,
                    `${label}: bar`,
                ).toBeCloseTo(60, 0);
                const column = await box(page, '[data-shell-column]');
                expect(column!.width, `${label}: column`).toBeLessThanOrEqual(1080.5);

                if (size.width >= 1100) {
                    expect(column!.width, `${label}: column is 1080 wide`).toBeCloseTo(1080, 0);
                    expect(column!.x + column!.width / 2, `${label}: centred`).toBeCloseTo(
                        size.width / 2,
                        0,
                    );
                }

                // The account control is always reachable, and is the 34px circle.
                const trigger = accountTrigger(page);
                await expect(trigger, label).toBeVisible();
                const triggerBox = await trigger.boundingBox();
                expect(triggerBox!.width, `${label}: 34px trigger`).toBeCloseTo(34, 0);
                expect(
                    triggerBox!.x + triggerBox!.width,
                    `${label}: inside the viewport`,
                ).toBeLessThanOrEqual(size.width);

                const desktop = size.width >= 900;
                expect(await topNav(page).count(), label).toBe(desktop ? 1 : 0);
                await expect(sheetTrigger(page), label)[desktop ? 'toBeHidden' : 'toBeVisible']();

                if (desktop) {
                    const links = topNav(page).getByRole('link');
                    expect(
                        await links.count(),
                        `${label}: at most five entries`,
                    ).toBeLessThanOrEqual(5);
                    await expect(links, label).toHaveText(LABELS);

                    if (route.current) {
                        const current = topNav(page).locator('[aria-current="page"]');
                        await expect(current, label).toHaveText(route.current);
                        // The 2px accent-line underline resolves to the theme's token.
                        const underline = await current.evaluate((node) => {
                            const style = getComputedStyle(node);

                            return {
                                width: style.borderBottomWidth,
                                color: style.borderBottomColor,
                            };
                        });
                        expect(underline.width, `${label}: underline`).toBe('2px');
                        expect(underline.color, `${label}: underline colour`).toBe(
                            await accentLine(page),
                        );
                    } else {
                        await expect(
                            topNav(page).locator('[aria-current="page"]'),
                            label,
                        ).toHaveCount(0);
                    }
                } else {
                    await sheetTrigger(page).click();
                    const sheet = page.getByRole('dialog', { name: 'Navigation' });
                    await expect(sheet, label).toBeVisible();
                    await expect(sheet.getByRole('link'), label).toHaveText(LABELS);
                    // The sheet never grows past its own width rule.
                    expect(
                        (await sheet.boundingBox())!.width,
                        `${label}: sheet`,
                    ).toBeLessThanOrEqual(Math.min(320, size.width * 0.88) + 1);

                    if (route.current) {
                        await expect(sheet.locator('[aria-current="page"]'), label).toHaveText(
                            route.current,
                        );
                    }

                    await page.keyboard.press('Escape');
                    await expect(sheet, label).toBeHidden();
                }
            }
        }
    });
}

// One React page and one Blade page: the sheet must behave the same in both renderers. (`/tickets` was the
// Blade leg until EPIC-017 WP2 made it React; `/my/invoices` is the Blade leg now.)
for (const route of ['/dashboard', '/my/invoices'] as const) {
    test(`the menu sheet is keyboard-complete on ${route}: opens, traps focus, closes on Esc and returns focus`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await signedIn(page, route);

        const trigger = sheetTrigger(page);
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');

        await trigger.focus();
        await page.keyboard.press('Enter');

        const sheet = page.getByRole('dialog', { name: 'Navigation' });
        await expect(sheet).toBeVisible();
        await expect(page.locator('[data-shell-sheet-trigger]')).toHaveAttribute(
            'aria-expanded',
            'true',
        );

        // Focus never lands on the page behind the sheet, whichever renderer drew it. Radix loops it
        // strictly inside the sheet; a native modal <dialog> may also cycle through the browser's own
        // chrome (where the document's active element is the body), but the inert page is unreachable.
        for (let step = 0; step < 10; step++) {
            await page.keyboard.press('Tab');
            expect(
                await page.evaluate(() => {
                    const active = document.activeElement;

                    return (
                        !active ||
                        active === document.body ||
                        active === document.documentElement ||
                        !!active.closest('[data-shell-sheet]')
                    );
                }),
                `tab ${step + 1} never reaches the page behind the sheet`,
            ).toBe(true);
        }

        await page.keyboard.press('Escape');
        await expect(sheet).toBeHidden();
        await expect(trigger).toBeFocused();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });
}

test('the menu sheet honours reduced motion: a short opacity fade, no travel', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.setViewportSize({ width: 390, height: 844 });
    await signedIn(page, '/dashboard');

    await sheetTrigger(page).click();
    const sheet = page.locator('[data-shell-sheet="focused"]');
    await expect(sheet).toBeVisible();

    const motion = await sheet.evaluate((node) => {
        const style = getComputedStyle(node);

        return { duration: parseFloat(style.animationDuration), name: style.animationName };
    });

    // `motion-fast` is 120ms; the fade keyframes move opacity only.
    expect(motion.duration).toBeLessThanOrEqual(0.12);
    expect(motion.name).toMatch(/fade/);
});

test('keyboard: the skip link is first, focus is visible, and a visit is announced', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page, '/dashboard');

    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Skip to content' })).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();

    // A focused nav link draws the 2px focus outline.
    await topNav(page).getByRole('link', { name: 'Support' }).focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    const outline = await topNav(page)
        .getByRole('link', { name: 'Support' })
        .evaluate((node) => ({
            width: getComputedStyle(node).outlineWidth,
            style: getComputedStyle(node).outlineStyle,
        }));
    expect(outline).toEqual({ width: '2px', style: 'solid' });

    // An Inertia visit to Projects is announced politely with the new page's heading.
    await topNav(page).getByRole('link', { name: 'Projects' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.locator('[data-shell-announcer]')).toHaveAttribute('aria-live', 'polite');
    await expect(page.locator('[data-shell-announcer]')).toHaveText('Projects');
});

for (const path of ['/dashboard', '/my/invoices']) {
    test(`the account menu on ${path} opens below the avatar, is keyboard-operable and reaches the profile anchors`, async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await signedIn(page, path);

        // Located by its hook: while the menu is open Radix hides the rest of the page, the trigger
        // included, from role queries.
        const trigger = page.locator('[data-shell-account]');
        await expect(accountTrigger(page)).toHaveAttribute('aria-expanded', 'false');

        await trigger.focus();
        await page.keyboard.press('Enter');

        const menu = page.getByRole('menu');
        await expect(menu).toBeVisible();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');

        // Below the trigger, right-aligned to it.
        const triggerBox = (await trigger.boundingBox())!;
        const menuBox = (await menu.boundingBox())!;
        expect(menuBox.y).toBeGreaterThanOrEqual(triggerBox.y + triggerBox.height - 1);
        expect(menuBox.x + menuBox.width).toBeCloseTo(triggerBox.x + triggerBox.width, -1);

        // Personal items only: nothing administrative.
        await expect(
            menu.getByRole('menuitem', { name: /admin|users|roles|settings|shortcut/i }),
        ).toHaveCount(0);

        await page.keyboard.press('Escape');
        await expect(menu).toBeHidden();
        await expect(trigger).toBeFocused();

        // Sessions lands on the section it names, clear of the sticky bar.
        await openAccountMenu(page);
        await page.getByRole('menuitem', { name: 'Sessions' }).click();
        await expect(page).toHaveURL(/\/profile#sessions$/);
        const section = page.locator('#sessions');
        await expect(section).toBeVisible();
        await expect(section.getByRole('heading', { name: 'Browser sessions' })).toBeInViewport();
        await expect.poll(async () => (await section.boundingBox())!.y).toBeGreaterThanOrEqual(60);
    });
}

test('every account-menu anchor names a section on the profile page', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page, '/profile');

    for (const [id, heading] of [
        ['security', 'Password'],
        ['connected-accounts', 'Connected accounts'],
        ['sessions', 'Browser sessions'],
    ] as const) {
        await expect(
            page.locator(`section#${id}`).getByRole('heading', { name: heading }),
        ).toBeVisible();
    }
});

test('the Blade frame and the React shell are the same customer product (parity)', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });

    const read = async (path: string) => {
        await signedIn(page, path);

        return page.evaluate(() => ({
            labels: Array.from(document.querySelectorAll('[data-shell-topnav] a')).map((a) =>
                a.textContent?.trim(),
            ),
            hrefs: Array.from(document.querySelectorAll('[data-shell-topnav] a')).map(
                (a) => new URL((a as HTMLAnchorElement).href).pathname,
            ),
            account: document.querySelector('[data-shell-account]')?.getAttribute('aria-label'),
            initials: document.querySelector('[data-shell-account]')?.textContent?.trim(),
            theme: document.documentElement.dataset.theme,
            brand: document.querySelectorAll('[data-shell-brand] svg').length,
            banner: document.querySelectorAll('header[data-shell-topbar]').length,
        }));
    };

    const react = await read('/dashboard');
    const blade = await read('/my/invoices');

    await expect(page.locator('#app')).toHaveCount(0);
    expect(blade).toEqual(react);
    expect(react.labels).toEqual(LABELS);

    // Same bar geometry and canvas on both.
    const geometry = async () => ({
        bar: (await box(page, '[data-shell-topbar]'))?.height,
        column: (await box(page, '[data-shell-column]'))?.width,
        background: await page.evaluate(
            () =>
                getComputedStyle(document.querySelector('[data-shell="focused"]')!).backgroundColor,
        ),
    });

    await signedIn(page, '/dashboard');
    const reactGeometry = await geometry();
    await signedIn(page, '/my/invoices');

    expect(await geometry()).toEqual(reactGeometry);
});

test('a customer who reaches an operator route is refused inside the customer frame', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await signedIn(page);

    const response = await page.goto('/operator/tickets');

    expect(response?.status()).toBe(403);
    await expect(focusedShell(page)).toHaveCount(1);
    await expect(page.locator('[data-shell-rail]')).toHaveCount(0);

    // Hidden navigation is not authorization: the routes the nav omits stay reachable by URL.
    expect((await page.goto('/tasks'))?.status()).toBe(200);
    await expect(focusedShell(page)).toHaveCount(1);
    expect((await page.goto('/time'))?.status()).toBe(200);
    await expect(focusedShell(page)).toHaveCount(1);
    await expect(page.locator('[data-shell-timer]')).toHaveCount(0);
});

/**
 * F1 (preservation): the Focused shell draws no global timer, yet `/time` can start one. The page
 * carries its own Stop, so a customer can finish what they start. This is general time on `/time`
 * (no ticket context); ticket time stays an operator matter in the next package.
 *
 * The cleanup fixture records the entry from the start response and deletes it afterwards. A failed
 * assertion must not leave the timer running either, so the `finally` stops whatever is still active
 * through the application's own endpoints.
 */
testWithCleanup(
    'on /time a customer can start a timer and stop it from the page, with no global timer',
    async ({ page }) => {
        const description = `E2E-${Date.now().toString(36)} customer timer`;

        await page.setViewportSize({ width: 1280, height: 900 });
        await signedIn(page, '/time');

        try {
            await expect(page.locator('[data-shell-timer]')).toHaveCount(0);
            await expect(page.getByText('Running timers')).toHaveCount(0);

            await page.locator('#timer-description').fill(description);
            await page.getByRole('button', { name: 'Start timer', exact: true }).click();

            await expect(page.getByRole('heading', { name: 'Running timers' })).toBeVisible();
            const stop = page.getByRole('button', { name: `Stop timer: ${description}` });
            await expect(stop).toBeVisible();
            await expect(page.locator('[data-shell-timer]')).toHaveCount(0);

            await stop.click();

            await expect(page.getByRole('heading', { name: 'Running timers' })).toHaveCount(0);
            // The entry it became is in the list without a reload by hand.
            await expect(page.getByRole('row').filter({ hasText: description })).toBeVisible();
        } finally {
            await stopAnyActiveTimer(page);
        }
    },
);

/** Stops every active timer for this session through the application's endpoints, whatever the UI state. */
async function stopAnyActiveTimer(page: Page) {
    if (!/\/time$/.test(page.url())) {
        await page.goto('/time');
    }

    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': token ?? '',
    };
    const active = await page.request.get('/time/timers/active', { headers });

    if (!active.ok()) {
        return;
    }

    for (const timer of (await active.json()) as { id: number }[]) {
        await page.request.post(`/time/timer/${timer.id}/stop`, { headers });
    }
}
