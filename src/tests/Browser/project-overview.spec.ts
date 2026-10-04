import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-015 WP2 critical flows: the project Overview as the canonical `projects.show` page. Creation
 * lands on it; the project navigation (page links, not ARIA tabs) reaches the Board, Tasks (WP3) and
 * Milestones; generic project links open it; a customer
 * member's Overview carries nothing gated; and it holds together at 390px, the S/M boundary and on
 * desktop, light and dark. Every DTO and authorization permutation is covered by Pest
 * (ProjectOverviewPageTest, ProjectOverviewPresenterTest) and Vitest (show.test.tsx); this proves the
 * real page in a real browser. Runs against the shared development database: every project is
 * registered with `cleanup.trackProject` as soon as its id is known (milestones cascade with it, and
 * the one timer entry is recorded by the fixture from its start response).
 */

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
    options: { budget?: string; withMember?: boolean } = {},
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    if (options.budget) await page.getByLabel('Budget ($)').fill(options.budget);
    if (options.withMember) {
        await page.getByRole('button', { name: 'Add member' }).click();
        await page
            .getByRole('combobox', { name: 'Member 1', exact: true })
            .selectOption({ label: 'Dev User (user@intechral.test)' });
    }
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

            return { status: response.status, json: await response.json().catch(() => null) };
        },
        { method, url, body },
    );
}

function isoDay(offsetDays: number) {
    const day = new Date();
    day.setUTCDate(day.getUTCDate() + offsetDays);

    return day.toISOString().slice(0, 10);
}

function projectNav(page: Page) {
    return page.getByRole('navigation', { name: 'Project', exact: true });
}

async function expectProjectNavigation(
    page: Page,
    current: 'Overview' | 'Board' | 'Tasks' | 'Milestones',
) {
    const nav = projectNav(page);
    // FLIPPED IN EPIC-015 WP3: the final four, Tasks included (§11.3.1).
    await expect(nav.getByRole('link')).toHaveText(['Overview', 'Board', 'Tasks', 'Milestones']);
    await expect(nav.getByRole('link', { name: current })).toHaveAttribute('aria-current', 'page');
    await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
    // Page navigation, never the ARIA tab widget.
    await expect(page.getByRole('tablist')).toHaveCount(0);
    await expect(page.getByRole('tab')).toHaveCount(0);
}

async function expectOneBreadcrumbAndHeading(page: Page) {
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
}

test('creating a project lands on its Overview: one h1, one breadcrumb, the project navigation', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP2 new project');

    await expect(
        page.getByRole('status').filter({ hasText: 'Project created successfully.' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E WP2 new project' }),
    ).toBeVisible();
    await expectOneBreadcrumbAndHeading(page);
    await expectProjectNavigation(page, 'Overview');

    // The shell trail ends on the project; the page draws none of its own.
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs.locator('[aria-current="page"]')).toHaveText('E2E WP2 new project');
    await expect(
        page.getByRole('main').getByRole('navigation', { name: 'Breadcrumb' }),
    ).toHaveCount(0);

    // A new project reads as new, not broken.
    const main = page.getByRole('main');
    await expect(main.getByText('Not enough data')).toBeVisible();
    await expect(main.getByText('No tasks yet')).toBeVisible();
    await expect(main.getByText('No milestones yet')).toBeVisible();

    // The operator may open Settings; it is the server's ability, and the link works.
    await main.getByRole('link', { name: 'Settings' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/edit`);
    await page.getByRole('link', { name: 'Back to project' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
});

test('the Overview reaches the Board and Milestones, and each leads back to the Overview', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP2 navigation project');

    // Overview -> Board through the project navigation.
    await projectNav(page).getByRole('link', { name: 'Board' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/board`);
    await expect(page.getByRole('region', { name: 'Kanban board' })).toBeVisible();
    await expectOneBreadcrumbAndHeading(page);

    // A task on the board shows up in the Overview's summary, whose counts open the project's Tasks
    // tab (WP3; WP2 sent them to the board).
    await page.getByRole('button', { name: 'Add task to Backlog' }).click();
    await page.getByLabel('New task title').fill('E2E WP2 task');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByRole('link', { name: 'E2E WP2 task' })).toBeVisible();

    // Board -> Overview through the shell breadcrumb's project segment.
    await page
        .getByRole('navigation', { name: 'Breadcrumb' })
        .getByRole('link', { name: 'E2E WP2 navigation project' })
        .click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
    const tasks = page.getByRole('main').getByRole('region', { name: 'Tasks' });
    await expect(tasks.getByText('0 of 1 task complete')).toBeVisible();
    await expect(tasks.getByRole('progressbar', { name: 'Task progress' })).toHaveAttribute(
        'aria-valuemax',
        '1',
    );
    await tasks.getByRole('link', { name: '1 open task: view in Tasks' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks?completion=open`);
    await expect(page.getByRole('link', { name: 'E2E WP2 task' })).toBeVisible();

    // Overview -> Milestones, and back through the page's own (generic) back link.
    await page.goto(`/projects/${projectId}`);
    await projectNav(page).getByRole('link', { name: 'Milestones' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/milestones`);
    await page.getByRole('link', { name: 'Back to project' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
    await expectProjectNavigation(page, 'Overview');
});

test('opening a project from the projects index goes to its Overview', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP2 index project');

    await page.goto('/projects');
    await page.getByRole('link', { name: 'E2E WP2 index project' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E WP2 index project' }),
    ).toBeVisible();
});

test('a customer member sees a safe Overview; the manager sees budget, people and Settings', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP2 customer project', {
        budget: '4321',
        withMember: true,
    });

    // The operator logs time on the project, so an all-user total exists to be withheld.
    const started = await send(page, 'POST', '/time/timer/start', { project_id: projectId });
    expect(started.status).toBeLessThan(300);
    await send(page, 'POST', `/time/timer/${(started.json as { id: number }).id}/stop`, {});

    // The manager's view: Settings, the budget as metadata, the roster, all-user time.
    await page.reload();
    const managerMain = page.getByRole('main');
    await expect(managerMain.getByRole('link', { name: 'Settings' })).toBeVisible();
    const details = page.getByRole('complementary', { name: 'Project details' });
    await expect(details.getByText('$4,321.00')).toBeVisible();
    await expect(details.getByRole('heading', { name: 'People' })).toBeVisible();
    await expect(details.getByText('Dev User')).toBeVisible();
    await expect(details.getByText('Time logged')).toBeVisible();

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(`/projects/${projectId}`);
        const main = memberPage.getByRole('main');
        await expect(
            main.getByRole('heading', { level: 1, name: 'E2E WP2 customer project' }),
        ).toBeVisible();
        await expectProjectNavigation(memberPage, 'Overview');

        // Nothing gated: no Settings, no budget, no roster, no project-wide time.
        await expect(main.getByRole('link', { name: 'Settings' })).toHaveCount(0);
        await expect(main.getByText('Budget')).toHaveCount(0);
        await expect(main.getByText(/4,?321/)).toHaveCount(0);
        await expect(main.getByRole('heading', { name: 'People' })).toHaveCount(0);
        await expect(main.getByText('Time logged')).toHaveCount(0);
        await expect(main).not.toContainText('Dev Operator');
        // Their own time, labelled as theirs.
        await expect(main.getByText('Your time')).toBeVisible();
        await expect(main.getByText('Your entries only')).toBeVisible();

        // And the props themselves carry none of it: the page data is what the server sent.
        const pageData = await memberPage.evaluate(() => {
            const script = document.querySelector('script[data-page]');
            const element = document.querySelector('[data-page]');

            return script?.textContent ?? element?.getAttribute('data-page') ?? '';
        });
        expect(pageData).toContain('"scope":"own"');
        expect(pageData).not.toContain('"budget"');
        expect(pageData).not.toContain('"members"');
        expect(pageData).not.toContain('openSettings');
    } finally {
        await memberContext.close();
    }
});

