import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-015 WP5 (optional S2): Direction D §5.3 per-surface drawer defaults in the Projects workspace.
 *
 * At XL the Projects list, Overview and Milestones keep the workspace default (open); Board, Tasks
 * and Time start collapsed. A choice made on a surface is remembered for that surface only, under a
 * semantic key (`projects.board`), never per project; the workspace's own choice is untouched by it.
 * Below XL the remembered state does not dock anything unless pinned (L), and M/S stay overlay/sheet.
 * The state is resolved before first paint, so a page never paints one layout and then another.
 *
 * Server hints are pinned in Pest (ProjectDrawerSurfaceTest); resolution parity in Vitest
 * (use-panel-state, shell/bootstrap). Every project is registered with `cleanup.trackProject`.
 */

const XL = { width: 1440, height: 900 };
const L = { width: 1200, height: 900 };
const M = { width: 900, height: 900 };
const S = { width: 390, height: 844 };

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)$/)![1]);
    cleanup.trackProject(projectId);

    return projectId;
}

function drawer(page: Page) {
    return page.getByRole('navigation', { name: 'Projects views' });
}

function tab(page: Page, label: string) {
    return page.getByRole('navigation', { name: 'Project', exact: true }).getByRole('link', {
        name: label,
    });
}

async function stored(page: Page) {
    return page.evaluate(
        () =>
            JSON.parse(localStorage.getItem('shell.operational.panel') ?? '{}') as Record<
                string,
                string
            >,
    );
}

async function canvasX(page: Page) {
    return Math.round((await page.locator('[data-shell-canvas]').boundingBox())!.x);
}

test('each project surface starts in its Direction D default at XL', async ({ page, cleanup }) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer defaults project');
    await page.evaluate(() => localStorage.removeItem('shell.operational.panel'));

    for (const [path, open] of [
        ['/projects', true],
        [`/projects/${projectId}`, true],
        [`/projects/${projectId}/milestones`, true],
        [`/projects/${projectId}/board`, false],
        [`/projects/${projectId}/tasks`, false],
        [`/projects/${projectId}/time`, false],
    ] as const) {
        await page.goto(path);
        await expect(page.locator('html'), path).toHaveAttribute(
            'data-drawer',
            open ? 'open' : 'collapsed',
        );
        if (open) {
            await expect(drawer(page), path).toBeVisible();
            expect(await canvasX(page), path).toBe(64 + 248);
        } else {
            await expect(drawer(page), path).toHaveCount(0);
            await expect(
                page.getByRole('button', { name: 'Show workspace views' }),
                path,
            ).toBeVisible();
            expect(await canvasX(page), path).toBe(64);
        }
    }

    // Visiting surfaces wrote nothing: defaults are not preferences.
    expect(await stored(page)).toEqual({});
});

