import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { personas, signedIn } from './support/auth';

/**
 * EPIC-011E WP8 critical flows: the unified `/tasks` list as a React/Inertia page, replacing the
 * deleted `tasks/index.blade.php`. Focused flows only; every DTO field, kind-semantics rule, and
 * authorization/visibility permutation is covered by Pest (TaskListInertiaTest.php,
 * ProjectVisibilityTest.php) and Vitest instead. Runs against the shared development database:
 * every project is registered with `cleanup.trackProject` as soon as its URL is known.
 *
 * Two things this file deliberately does NOT attempt, and why:
 *  - A ticket-derived task row: nothing in this application creates one (§3.1, characterized in
 *    WP1 — only test factories do), so there is no real browser path to produce that row kind.
 *    Its rendering (context link, no title link) is pinned by Vitest and Pest instead.
 *  - D2's "company-linked but not a member" scenario: every locked-behavior browser spec in this
 *    suite so far (board, milestones, task detail) has left that scenario to Pest, because
 *    reproducing it needs an Organization/CrmCompany fixture this suite has never built. D2 is
 *    exhaustively pinned server-side (ProjectVisibilityTest.php, 10 cases); this file instead
 *    proves the plainer, still-real visibility fact a browser uniquely adds: a task assigned to
 *    one person never appears on someone else's list at all.
 *  - Standalone tasks have no delete route (D3, locked) and none is added here. The one
 *    standalone row this file creates (`E2E WP8 standalone task`) therefore cannot be removed
 *    through the application afterward and is accepted as permanent development-database debt,
 *    the same trade-off already recorded for the DevSeeder fixture ticket (EPIC-011E §21, Z4).
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

/**
 * `/tasks`'s own "mine" scope is `assignee_id = viewer` (§15), and quick-add creates a task with
 * no assignee at all, so a freshly quick-added board task never appears on anyone's list until
 * someone is assigned to it. Opens the task, assigns it to the project's creator/manager (the
 * operator account this suite signs in as, auto-attached as a member by `ProjectService::create`)
 * and saves, then returns to the board.
 */
async function assignToBoardCreator(page: Page, projectId: number, taskTitle: string) {
    await page.getByRole('link', { name: taskTitle }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    await page.getByLabel('Assignee').selectOption({ label: 'Dev Operator' });
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('definition').filter({ hasText: 'Dev Operator' })).toBeVisible();
}

