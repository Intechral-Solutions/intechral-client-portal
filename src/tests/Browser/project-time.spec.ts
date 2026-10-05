import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-015 WP5 (optional S3) critical flows: the project Time tab, `/projects/{project}/time`.
 *
 * An all-team viewer (the operator, `time.view_all`) sees every person's settled project time,
 * attributed by the WP1 canonical rule: task time under the task's project, direct time under its
 * own, another project's time nowhere. An own-scope viewer (the member persona, a customer holding
 * `time.log` only) sees their own entries and no other person at all; a non-member gets 403. The tab
 * is the fifth workspace link, with one h1, one breadcrumb, and a usable strip at 390px.
 *
 * Authorization matrix, attribution edge cases (malformed rows), DTO minimality and query budgets are
 * Pest's (ProjectTimePageTest, ProjectQueryBudgetTest); rendering details are Vitest's.
 *
 * Runs against the shared development database. Time entries are logged through the real `/time`
 * endpoint and removed by id (read from this page's own props) in a `finally`, BEFORE the fixture
 * deletes the projects, because a project with recorded time is never deletable (INV-P3).
 */

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
    withMember: boolean,
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    if (withMember) {
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

async function quickAddTask(page: Page, projectId: number, title: string) {
    await page.goto(`/projects/${projectId}/board`);
    await page.getByRole('button', { name: 'Add task to To Do' }).click();
    await page.getByLabel('New task title').fill(title);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    const card = page.getByRole('article', { name: title });
    await expect(card).toBeVisible();

    return Number(await card.getAttribute('data-task-id'));
}

/** A manual entry through the real `/time` store, as this page's signed-in user. */
async function logTime(
    page: Page,
    context: { task_id: number } | { project_id: number },
    hours: number,
) {
    const status = await page.evaluate(
        async (body) => {
            const token =
                document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
            const response = await fetch('/time', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify(body),
            });

            return response.status;
        },
        {
            date: new Date().toISOString().slice(0, 10),
            hours,
            description: 'E2E WP5 project time',
            billable: false,
            ...context,
        },
    );
    expect(status, 'manual time logged').toBeLessThan(400);
}

type TimeProps = {
    auth: { user: { name: string } };
    summary: { scope: 'all' | 'own' };
    entries: { data: { id: number; userName?: string }[] };
};

/**
 * Deletes this page's user's own entries on the project, by id from the Time tab's own props (own
 * scope lists only them; all scope names each person). Only entries the user owns can be deleted.
 */
async function purgeOwnProjectTime(page: Page, projectId: number) {
    const response = await page.goto(`/projects/${projectId}/time`);
    if (!response || response.status() !== 200) return;

    await page.evaluate(async () => {
        // The initial page object: a `<script data-page>` JSON element (older Inertia: `#app[data-page]`).
        const raw =
            document.querySelector('script[data-page]')?.textContent ??
            document.getElementById('app')?.dataset.page ??
            '{}';
        const props = JSON.parse(raw).props as TimeProps;
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        const mine = props.entries.data.filter(
            (row) => props.summary.scope === 'own' || row.userName === props.auth.user.name,
        );

        for (const row of mine) {
            await fetch(`/time/${row.id}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
        }
    });
}

function projectNav(page: Page) {
    return page.getByRole('navigation', { name: 'Project', exact: true });
}

function timeTable(page: Page) {
    return page.getByRole('table', { name: 'Time entries' });
}

test('all-team and own-only views of the same project, canonically attributed and customer-safe', async ({
    page,
    contextFor,
    cleanup,
}) => {
    test.slow(); // one journey over two personas and two projects; its cost is the seed

    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 time project', true);
    const otherId = await createProject(page, cleanup, 'E2E WP5 other time project', false);
    const taskId = await quickAddTask(page, projectId, 'E2E WP5 timed task');

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();

    try {
        // Operator: 2h on the task (task time, project_id NULL), 30m direct; 1h on another project.
        await logTime(page, { task_id: taskId }, 2);
        await logTime(page, { project_id: projectId }, 0.5);
        await logTime(page, { project_id: otherId }, 1);
        // The member: 45m on the same task.
        await memberPage.goto('/time');
        await logTime(memberPage, { task_id: taskId }, 0.75);

        // ── All team members (operator) ──
        await page.goto(`/projects/${projectId}/time`);
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('E2E WP5 time project');
        await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);
        await expect(projectNav(page).getByRole('link')).toHaveText([
            'Overview',
            'Board',
            'Tasks',
            'Milestones',
            'Time',
        ]);
        await expect(projectNav(page).getByRole('link', { name: 'Time' })).toHaveAttribute(
            'aria-current',
            'page',
        );

        const summary = page.getByLabel('Project time summary');
        await expect(summary).toContainText('Time logged');
        await expect(summary).toContainText('3h 15m');
        await expect(summary).toContainText('All team members');

        const rows = timeTable(page).getByRole('row');
        await expect(rows).toHaveCount(1 + 3); // header + this project's three entries only
        await expect(timeTable(page).getByRole('columnheader')).toHaveText([
            'Date',
            'Person',
            'Logged against',
            'Duration',
        ]);
        await expect(timeTable(page)).toContainText('Dev User');
        await expect(timeTable(page)).toContainText('Project (no task)');
        await expect(timeTable(page)).not.toContainText('1h');
        // The canonical task context links to the task, which this viewer can open.
        const taskLinks = timeTable(page).getByRole('link', { name: 'E2E WP5 timed task' });
        await expect(taskLinks).toHaveCount(2);
        await taskLinks.first().focus();
        await expect(taskLinks.first()).toBeFocused();

        // The Overview's summary agrees and links here.
        await page.goto(`/projects/${projectId}`);
        await expect(page.getByRole('complementary', { name: 'Project details' })).toContainText(
            '3h 15m',
        );
        await page.getByRole('link', { name: 'View time entries' }).click();
        await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/time$`));

        // ── Your entries only (customer member) ──
        await memberPage.goto(`/projects/${projectId}/time`);
        const memberSummary = memberPage.getByLabel('Project time summary');
        await expect(memberSummary).toContainText('Your time');
        await expect(memberSummary).toContainText('45m');
        await expect(memberSummary).toContainText('Your entries only');
        await expect(timeTable(memberPage).getByRole('row')).toHaveCount(1 + 1);
        await expect(timeTable(memberPage).getByRole('columnheader')).toHaveText([
            'Date',
            'Logged against',
            'Duration',
        ]);
        // Nothing about anyone else: not the operator's name, not their minutes, not in the props.
        const memberProps = await memberPage.evaluate(
            () =>
                document.querySelector('script[data-page]')?.textContent ??
                document.getElementById('app')?.dataset.page ??
                '',
        );
        expect(memberProps).toContain('"scope":"own"');
        expect(memberProps).not.toContain('Dev Operator');
        expect(memberProps).not.toContain('userName');
        await expect(memberPage.getByRole('main')).not.toContainText('3h 15m');
        await expect(memberPage.getByRole('main')).not.toContainText('Dev Operator');

        // The member is offered the Time tab on every workspace page they can open.
        await memberPage.goto(`/projects/${projectId}/board`);
        await expect(projectNav(memberPage).getByRole('link', { name: 'Time' })).toBeVisible();

        // A project the member is not on: the route refuses, whatever the URL.
        const refused = await memberPage.goto(`/projects/${otherId}/time`);
        expect(refused?.status()).toBe(403);
    } finally {
        await purgeOwnProjectTime(memberPage, projectId);
        await memberContext.close();
        await purgeOwnProjectTime(page, projectId);
        await purgeOwnProjectTime(page, otherId);
    }
});

test('the Time tab and its five-link strip hold together at 390px, 768px and 1440px, light and dark', async ({
    page,
    cleanup,
}) => {
    test.slow(); // six width/theme passes over one seeded project

    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 responsive time project', false);
    const taskId = await quickAddTask(
        page,
        projectId,
        'E2E WP5 a deliberately long task title that has to wrap on a phone',
    );

    try {
        await logTime(page, { task_id: taskId }, 1.25);
        await logTime(page, { project_id: projectId }, 0.25);

        for (const width of [390, 768, 1440]) {
            for (const theme of ['light', 'dark'] as const) {
                const at = `${width}px ${theme}`;
                await page.setViewportSize({ width, height: 900 });
                await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
                await page.goto(`/projects/${projectId}/time`);
                await expect(page.locator('html')).toHaveAttribute('data-theme', theme);

                await expect(page.getByRole('heading', { level: 1 }), at).toHaveCount(1);
                await expect(page.getByRole('navigation', { name: 'Breadcrumb' }), at).toHaveCount(
                    1,
                );
                expect(await hasHorizontalOverflow(page), `${at} document overflow`).toBe(false);

                // The strip scrolls rather than wraps; every one of the five links can be reached.
                const nav = projectNav(page);
                expect(
                    await nav.evaluate((node) => getComputedStyle(node).overflowX),
                    `${at} nav scrolls`,
                ).toBe('auto');
                for (const label of ['Overview', 'Board', 'Tasks', 'Milestones', 'Time']) {
                    const link = nav.getByRole('link', { name: label });
                    await link.scrollIntoViewIfNeeded();
                    await expect(link, `${at} ${label} reachable`).toBeInViewport();
                }
                const time = nav.getByRole('link', { name: 'Time' });
                await time.focus();
                await expect(time, `${at} Time focusable`).toBeFocused();
                const ring = await time.evaluate((node) => {
                    const style = getComputedStyle(node);

                    return { style: style.outlineStyle, offset: parseFloat(style.outlineOffset) };
                });
                expect(ring.style, `${at} focus outline`).not.toBe('none');
                expect(ring.offset, `${at} focus outline inset`).toBeLessThanOrEqual(0);
                await expect(time, `${at} focused Time in view`).toBeInViewport({ ratio: 1 });

                // Scope in words, rows in a named table, durations reachable in the viewport.
                await expect(page.getByLabel('Project time summary'), at).toContainText(
                    'All team members',
                );
                await expect(timeTable(page).getByRole('row'), at).toHaveCount(1 + 2);
                await expect(timeTable(page).getByText('1h 15m'), at).toBeInViewport();
            }
        }
    } finally {
        await purgeOwnProjectTime(page, projectId);
    }
});
