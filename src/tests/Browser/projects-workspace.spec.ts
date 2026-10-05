import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-015 WP4 critical flows: the Direction D projects index and the remaining project workspace
 * pages (Board, Milestones, Settings, create) on the shared project header, in a real browser.
 *
 * The index lists rows, not cards: the name opens the Overview, lifecycle and derived health are
 * separate text, and the lifecycle-status filter lives in the URL. A customer member sees the same
 * workspace pages with no Settings, no management control and no create or edit route. Every page
 * keeps one h1 and one breadcrumb, and holds together at 390px, 768px and 1440px, light and dark.
 *
 * DTO, authorization and query permutations are Pest's (ProjectInertiaPagesTest,
 * ProjectMilestoneInertiaTest, ProjectQueryBudgetTest) and Vitest's. Runs against the shared
 * development database: every project is registered with `cleanup.trackProject` as soon as its id
 * is known, and its milestones and tasks cascade with it.
 */

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    // The member persona joins, so a customer member's view can be checked.
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)$/)![1]);
    cleanup.trackProject(projectId);

    return projectId;
}

/** A same-origin form request from the page, carrying its CSRF token. */
async function send(page: Page, method: string, url: string, body: Record<string, unknown>) {
    return page.evaluate(
        async ({ method, url, body }) => {
            const token =
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify(body),
            });

            return response.status;
        },
        { method, url, body },
    );
}

function indexRow(page: Page, name: string) {
    return page
        .getByRole('table', { name: 'Projects' })
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name, exact: true }) });
}

async function expectOneBreadcrumbAndHeading(page: Page, at = '') {
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' }), at).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 }), at).toHaveCount(1);
}

test('the index lists rows with lifecycle and health apart, opens the Overview, and filters by status in the URL', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const name = `E2E WP4 index project ${Date.now()}`;
    const projectId = await createProject(page, cleanup, name);
    // An overdue, never-completed milestone makes the project Off track (count-only on the index).
    expect(
        await send(page, 'POST', `/projects/${projectId}/milestones`, {
            name: 'E2E WP4 late checkpoint',
            due_date: '2020-01-15',
        }),
    ).toBeLessThan(400);

    await page.goto('/projects?status=active');
    await expectOneBreadcrumbAndHeading(page);
    await expect(page.getByRole('combobox', { name: 'Status' })).toHaveValue('active');
    const row = indexRow(page, name);
    const cells = row.getByRole('cell');
    await expect(cells.nth(1)).toHaveText('Active');
    await expect(cells.nth(2)).toContainText('Off track');
    await expect(cells.nth(2)).toContainText('1 milestone overdue');
    await expect(cells.nth(3)).toContainText('No tasks');
    await expect(row.getByRole('link', { name, exact: true })).toHaveAttribute(
        'href',
        new RegExp(`/projects/${projectId}$`),
    );

    // Put it on hold: the filter narrows in the URL, and history restores each state.
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByLabel('Status').selectOption({ label: 'On hold' });
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('status')).toContainText('Project updated.');

    await page.goto('/projects?status=active');
    await expect(indexRow(page, name)).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Status' }).selectOption({ label: 'On hold' });
    await expect(page).toHaveURL(/\/projects\?status=on_hold$/);
    await expect(indexRow(page, name).getByRole('cell').nth(1)).toHaveText('On hold');
    // On hold has no derived health: the lifecycle status carries the meaning.
    await expect(indexRow(page, name).getByRole('cell').nth(2)).toContainText(
        'No health while not active',
    );
    await expect(indexRow(page, name).getByRole('cell').nth(2)).not.toContainText(/track|risk/i);
    await page.goBack();
    await expect(page).toHaveURL(/\/projects\?status=active$/);
    await expect(page.getByRole('combobox', { name: 'Status' })).toHaveValue('active');
    await expect(indexRow(page, name)).toHaveCount(0);
    await page.goForward();
    await expect(page.getByRole('button', { name: /Status: On hold/ })).toBeVisible();
    await page.getByRole('button', { name: /Status: On hold/ }).click();
    await expect(page).toHaveURL(/\/projects$/);
    await expect(page.getByRole('combobox', { name: 'Status' })).toHaveValue('');

    // The name opens the Overview, the project's canonical page.
    await page.goto(`/projects?status=on_hold`);
    await indexRow(page, name).getByRole('link', { name, exact: true }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
});