test('the tasks list loads and navigates over Inertia, both to a task page and back', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP8 nav project');
    await quickAdd(page, 'Backlog', 'E2E WP8 nav task');
    await assignToBoardCreator(page, projectId, 'E2E WP8 nav task');
    await page.goto(`/projects/${projectId}/board`);

    // A persistent timer's live clock is the SPA-navigation signal used throughout this suite: a
    // full document reload would remount the app and the clock would restart from what the
    // server last rendered, not keep ticking client-side (D4 requires this entry gone before the
    // project can be deleted, so it is stopped before the test ends).
    const entryId = await page.evaluate(async (id) => {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        const response = await fetch('/time/timer/start', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ project_id: id }),
        });

        return ((await response.json()) as { id: number }).id;
    }, projectId);

    // A full document load: `TimerProvider` fetches the active-timer list once, on mount, so a
    // timer started by a raw request (bypassing its own start handler) needs a fresh mount to be
    // discovered — exactly the same reason `board-migration.spec.ts` uses `page.goto` here too.
    // The Inertia-ness this test is actually about is proven below, from this point onward.
    await page.goto('/tasks');
    await expect(page.getByRole('heading', { name: 'Tasks' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    // The title link is the migrated React task page (WP7): an Inertia visit, never a document
    // load, so the timer bar survives it.
    await page.getByRole('link', { name: 'E2E WP8 nav task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    await page.goBack();
    await expect(page).toHaveURL('/tasks');
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    await page.evaluate(async (id) => {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        await fetch(`/time/timer/${id}/stop`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': token,
            },
        });
    }, entryId);
});

test('a project task is linked and a standalone task is not, and the standalone row offers no mutation controls (D3)', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP8 mixed-kind project');
    await quickAdd(page, 'Backlog', 'E2E WP8 project task');
    await assignToBoardCreator(page, projectId, 'E2E WP8 project task');

    await page.goto('/tasks');
    const projectRow = page.getByRole('row').filter({ hasText: 'E2E WP8 project task' });
    await expect(projectRow.getByRole('link', { name: 'E2E WP8 project task' })).toHaveAttribute(
        'href',
        new RegExp(`/projects/${projectId}/tasks/\\d+$`),
    );
    await expect(
        projectRow.getByRole('link', { name: 'E2E WP8 mixed-kind project' }),
    ).toHaveAttribute('href', new RegExp(`/projects/${projectId}/board$`));

    // Standalone create (§11, D3): title only, assignee limited to "Me" or "Unassigned". This
    // row has no delete route by design and is accepted as permanent test debt (see file header);
    // the title carries a run-unique suffix so repeated runs never collide on the same text and
    // trip a Playwright strict-mode match against an earlier run's undeletable leftover row.
    const standaloneTitle = `E2E WP8 standalone task ${Date.now()}`;
    await page.getByRole('button', { name: '+ New task' }).click();
    await page.getByLabel('Title', { exact: false }).fill(standaloneTitle);
    await page.getByLabel('Assignee').selectOption({ label: 'Me' });
    await page.getByRole('button', { name: 'Create task' }).click();
    await expect(page.getByRole('status')).toContainText('Task created.');

    const standaloneRow = page.getByRole('row').filter({ hasText: standaloneTitle });
    await expect(standaloneRow).toBeVisible();
    await expect(standaloneRow.getByRole('link')).toHaveCount(0);
    await expect(standaloneRow.getByText('Standalone', { exact: true })).toBeVisible();
    // No edit, delete, or complete control exists for a standalone row (D3, locked): the create
    // form itself is the only mutation surface this page ever offers for this kind.
    await expect(standaloneRow.getByRole('checkbox')).toHaveCount(0);
    await expect(standaloneRow.getByRole('button')).toHaveCount(0);
});

test("a task assigned to one person never appears on another person's task list", async ({
    page,
    browser,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP8 visibility project');
    await quickAdd(page, 'Backlog', 'E2E WP8 not-yours task');
    await assignToBoardCreator(page, projectId, 'E2E WP8 not-yours task');

    await page.goto('/tasks');
    await expect(page.getByText('E2E WP8 not-yours task')).toBeVisible();

    // A second browser context, not a re-sign-in on the same page: every other multi-actor flow
    // in this suite (e.g. task-detail-migration.spec.ts's checklist test) does the same, so one
    // session's own state never leaks into the other's.
    const otherContext = await browser.newContext({ storageState: personas.member.storageState });
    try {
        const otherPage = await otherContext.newPage();
        await otherPage.goto('/tasks');
        await expect(otherPage.getByText('E2E WP8 not-yours task')).toHaveCount(0);
    } finally {
        await otherContext.close();
    }
});

test('the standalone create form reports a validation error inline without losing the draft', async ({
    page,
}) => {
    await signedIn(page);
    await page.goto('/tasks');

    await page.getByRole('button', { name: '+ New task' }).click();
    // Title left empty: the browser's own `required` attribute would block a native submit, so
    // the description field is filled instead to prove the round trip without relying on that.
    await page.getByLabel('Description').fill('E2E WP8 orphaned description');
    await page.evaluate(() => {
        // Bypass the native required-field block to exercise the server's own validation path,
        // exactly as a request forged past the client would.
        document
            .querySelectorAll('input[required]')
            .forEach((el) => el.removeAttribute('required'));
    });
    await page.getByRole('button', { name: 'Create task' }).click();

    await expect(page.getByRole('alert')).toContainText('title');
    await expect(page.getByLabel('Description')).toHaveValue('E2E WP8 orphaned description');
});

test('the tasks list is usable at a phone viewport with no document-level horizontal scroll', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 700 });
    await signedIn(page);
    await page.goto('/tasks');

    await expect(page.getByRole('heading', { name: 'Tasks' })).toBeVisible();
    await expect(page.getByRole('button', { name: '+ New task' })).toBeVisible();

    const overflowsHorizontally = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    );
    expect(overflowsHorizontally).toBe(false);
});
