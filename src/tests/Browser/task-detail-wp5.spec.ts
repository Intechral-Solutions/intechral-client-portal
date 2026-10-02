import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-014 WP5 critical flows: the shared Direction D task detail (board and standalone), the one
 * shell breadcrumb (A13.12), single-row assignment from the list (R6) and the detail at 390px and on
 * desktop. Focused flows only: every authorization permutation, DTO field and candidate-source rule is
 * covered by Pest (TaskShowTest, TaskAssigneeOptionsTest, TaskListPageTest, StandaloneLifecycleTest) and
 * Vitest.
 *
 * Runs against the shared development database. Every project is registered with
 * `cleanup.trackProject`. Every standalone task this file creates is also DELETED by the file, through
 * the task page's own Delete action (the supported `tasks.destroy` route), so it leaves no residue. A
 * standalone task WITH recorded time cannot be deleted by design (INV-11), so the recorded-time refusal is
 * pinned by Pest and Vitest, and the board's by `task-detail-migration.spec.ts` (D4), rather than leaving
 * an undeletable row behind here. Fixture cleanup for the older specs stays WP7 (A9.7).
 */

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]);
    cleanup.trackProject(projectId);

    return projectId;
}

async function quickAdd(page: Page, columnName: string, title: string) {
    await page.getByRole('button', { name: `Add task to ${columnName}` }).click();
    await page.getByLabel('New task title').fill(title);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByRole('link', { name: title })).toBeVisible();
}

async function addMember(page: Page, projectId: number) {
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Update members' }).click();
    await expect(page.getByRole('status')).toContainText('Members updated.');
}