test('a choice on a surface is remembered for that surface only, across projects and reloads', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const first = await createProject(page, cleanup, 'E2E WP5 drawer override project');
    const second = await createProject(page, cleanup, 'E2E WP5 drawer second project');
    await page.evaluate(() => localStorage.removeItem('shell.operational.panel'));

    // Open it on the Board.
    await page.goto(`/projects/${first}/board`);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await expect(drawer(page)).toBeVisible();
    expect(await stored(page)).toEqual({ 'projects.board': 'open' });

    // Tasks keeps its own default; the Board's choice did not leak to it (in-app navigation).
    await tab(page, 'Tasks').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${first}/tasks$`));
    await expect(drawer(page)).toHaveCount(0);

    // Back on the Board, the remembered choice applies.
    await tab(page, 'Board').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${first}/board$`));
    await expect(drawer(page)).toBeVisible();

    // Collapse it on the Overview: a workspace choice, which the Board does not inherit. Each
    // drawer assertion waits for the visit to land first: the Board's own open drawer would
    // otherwise satisfy it before the Overview renders.
    await tab(page, 'Overview').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${first}$`));
    await expect(page.getByRole('heading', { level: 2, name: 'Health' })).toBeVisible();
    await expect(drawer(page)).toBeVisible();
    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(drawer(page)).toHaveCount(0);
    await tab(page, 'Milestones').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${first}/milestones$`));
    await expect(page.getByRole('button', { name: 'New milestone' })).toBeVisible();
    await expect(drawer(page)).toHaveCount(0);
    await tab(page, 'Board').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${first}/board$`));
    await expect(drawer(page)).toBeVisible();

    // One Board preference for every project, with no project id in any key.
    await page.goto(`/projects/${second}/board`);
    await expect(drawer(page)).toBeVisible();
    const keys = Object.keys(await stored(page));
    expect(keys.sort()).toEqual(['projects', 'projects.board']);
    expect(keys.some((key) => /\d/.test(key))).toBe(false);

    // Ctrl+\ on the Board flips and remembers the Board's choice only.
    await page.locator('#main-content').focus();
    await page.keyboard.press('Control+Backslash');
    await expect(drawer(page)).toHaveCount(0);
    expect(await stored(page)).toEqual({ projects: 'collapsed', 'projects.board': 'collapsed' });
});

test('the remembered surface state is resolved before first paint, with no layout shift', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer paint project');

    for (const [boardState, expectedX] of [
        ['open', 64 + 248],
        ['collapsed', 64],
    ] as const) {
        await page.evaluate(
            (state) =>
                localStorage.setItem(
                    'shell.operational.panel',
                    // The workspace says the opposite, so reading the wrong key would show.
                    JSON.stringify({
                        projects: state === 'open' ? 'collapsed' : 'open',
                        'projects.board': state,
                    }),
                ),
            boardState,
        );
        await page.addInitScript(() => {
            const probe = { drawer: [] as string[], canvasX: [] as number[] };
            (window as unknown as { __probe: typeof probe }).__probe = probe;
            const frame = () => {
                probe.drawer.push(document.documentElement.dataset.drawer ?? '');
                const canvas = document.querySelector('[data-shell-canvas]');
                if (canvas) probe.canvasX.push(Math.round(canvas.getBoundingClientRect().x));
                if (probe.canvasX.length < 10) requestAnimationFrame(frame);
            };
            requestAnimationFrame(frame);
        });

        await page.goto(`/projects/${projectId}/board`);
        await expect
            .poll(() =>
                page.evaluate(
                    () =>
                        (window as unknown as { __probe: { canvasX: number[] } }).__probe.canvasX
                            .length,
                ),
            )
            .toBeGreaterThanOrEqual(10);
        const probe = await page.evaluate(
            () =>
                (window as unknown as { __probe: { drawer: string[]; canvasX: number[] } }).__probe,
        );

        // Every frame, from the first one the page painted, already agrees.
        expect(new Set(probe.drawer), boardState).toEqual(new Set([boardState]));
        expect(new Set(probe.canvasX), boardState).toEqual(new Set([expectedX]));
    }
});

test('below XL the surface preference never docks the drawer; M and S stay overlay and sheet', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer narrow project');
    await page.evaluate(() =>
        localStorage.setItem(
            'shell.operational.panel',
            JSON.stringify({ projects: 'open', 'projects.board': 'open' }),
        ),
    );

    // L: collapsed whatever is remembered, opens as an overlay over the canvas.
    await page.setViewportSize(L);
    await page.goto(`/projects/${projectId}/board`);
    await expect(drawer(page)).toHaveCount(0);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await expect(drawer(page)).toBeVisible();
    expect(await canvasX(page)).toBe(64);
    await page.keyboard.press('Escape');
    await expect(drawer(page)).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Show workspace views' })).toBeFocused();
    // Using the overlay is transient: the XL preference seeded above is exactly as it was.
    expect(await stored(page)).toEqual({ projects: 'open', 'projects.board': 'open' });

    // M: overlay only, no pin.
    await page.setViewportSize(M);
    await page.goto(`/projects/${projectId}/time`);
    await expect(drawer(page)).toHaveCount(0);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await expect(drawer(page)).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pin workspace views' })).toBeHidden();
    expect(await canvasX(page)).toBe(64);
    expect(await hasHorizontalOverflow(page)).toBe(false);
    await page.keyboard.press('Escape');
    await expect(drawer(page)).toHaveCount(0);
    expect(await stored(page)).toEqual({ projects: 'open', 'projects.board': 'open' });

    // S: no drawer at all, no overflow; the board is still the page.
    await page.setViewportSize(S);
    await page.goto(`/projects/${projectId}/board`);
    await expect(page.locator('[data-shell-drawer]')).toHaveCount(0);
    expect(await hasHorizontalOverflow(page)).toBe(false);
});

test('other workspaces keep their own defaults and remembered state', async ({ page }) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    await page.evaluate(() =>
        localStorage.setItem(
            'shell.operational.panel',
            JSON.stringify({ 'projects.board': 'open', 'projects.tasks': 'open' }),
        ),
    );

    // The global Tasks workspace is not the project Tasks surface: its own default, collapsed.
    await page.goto('/tasks');
    await expect(page.getByRole('navigation', { name: 'Tasks views' })).toHaveCount(0);
    await expect(page.locator('html')).not.toHaveAttribute('data-drawer-surface', /.+/);

    // Helpdesk (a Blade page) opens by default, as before.
    await page.goto('/tickets');
    await expect(page.getByRole('navigation', { name: 'Helpdesk views' })).toBeVisible();
});

async function pins(page: Page) {
    return page.evaluate(
        () =>
            JSON.parse(localStorage.getItem('shell.operational.pin') ?? '{}') as Record<
                string,
                boolean
            >,
    );
}

test('narrow-width drawer use never changes the remembered XL preference', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer transient project');
    const seed = { projects: 'collapsed', 'projects.board': 'open' };
    await page.evaluate((map) => {
        localStorage.setItem('shell.operational.panel', JSON.stringify(map));
        localStorage.removeItem('shell.operational.pin');
    }, seed);

    // L (overlay) and M (overlay only): open, close with the button, reopen, dismiss with Escape.
    for (const viewport of [L, M]) {
        await page.setViewportSize(viewport);
        await page.goto(`/projects/${projectId}/board`);
        await expect(drawer(page)).toHaveCount(0);
        await page.getByRole('button', { name: 'Show workspace views' }).click();
        await expect(drawer(page)).toBeVisible();
        await page.getByRole('button', { name: 'Collapse workspace views' }).click();
        await expect(drawer(page)).toHaveCount(0);
        await page.getByRole('button', { name: 'Show workspace views' }).click();
        await expect(drawer(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(drawer(page)).toHaveCount(0);
        expect(await stored(page), `${viewport.width}px`).toEqual(seed);
        expect(await pins(page), `${viewport.width}px`).toEqual({});
    }

    // Back at XL the Board's remembered choice is still what applies.
    await page.setViewportSize(XL);
    await page.goto(`/projects/${projectId}/board`);
    await expect(drawer(page)).toBeVisible();
    expect(await canvasX(page)).toBe(64 + 248);
});

test('the L pin is Projects-wide: it docks every surface, and collapsing the docked drawer unpins', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer pin project');
    // A Board choice remembered at XL (collapsed) must not undo the pin at L.
    await page.evaluate(() => {
        localStorage.setItem(
            'shell.operational.panel',
            JSON.stringify({ 'projects.board': 'collapsed' }),
        );
        localStorage.removeItem('shell.operational.pin');
    });

    await page.setViewportSize(L);
    await page.goto(`/projects/${projectId}`);
    await expect(drawer(page)).toHaveCount(0);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    await expect(drawer(page)).toBeVisible();
    expect(await canvasX(page)).toBe(64); // an overlay until pinned
    await page.getByRole('button', { name: 'Pin workspace views' }).click();
    await expect.poll(() => canvasX(page)).toBe(64 + 248);
    expect(await pins(page)).toEqual({ projects: true });

    // In-app navigation across surfaces (their defaults are collapsed): docked on each.
    for (const [label, path] of [
        ['Board', 'board'],
        ['Tasks', 'tasks'],
        ['Time', 'time'],
        ['Overview', ''],
    ] as const) {
        await tab(page, label).click();
        await expect(page).toHaveURL(
            new RegExp(`/projects/${projectId}${path ? `/${path}` : ''}$`),
        );
        await expect(drawer(page), label).toBeVisible();
        await expect.poll(() => canvasX(page), label).toBe(64 + 248);
    }

    // A full load on a surface is docked before paint too.
    await page.goto(`/projects/${projectId}/board`);
    await expect(page.locator('html')).toHaveAttribute('data-drawer', 'open');
    await expect(drawer(page)).toBeVisible();
    expect(await canvasX(page)).toBe(64 + 248);

    // Pinning at L wrote no XL value of its own.
    expect(await stored(page)).toEqual({ 'projects.board': 'collapsed' });

    // Collapsing the docked drawer releases the pin: every surface is an overlay again.
    await page.getByRole('button', { name: 'Collapse workspace views' }).click();
    await expect(drawer(page)).toHaveCount(0);
    expect(await pins(page)).toEqual({});
    await tab(page, 'Tasks').click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks$`));
    await expect(drawer(page)).toHaveCount(0);
    await page.reload();
    await expect(drawer(page)).toHaveCount(0);
    await page.getByRole('button', { name: 'Show workspace views' }).click();
    expect(await canvasX(page)).toBe(64);
});