test('a customer member sees every project page without Settings, management controls or create/edit routes', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const name = `E2E WP4 customer project ${Date.now()}`;
    const projectId = await createProject(page, cleanup, name);
    expect(
        await send(page, 'POST', `/projects/${projectId}/milestones`, {
            name: 'E2E WP4 member-visible milestone',
            due_date: '2030-01-15',
        }),
    ).toBeLessThan(400);

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto('/projects');
        await expect(indexRow(memberPage, name)).toHaveCount(1);
        await expect(
            memberPage.getByRole('main').getByRole('link', { name: 'New project' }),
        ).toHaveCount(0);

        for (const [path, current] of [
            [`/projects/${projectId}`, 'Overview'],
            [`/projects/${projectId}/board`, 'Board'],
            [`/projects/${projectId}/tasks`, 'Tasks'],
            [`/projects/${projectId}/milestones`, 'Milestones'],
        ] as const) {
            await memberPage.goto(path);
            const tabs = memberPage.getByRole('navigation', { name: 'Project', exact: true });
            await expect(tabs.getByRole('link'), path).toHaveText([
                'Overview',
                'Board',
                'Tasks',
                'Milestones',
            ]);
            await expect(tabs.locator('[aria-current="page"]'), path).toHaveText(current);
            await expect(
                memberPage.getByRole('main').getByRole('link', { name: 'Settings' }),
                path,
            ).toHaveCount(0);
            await expectOneBreadcrumbAndHeading(memberPage, path);
        }

        // Milestones read-only: the row and its explicit state, no completion or edit control.
        await expect(
            memberPage.getByText('E2E WP4 member-visible milestone').first(),
        ).toBeVisible();
        await expect(memberPage.getByRole('main').getByRole('button')).toHaveCount(0);

        // Hidden controls are not the authorization: the routes refuse too.
        expect((await memberPage.request.get(`/projects/${projectId}/edit`)).status()).toBe(403);
        expect((await memberPage.request.get('/projects/create')).status()).toBe(403);

        // The page props carry no Settings ability, budget, roster or email.
        const props = await memberPage.evaluate(() => {
            const script = document.querySelector('script[data-page]');
            const element = document.querySelector('[data-page]');
            const raw = script?.textContent ?? element?.getAttribute('data-page') ?? '{}';
            const page = JSON.parse(raw) as { props: Record<string, unknown> };
            delete page.props.auth;
            delete page.props.navigation;

            return JSON.stringify(page.props);
        });
        expect(props).not.toContain('"manage":true');
        expect(props).not.toContain('openSettings');
        expect(props).not.toContain('@intechral.test');
    } finally {
        await memberContext.close();
    }
});

test('every WP4 page keeps one h1 and one breadcrumb, reachable tabs and actions, and no document overflow at 390, 768 and 1440, light and dark', async ({
    page,
    cleanup,
}) => {
    test.slow(); // 3 widths × 2 themes × 5 pages, after a project seeded through the real UI.
    await signedIn(page);
    const name = 'E2E WP4 responsive project with a deliberately long name for phones';
    const projectId = await createProject(page, cleanup, name);
    expect(
        await send(page, 'POST', `/projects/${projectId}/milestones`, {
            name: 'E2E WP4 responsive milestone with a long name that has to wrap',
            due_date: '2020-01-15',
        }),
    ).toBeLessThan(400);

    const pages = [
        { path: '/projects', tabs: false },
        { path: `/projects/${projectId}/board`, tabs: true },
        { path: `/projects/${projectId}/milestones`, tabs: true },
        { path: `/projects/${projectId}/edit`, tabs: false },
        { path: '/projects/create', tabs: false },
    ];

    for (const width of [390, 768, 1440]) {
        for (const theme of ['light', 'dark'] as const) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/projects');
            await page.evaluate((value) => localStorage.setItem('theme', value), theme);

            for (const { path, tabs } of pages) {
                await page.goto(path);
                const at = `${path} ${width}px ${theme}`;
                await expect(page.locator('html'), at).toHaveAttribute('data-theme', theme);
                await expectOneBreadcrumbAndHeading(page, at);
                expect(await hasHorizontalOverflow(page), `${at} document overflow`).toBe(false);
                await expect(page.getByRole('tablist'), at).toHaveCount(0);

                if (tabs) {
                    const nav = page.getByRole('navigation', { name: 'Project', exact: true });
                    for (const label of ['Overview', 'Board', 'Tasks', 'Milestones']) {
                        const link = nav.getByRole('link', { name: label });
                        await link.scrollIntoViewIfNeeded();
                        await expect(link, `${at} ${label}`).toBeInViewport();
                    }
                    const settings = page.getByRole('main').getByRole('link', { name: 'Settings' });
                    await settings.scrollIntoViewIfNeeded();
                    await expect(settings, `${at} Settings`).toBeInViewport();
                }
            }

            // The index stays a scannable table: at S each row is two bands (name, then status,
            // health and progress), a table row above it.
            await page.goto('/projects');
            const row = indexRow(page, name);
            await expect(row).toBeVisible();
            const display = await row.evaluate((node) => getComputedStyle(node).display);
            if (width === 390) {
                expect(display, 'index row reflows at S').toBe('flex');
                const height = await row.evaluate((node) => node.getBoundingClientRect().height);
                expect(height, 'index row is two bands at S').toBeLessThanOrEqual(96);
            } else {
                expect(display, `index row at ${width}px`).toBe('table-row');
            }

            // Milestones: the row's actions fit, and the focus ring on Complete is visible.
            await page.goto(`/projects/${projectId}/milestones`);
            const complete = page.getByRole('button', { name: /^Complete E2E WP4 responsive/ });
            await complete.scrollIntoViewIfNeeded();
            await expect(complete).toBeInViewport();
            await complete.focus();
            await expect(complete).toBeFocused();
            expect(
                await complete.evaluate((node) => getComputedStyle(node).outlineStyle),
                `${width}px ${theme} focus outline`,
            ).not.toBe('none');
        }
    }

    await page.evaluate(() => localStorage.removeItem('theme'));
});