async function createStandalone(page: Page, title: string, assignee: 'Me' | 'Unassigned' = 'Me') {
    await page.goto('/tasks');
    await page.getByRole('button', { name: 'New task' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New task' });
    await dialog.getByLabel(/Title/).fill(title);
    await dialog.getByLabel('Assignee').selectOption({ label: assignee });
    await dialog.getByRole('button', { name: 'Create task' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('status').filter({ hasText: 'Task created.' })).toBeVisible();
}

/**
 * Delete the open standalone task through its own page (the supported route), and land on the list.
 * The DELETE's own response is asserted before the URL, so a failure says which step broke: the
 * delete itself, or the `/tasks` page it redirects to.
 */
async function deleteStandaloneFromDetail(page: Page) {
    // Callers may arrive by a row link whose Inertia visit is still in flight.
    await expect(page).toHaveURL(/\/tasks\/\d+$/);
    const path = new URL(page.url()).pathname;

    await page.getByRole('button', { name: 'Delete task' }).click();
    const [response] = await Promise.all([
        page.waitForResponse(
            (candidate) =>
                new URL(candidate.url()).pathname === path && candidate.request().method() === 'DELETE',
        ),
        page.getByRole('dialog').getByRole('button', { name: 'Delete task' }).click(),
    ]);

    // Inertia answers a non-GET redirect with 303, so the browser follows it with GET /tasks.
    expect(response.status(), `DELETE ${path}`).toBe(303);
    expect(new URL(response.headers()['location'] ?? '', page.url()).pathname, `DELETE ${path} redirect`).toBe('/tasks');
    await expect(page).toHaveURL(/\/tasks$/, { timeout: 15000 });
}

const rowOf = (page: Page, title: string) => page.getByRole('row').filter({ hasText: title });
const timePanel = (page: Page): Locator => page.getByRole('region', { name: 'Time' });
const assignTrigger = (scope: Page | Locator, title: string) =>
    scope.getByRole('button', { name: new RegExp(`Change assignee of “${title}”`) });

/** The one breadcrumb landmark and its parts, as the browser's accessibility tree exposes them. */
async function expectSingleBreadcrumb(page: Page) {
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);
    const tree = await page.locator('body').ariaSnapshot();
    expect(tree.match(/navigation "Breadcrumb"/g) ?? []).toHaveLength(1);
}

test('a standalone task is created, opened, edited, assigned, completed, reopened and deleted from its own page', async ({
    page,
}) => {
    await signedIn(page);
    const title = `E2E WP5 standalone ${Date.now()}`;
    await createStandalone(page, title);

    // The row links to its own detail page (tasks.show).
    await page.goto('/tasks');
    await rowOf(page, title).getByRole('link', { name: title }).click();
    await expect(page).toHaveURL(/\/tasks\/\d+$/);

    // The shared Direction D grammar, and only the sections a standalone task has.
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.locator('[data-page-frame="grid"]')).toHaveCount(1);
    await expect(page.locator('[data-strata]')).toHaveCount(1);
    await expect(page.getByRole('complementary', { name: 'Task details' })).toBeVisible();
    for (const present of ['Description', 'Details', 'Time']) {
        await expect(page.getByRole('heading', { level: 2, name: present })).toBeVisible();
    }
    for (const absent of ['Checklist', 'Comments']) {
        await expect(page.getByRole('heading', { name: absent })).toHaveCount(0);
    }

    // A13.12: exactly one Breadcrumb landmark, the shell's, whose trail is Tasks › My tasks › the task.
    await expectSingleBreadcrumb(page);
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs.getByRole('link', { name: 'Tasks', exact: true })).toBeVisible();
    await expect(crumbs.getByText(title)).toHaveAttribute('aria-current', 'page');

    // The contextual time panel: the assignee (the creator took it) may start a timer.
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();

    // Edit, through the dialog; the page re-renders from the server.
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Edit task' });
    await expect(dialog.getByLabel(/Title/)).toBeFocused();
    await dialog.getByLabel(/Title/).fill(`${title} edited`);
    await dialog.getByLabel('Priority').selectOption('high');
    await dialog.getByLabel('Description', { exact: true }).fill('Edited on the detail page.');
    await dialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Edit task', exact: true })).toBeFocused();
    await expect(page.getByRole('heading', { level: 1, name: `${title} edited` })).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'High' })).toBeVisible();

    // Assign / release through the narrow control: Unassigned and Me only. Releasing removes the
    // eligibility to START a timer (P5), and the page says so by dropping the control.
    const edited = `${title} edited`;
    await assignTrigger(page, edited).click();
    await expect(page.getByRole('menuitemradio')).toHaveText(['Unassigned', 'Me']);
    await page.getByRole('menuitemradio', { name: 'Unassigned' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Assignee updated.' })).toBeVisible();
    await expect(
        page.getByRole('button', { name: `Assignee: Unassigned. Change assignee of “${edited}”` }),
    ).toBeVisible();
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toHaveCount(0);

    await assignTrigger(page, edited).click();
    await page.getByRole('menuitemradio', { name: 'Me' }).click();
    await expect(
        page.getByRole('button', {
            name: `Assignee: Dev Operator. Change assignee of “${edited}”`,
        }),
    ).toBeVisible();
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();

    // Complete and Reopen: semantic actions, the button keeps focus as its name flips.
    await page.getByRole('button', { name: 'Complete task' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Reopen task' })).toBeFocused();
    await expect(page.locator('header').getByText('Done', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reopen task' }).click();
    await expect(page.getByRole('button', { name: 'Complete task' })).toBeVisible();

    // No board-only sections or fields ever appear.
    await expect(page.getByRole('term').filter({ hasText: /Milestone|Column/ })).toHaveCount(0);

    await deleteStandaloneFromDetail(page);
    await expect(rowOf(page, edited)).toHaveCount(0);
});

test('a standalone task is a 403 to a stranger and a missing task id is a 404', async ({
    page,
    contextFor,
}) => {
    await signedIn(page);
    const title = `E2E WP5 private ${Date.now()}`;
    await createStandalone(page, title);
    await page.goto('/tasks');
    await rowOf(page, title).getByRole('link', { name: title }).click();
    await expect(page).toHaveURL(/\/tasks\/\d+$/);
    const taskUrl = page.url();

    const memberContext = await contextFor('member');
    try {
        const memberPage = await memberContext.newPage();
        const response = await memberPage.goto(taskUrl);
        expect(response?.status()).toBe(403);
        expect((await memberPage.goto('/tasks/99999999'))?.status()).toBe(404);
    } finally {
        await memberContext.close();
    }

    await page.goto(taskUrl);
    await deleteStandaloneFromDetail(page);
});

test('a board task detail is the shared grammar with one shell breadcrumb, Complete/Reopen, assignment and the time panel', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectName = 'E2E WP5 board detail project';
    const projectId = await createProject(page, cleanup, projectName);
    const title = 'E2E WP5 board detail task';
    await quickAdd(page, 'Backlog', title);
    await page.getByRole('link', { name: title }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));

    // The grammar, with the board-only sections and details.
    await expect(page.locator('[data-page-frame="grid"]')).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(page.getByRole('complementary', { name: 'Task details' })).toBeVisible();
    for (const section of ['Description', 'Checklist', 'Comments', 'Details', 'Time']) {
        await expect(page.getByRole('heading', { level: 2, name: section })).toBeVisible();
    }
    await expect(page.getByRole('term').filter({ hasText: 'Milestone' })).toBeVisible();
    await expect(page.getByRole('term').filter({ hasText: 'Column' })).toBeVisible();
    // No raw status editor on a board task (completion is the column's).
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    await expect(page.getByRole('dialog', { name: 'Edit task' }).getByLabel('Status')).toHaveCount(
        0,
    );
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();

    // A13.12 closed: ONE Breadcrumb landmark, the shell's, with the project and the task in its trail.
    await expectSingleBreadcrumb(page);
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs.getByRole('link', { name: 'Projects', exact: true })).toBeVisible();
    await expect(crumbs.getByRole('link', { name: projectName })).toHaveAttribute(
        'href',
        new RegExp(`/projects/${projectId}/board$`),
    );
    await expect(crumbs.getByText(title)).toHaveAttribute('aria-current', 'page');

    // The contextual time panel is there and usable.
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();

    // Assignment follows the project-member rule: the manager is offered the members, nobody else.
    await assignTrigger(page, title).click();
    await expect(page.getByRole('menuitemradio')).toHaveText(['Unassigned', 'Dev Operator']);
    await page.getByRole('menuitemradio', { name: 'Dev Operator' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Assignee updated.' })).toBeVisible();
    await expect(
        page.getByRole('button', { name: `Assignee: Dev Operator. Change assignee of “${title}”` }),
    ).toBeVisible();

    // Complete lands the card in the Done column; Reopen returns it.
    await page.getByRole('button', { name: 'Complete task' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'Done' })).toBeVisible();
    await page.getByRole('button', { name: 'Reopen task' }).click();
    await expect(page.getByRole('button', { name: 'Complete task' })).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'Done' })).toHaveCount(0);

    // The shell's trail navigates back to the board over Inertia.
    await crumbs.getByRole('link', { name: projectName }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/board$`));
});

test('a member-assignee completes and reopens a board task from its detail, and is offered nothing else', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 member-assignee project');
    await addMember(page, projectId);
    await page.goto(`/projects/${projectId}/board`);
    const title = 'E2E WP5 member task';
    await quickAdd(page, 'Backlog', title);
    await page.getByRole('link', { name: title }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const taskUrl = page.url();

    await assignTrigger(page, title).click();
    await expect(page.getByRole('menuitemradio')).toHaveText([
        'Unassigned',
        'Dev Operator',
        'Dev User',
    ]);
    await page.getByRole('menuitemradio', { name: 'Dev User' }).click();
    await expect(
        page.getByRole('button', { name: `Assignee: Dev User. Change assignee of “${title}”` }),
    ).toBeVisible();

    const memberContext = await contextFor('member');
    try {
        const memberPage = await memberContext.newPage();
        await memberPage.goto(taskUrl);

        await expect(memberPage.getByRole('button', { name: 'Complete task' })).toBeVisible();
        for (const forbidden of ['Edit task', 'Delete task']) {
            await expect(memberPage.getByRole('button', { name: forbidden })).toHaveCount(0);
        }
        // Read-only assignee, no assign control, and still the one breadcrumb.
        await expect(memberPage.getByRole('button', { name: /Change assignee/ })).toHaveCount(0);
        await expect(
            memberPage.getByRole('definition').filter({ hasText: 'Dev User' }),
        ).toBeVisible();
        await expectSingleBreadcrumb(memberPage);

        await memberPage.getByRole('button', { name: 'Complete task' }).click();
        await expect(memberPage.getByRole('button', { name: 'Reopen task' })).toBeFocused();
        await memberPage.getByRole('button', { name: 'Reopen task' }).click();
        await expect(memberPage.getByRole('button', { name: 'Complete task' })).toBeVisible();
    } finally {
        await memberContext.close();
    }
});

test('single-row assignment from the list: standalone is Me or Unassigned, a board row takes a member, and a row the viewer may not assign offers nothing', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 list assign project');
    await addMember(page, projectId);
    await page.goto(`/projects/${projectId}/board`);
    const boardTitle = 'E2E WP5 list board task';
    await quickAdd(page, 'Backlog', boardTitle);
    await page.getByRole('link', { name: boardTitle }).click();
    await assignTrigger(page, boardTitle).click();
    await page.getByRole('menuitemradio', { name: 'Dev Operator' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Assignee updated.' })).toBeVisible();

    const standaloneTitle = `E2E WP5 list standalone ${Date.now()}`;
    await createStandalone(page, standaloneTitle);

    // Standalone row: exactly Me and Unassigned.
    await page.goto('/tasks');
    const standaloneRow = rowOf(page, standaloneTitle);
    await assignTrigger(standaloneRow, standaloneTitle).click();
    await expect(page.getByRole('menuitemradio')).toHaveText(['Unassigned', 'Me']);
    await page.getByRole('menuitemradio', { name: 'Unassigned' }).click();
    await expect(standaloneRow.getByRole('button', { name: /Assignee: Unassigned/ })).toBeVisible();
    // An unassigned task its creator made stays on My tasks (Q4).
    await assignTrigger(standaloneRow, standaloneTitle).click();
    await page.getByRole('menuitemradio', { name: 'Me' }).click();
    await expect(
        standaloneRow.getByRole('button', { name: /Assignee: Dev Operator/ }),
    ).toBeVisible();

    // Board row: the project's members, from the server's list.
    const boardRow = rowOf(page, boardTitle);
    await assignTrigger(boardRow, boardTitle).click();
    await expect(page.getByRole('menuitemradio')).toHaveText([
        'Unassigned',
        'Dev Operator',
        'Dev User',
    ]);
    await page.getByRole('menuitemradio', { name: 'Dev User' }).click();
    // Reassigned away from the operator, the row leaves My tasks; focus is not dropped on the page.
    await expect(boardRow).toHaveCount(0);
    expect(await page.evaluate(() => document.activeElement === document.body)).toBe(false);

    // All tasks (the operator holds tasks.view_all) still shows it, now held by Dev User.
    await page.goto('/tasks?view=all');
    await expect(
        rowOf(page, boardTitle).getByRole('button', { name: /Assignee: Dev User/ }),
    ).toBeVisible();

    // Dev User holds the task as a plain member-assignee: Complete, but no assignment affordance.
    const memberContext = await contextFor('member');
    try {
        const memberPage = await memberContext.newPage();
        await memberPage.goto('/tasks');
        const memberRow = rowOf(memberPage, boardTitle);
        await expect(memberRow).toBeVisible();
        await expect(
            memberRow.getByRole('button', { name: `Complete ${boardTitle}` }),
        ).toBeVisible();
        await expect(memberRow.getByRole('button', { name: /Change assignee/ })).toHaveCount(0);
        await expect(memberRow.getByText('Dev User')).toBeAttached();
    } finally {
        await memberContext.close();
    }

    await page.goto('/tasks');
    await rowOf(page, standaloneTitle).getByRole('link', { name: standaloneTitle }).click();
    await deleteStandaloneFromDetail(page);
});

test('the S-width list row stays a valid two-band row with the assignment control, and the menu works at 390px', async ({
    page,
}) => {
    await signedIn(page);
    const title = `E2E WP5 phone assign ${Date.now()}`;
    await createStandalone(page, title);

    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto('/tasks');
    const row = rowOf(page, title);
    await expect(row).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);

    const trigger = assignTrigger(row, title);
    await expect(trigger).toBeVisible();
    const triggerBox = (await trigger.boundingBox())!;
    expect(triggerBox.height).toBeGreaterThanOrEqual(24);
    expect(triggerBox.x + triggerBox.width).toBeLessThanOrEqual(391);

    // Exactly two bands, measured from every cell that draws something.
    const bands = await row.evaluate((node) => {
        const mids = [...node.querySelectorAll(':scope > td')]
            .map((cell) => cell.getBoundingClientRect())
            .filter((box) => box.width >= 2 && box.height >= 2)
            .map((box) => (box.top + box.bottom) / 2);
        const clusters: number[] = [];
        for (const mid of mids.sort((a, b) => a - b)) {
            if (clusters.length === 0 || mid - clusters[clusters.length - 1]! > 6)
                clusters.push(mid);
        }
        const box = node.getBoundingClientRect();

        return { clusters, height: box.height };
    });
    expect(bands.clusters, 'two bands').toHaveLength(2);
    expect(bands.height).toBeLessThanOrEqual(96);

    // The control works at S, the row is still two bands afterwards, and its name is announced.
    await trigger.click();
    await page.getByRole('menuitemradio', { name: 'Unassigned' }).click();
    await expect(row.getByRole('button', { name: /Assignee: Unassigned/ })).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);
    expect(await row.evaluate((node) => node.getBoundingClientRect().height)).toBeLessThanOrEqual(
        96,
    );

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto('/tasks');
    await rowOf(page, title).getByRole('link', { name: title }).click();
    await deleteStandaloneFromDetail(page);
});

test('the detail pages have no horizontal overflow and keep their actions reachable at 390px, and collapse the aside under the main column; on desktop the aside sits beside it', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const title = `E2E WP5 responsive ${Date.now()}`;
    await createStandalone(page, title);
    const projectId = await createProject(page, cleanup, 'E2E WP5 responsive project');
    await quickAdd(page, 'Backlog', 'E2E WP5 responsive board task');
    await page.getByRole('link', { name: 'E2E WP5 responsive board task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const boardUrl = page.url();

    await page.goto('/tasks');
    await rowOf(page, title).getByRole('link', { name: title }).click();
    await expect(page).toHaveURL(/\/tasks\/\d+$/);
    const standaloneUrl = page.url();

    for (const url of [standaloneUrl, boardUrl]) {
        for (const [width, stacked] of [
            [390, true],
            [1400, false],
        ] as const) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(url);
            const at = `${url} ${width}`;

            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            expect(await hasHorizontalOverflow(page), `${at} overflow`).toBe(false);

            // One breadcrumb at every width. At S the 48px bar keeps the parent and the current page and
            // hides the leading segments, rather than truncating all of them to nothing.
            await expectSingleBreadcrumb(page);
            const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
            await expect(crumbs.locator('[aria-current="page"]')).toBeVisible();
            const workspace = url === boardUrl ? 'Projects' : 'Tasks';
            if (stacked) {
                await expect(
                    crumbs.getByRole('link', { name: workspace, exact: true }),
                ).toBeHidden();
                if (url === boardUrl) {
                    await expect(
                        crumbs.getByRole('link', { name: /responsive project/ }),
                    ).toBeVisible();
                } else {
                    await expect(crumbs.getByRole('button', { name: /Tasks views/ })).toBeVisible();
                }
            } else {
                await expect(
                    crumbs.getByRole('link', { name: workspace, exact: true }),
                ).toBeVisible();
            }
            // The important actions stay reachable, wrapped inside the viewport.
            for (const name of ['Complete task', 'Edit task', 'Delete task']) {
                const box = await page.getByRole('button', { name }).boundingBox();
                expect(box, `${at} ${name}`).not.toBeNull();
                expect(box!.x, `${at} ${name} left`).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width, `${at} ${name} right`).toBeLessThanOrEqual(width + 1);
            }

            const main = (await page
                .getByRole('heading', { level: 2, name: 'Description' })
                .boundingBox())!;
            const aside = (await page
                .getByRole('complementary', { name: 'Task details' })
                .boundingBox())!;
            if (stacked) {
                // Reading order: the supporting column follows the main column.
                expect(aside.y, `${at} aside below main`).toBeGreaterThan(main.y);
                expect(aside.x, `${at} aside full width`).toBeLessThan(40);
            } else {
                expect(aside.x, `${at} aside beside main`).toBeGreaterThan(main.x + 200);
            }
        }
    }

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto(standaloneUrl);
    await deleteStandaloneFromDetail(page);
});