/**
 * A probe that samples every painted frame across an Inertia visit: the drawer attribute the CSS reads,
 * the surface attribute the shell stamps with it, whether the drawer element is in the DOM, and where
 * the canvas sits. They come from one React commit, so a frame that disagrees with itself is a frame
 * where the layout was painted wrong (an effect correcting itself a frame late would show here).
 */
async function installFrameProbe(page: Page) {
    await page.evaluate(() => {
        type Frame = { surface: string | null; drawer: string; present: boolean; x: number };
        const w = window as unknown as { __frames: Frame[]; __stopProbe?: boolean };
        w.__frames = [];
        w.__stopProbe = false;
        const tick = () => {
            const canvas = document.querySelector('[data-shell-canvas]');
            w.__frames.push({
                surface: document.documentElement.getAttribute('data-drawer-surface'),
                drawer: document.documentElement.getAttribute('data-drawer') ?? '',
                present: document.querySelector('[data-shell-drawer]') !== null,
                x: canvas ? Math.round(canvas.getBoundingClientRect().x) : -1,
            });
            if (!w.__stopProbe) requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
    });
}

async function readFrames(page: Page) {
    return page.evaluate(() => {
        const w = window as unknown as {
            __stopProbe: boolean;
            __frames: { surface: string | null; drawer: string; present: boolean; x: number }[];
        };
        w.__stopProbe = true;

        return w.__frames;
    });
}

test('navigating between Project surfaces in-app never paints the wrong drawer layout', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 drawer navigation project');
    await page.evaluate(() => localStorage.removeItem('shell.operational.panel'));

    // Overview (open default) → Board (collapsed default), then back.
    for (const [from, to, toSurface] of [
        ['Overview', 'Board', 'projects.board'],
        ['Board', 'Overview', null],
    ] as const) {
        await page.goto(`/projects/${projectId}${from === 'Board' ? '/board' : ''}`);
        await expect(page.locator('html')).toHaveAttribute(
            'data-drawer',
            from === 'Board' ? 'collapsed' : 'open',
        );
        await installFrameProbe(page);

        await tab(page, to).click();
        await expect(page).toHaveURL(
            new RegExp(`/projects/${projectId}${to === 'Board' ? '/board' : ''}$`),
        );
        // Settled once the destination's surface is stamped and a few more frames have painted.
        await expect
            .poll(() =>
                page.evaluate((surface) => {
                    const frames = (window as unknown as { __frames: { surface: string | null }[] })
                        .__frames;

                    return frames.slice(-5).every((f) => f.surface === surface);
                }, toSurface),
            )
            .toBe(true);

        const frames = await readFrames(page);
        const open = (frame: (typeof frames)[number]) => frame.surface === null;

        expect(frames.some(open), `${from}→${to}: saw the Overview layout`).toBe(true);
        expect(
            frames.some((f) => !open(f)),
            `${from}→${to}: saw the Board layout`,
        ).toBe(true);
        for (const [index, frame] of frames.entries()) {
            // The Overview is open and docked; the Board is collapsed with no drawer: in every frame.
            const wantOpen = frame.surface === null;
            expect(frame, `${from}→${to}, frame ${index}`).toEqual({
                surface: frame.surface,
                drawer: wantOpen ? 'open' : 'collapsed',
                present: wantOpen,
                x: wantOpen ? 64 + 248 : 64,
            });
        }
    }
});