test('the Overview holds together at 390px, the S/M boundary and desktop, light and dark', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(
        page,
        cleanup,
        'E2E WP2 responsive project with a long name',
    );
    for (const [name, offset] of [
        ['E2E WP2 overdue checkpoint', -3],
        ['E2E WP2 next checkpoint', 10],
        ['E2E WP2 later checkpoint', 20],
    ] as const) {
        const created = await send(page, 'POST', `/projects/${projectId}/milestones`, {
            name,
            due_date: isoDay(offset),
        });
        expect(created.status).toBeLessThan(400);
    }

    for (const width of [390, 768, 1440]) {
        for (const theme of ['light', 'dark'] as const) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/projects/${projectId}`);
            await page.evaluate((value) => localStorage.setItem('theme', value), theme);
            await page.reload();
            const at = `${width}px ${theme}`;

            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            expect(await hasHorizontalOverflow(page), `${at} document overflow`).toBe(false);
            await expectOneBreadcrumbAndHeading(page);

            // The navigation and the header action stay reachable at every width.
            for (const label of ['Overview', 'Board', 'Tasks', 'Milestones']) {
                await expect(projectNav(page).getByRole('link', { name: label }), at).toBeVisible();
            }
            await expect(
                page.getByRole('main').getByRole('link', { name: 'Settings' }),
                at,
            ).toBeVisible();

            // Health, progress and the StagePath stay legible: the current milestone is the overdue one.
            const main = page.getByRole('main');
            await expect(main.getByText('Off track'), at).toBeVisible();
            const path = main.getByRole('list', { name: 'Milestones: 0 of 3 complete' });
            await expect(path.getByRole('listitem'), at).toHaveCount(3);
            await expect(path.locator('[aria-current="step"]'), at).toHaveCount(1);
            const key = main.getByRole('list', { name: 'Key milestones' });
            await expect(key.getByRole('listitem'), at).toHaveCount(2);
            await expect(key.getByText('E2E WP2 overdue checkpoint'), at).toBeVisible();
            await expect(key.getByText('E2E WP2 next checkpoint'), at).toBeVisible();

            // Keyboard order follows reading order: the header action, then the project tabs; the
            // focused tab shows a visible focus ring.
            await page.getByRole('main').getByRole('link', { name: 'Settings' }).focus();
            await page.keyboard.press('Tab');
            const overview = projectNav(page).getByRole('link', { name: 'Overview' });
            await expect(overview, at).toBeFocused();
            const outline = await overview.evaluate(
                (element) => getComputedStyle(element).outlineStyle,
            );
            expect(outline, `${at} focus outline`).not.toBe('none');
        }
    }

    await page.evaluate(() => localStorage.removeItem('theme'));
});
