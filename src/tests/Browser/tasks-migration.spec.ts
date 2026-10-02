import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';
import { drawerLink, hasHorizontalOverflow, timerPill } from './support/shell';

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
 *  - Standalone tasks are still not removed: the supported delete route exists (EPIC-014 WP2) but
 *    fixture cleanup through it is WP7's (A9.7). The one standalone row this file creates
 *    (`E2E WP8 standalone task`) is therefore Completed at the end of its test, so it leaves the
 *    default open list and cannot clutter later runs; the row itself remains until WP7.
 *
 * EPIC-014 WP4 rewrote the list as the Direction D page (filter bar, `DataTable`, Complete ring,
 * bulk bar, row shortcuts, D9 rows at S). The flows below are the browser half of that contract:
 * Complete/Reopen, URL-backed filter state and history, keyboard + bulk, and measured geometry at
 * the S boundary and a 390px phone, in both themes. Visibility, query semantics and normalization
 * stay with Pest (TaskQueryTest, TaskListPageTest); component behaviour stays with Vitest.
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
async function assignToBoardCreator(
    page: Page,
    projectId: number,
    taskTitle: string,
    details: { priority?: string; due?: string } = {},
) {
    await page.getByRole('link', { name: taskTitle }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    // The edit form is a dialog on the task page (EPIC-014 WP5), over the same endpoint.
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Edit task' });
    await dialog.getByLabel('Assignee').selectOption({ label: 'Dev Operator' });
    if (details.priority)
        await dialog.getByLabel('Priority').selectOption({ label: details.priority });
    if (details.due) await dialog.getByLabel('Due date').fill(details.due);
    await dialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(dialog).toHaveCount(0);
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
    await expect(page.getByRole('heading', { level: 1, name: 'My tasks' })).toBeVisible();
    await expect(timerPill(page)).toBeVisible();

    // The title link is the migrated React task page (WP7): an Inertia visit, never a document
    // load, so the timer pill survives it.
    await page.getByRole('link', { name: 'E2E WP8 nav task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    await expect(timerPill(page)).toBeVisible();

    await page.goBack();
    await expect(page).toHaveURL('/tasks');
    await expect(timerPill(page)).toBeVisible();

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

test('a project task and a standalone task are both linked, and the standalone row offers exactly the controls its abilities allow', async ({
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

    // Standalone create (§14.1): a dialog opened from the page header, assignee limited to "Me" or
    // "Unassigned". The title carries a run-unique suffix so repeated runs never collide.
    const standaloneTitle = `E2E WP8 standalone task ${Date.now()}`;
    await page.getByRole('button', { name: 'New task' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New task' });
    await expect(dialog.getByLabel('Title', { exact: false })).toBeFocused();
    await dialog.getByLabel('Title', { exact: false }).fill(standaloneTitle);
    await dialog.getByLabel('Assignee').selectOption({ label: 'Me' });
    await dialog.getByRole('button', { name: 'Create task' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Task created.' })).toBeVisible();
    await expect(dialog).toHaveCount(0);

    const standaloneRow = page.getByRole('row').filter({ hasText: standaloneTitle });
    await expect(standaloneRow).toBeVisible();
    // EPIC-014 WP5 (§16.3): the standalone row offers exactly the controls its abilities allow: a
    // link to its own detail page, Complete, the Me/Unassigned assignment menu and the selection
    // box. No Edit or Delete is offered on the list.
    await expect(standaloneRow.getByRole('link')).toHaveCount(1);
    await expect(standaloneRow.getByRole('link', { name: standaloneTitle })).toHaveAttribute(
        'href',
        /\/tasks\/\d+$/,
    );
    await expect(standaloneRow.getByText('Standalone', { exact: true }).first()).toBeVisible();
    await expect(
        standaloneRow.getByRole('button', { name: `Complete ${standaloneTitle}` }),
    ).toBeVisible();
    await expect(
        standaloneRow.getByRole('button', {
            name: new RegExp(`Change assignee of “${standaloneTitle}”`),
        }),
    ).toBeVisible();
    await expect(standaloneRow.getByRole('checkbox')).toHaveCount(1);
    await expect(standaloneRow.getByRole('button')).toHaveCount(2);

    // Complete it, so the leftover row leaves the default open list (the row stays until WP7).
    await standaloneRow.getByRole('button', { name: `Complete ${standaloneTitle}` }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    await expect(standaloneRow).toHaveCount(0);
});

test("a task assigned to one person never appears on another person's task list", async ({
    page,
    contextFor,
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
    const otherContext = await contextFor('member');
    try {
        const otherPage = await otherContext.newPage();
        await otherPage.goto('/tasks');
        await expect(otherPage.getByText('E2E WP8 not-yours task')).toHaveCount(0);
    } finally {
        await otherContext.close();
    }
});

test('the create dialog reports a validation error inline without losing the draft', async ({
    page,
}) => {
    await signedIn(page);
    await page.goto('/tasks');

    await page.getByRole('button', { name: 'New task' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New task' });
    // Title left empty: the browser's own `required` attribute would block a native submit, so
    // the description field is filled instead to prove the round trip without relying on that.
    await dialog.getByLabel('Description').fill('E2E WP8 orphaned description');
    await page.evaluate(() => {
        // Bypass the native required-field block to exercise the server's own validation path,
        // exactly as a request forged past the client would.
        document
            .querySelectorAll('input[required]')
            .forEach((el) => el.removeAttribute('required'));
    });
    await dialog.getByRole('button', { name: 'Create task' }).click();

    await expect(dialog.getByRole('alert')).toContainText('title');
    await expect(dialog.getByLabel('Description')).toHaveValue('E2E WP8 orphaned description');

    // Escape closes it and focus returns to what opened it.
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'New task' }).first()).toBeFocused();
});

test('the tasks list is usable at a phone viewport with no document-level horizontal scroll', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 700 });
    await signedIn(page);
    await page.goto('/tasks');

    await expect(page.getByRole('heading', { level: 1, name: 'My tasks' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'New task' }).first()).toBeVisible();

    expect(await hasHorizontalOverflow(page)).toBe(false);
});

/*
 * ── EPIC-014 WP4: the Direction D list ──────────────────────────────────────────────────────────
 */

/** One project, each title quick-added and assigned to the signed-in operator so it is on My tasks. */
async function seedAssigned(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    projectName: string,
    titles: string[],
    details: { priority?: string; due?: string } = {},
) {
    const projectId = await createProject(page, cleanup, projectName);
    for (const title of titles) {
        await page.goto(`/projects/${projectId}/board`);
        await quickAdd(page, 'Backlog', title);
        await assignToBoardCreator(page, projectId, title, details);
    }

    return projectId;
}

const rowOf = (page: Page, title: string) => page.getByRole('row').filter({ hasText: title });

test('a board task is completed and reopened from the list, landing in and leaving the Done column', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const title = 'E2E WP4 complete me';
    const projectId = await seedAssigned(page, cleanup, 'E2E WP4 complete project', [title]);

    await page.goto('/tasks');
    await rowOf(page, title)
        .getByRole('button', { name: `Complete ${title}` })
        .click();
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    // Open is the default view, so a completed task leaves it.
    await expect(rowOf(page, title)).toHaveCount(0);

    // The completion filter (WP4 closes the WP3 gap): the task is in the Done view, with its
    // column as its status text (column-authoritative, INV-1), and the filter is a visible chip.
    await page.getByLabel('Completion').selectOption({ label: 'Done' });
    await expect(page).toHaveURL(/completion=done/);
    await expect(page.getByRole('list', { name: 'Active filters' })).toContainText(
        'Completion: Done',
    );
    await expect(rowOf(page, title).getByText('Done', { exact: true })).toBeVisible();

    await rowOf(page, title)
        .getByRole('button', { name: `Reopen ${title}` })
        .click();
    await expect(page.getByRole('status').filter({ hasText: 'Task reopened.' })).toBeVisible();
    await expect(rowOf(page, title)).toHaveCount(0);

    await page.goto(`/projects/${projectId}/board`);
    await expect(page.getByRole('link', { name: title })).toBeVisible();
});

test('filters, search and sort live in the URL, history restores each state, and an unlabeled id stays clearable', async ({
    page,
    cleanup,
    contextFor,
}) => {
    await signedIn(page);
    await seedAssigned(page, cleanup, 'E2E WP4 filter project', ['E2E WP4 alpha', 'E2E WP4 beta']);

    await page.goto('/tasks');
    // The title is each row's first link (the context link comes after it).
    const titles = () =>
        page
            .getByRole('row')
            .filter({ hasText: 'E2E WP4' })
            .evaluateAll((rows) => rows.map((row) => row.querySelector('a')?.textContent));

    // Search commits on Enter and is title-only, from the URL.
    await page.getByRole('searchbox', { name: 'Search tasks' }).fill('E2E WP4');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/q=E2E(\+|%20)WP4/);
    // The default sort is due date, undated rows newest first.
    await expect.poll(titles).toEqual(['E2E WP4 beta', 'E2E WP4 alpha']);

    // Sort is a server sort: Title takes its natural ascending order, then Descending reverses it.
    await page.getByLabel('Sort by').selectOption({ label: 'Title' });
    await expect(page).toHaveURL(/sort=title/);
    await expect.poll(titles).toEqual(['E2E WP4 alpha', 'E2E WP4 beta']);
    await page.getByLabel('Order').selectOption({ label: 'Descending' });
    await expect(page).toHaveURL(/dir=desc/);
    await expect.poll(titles).toEqual(['E2E WP4 beta', 'E2E WP4 alpha']);

    // A narrowing filter shows a chip and, with no match, the filtered-empty state.
    await page
        .getByRole('group', { name: 'Priority' })
        .getByRole('button', { name: 'Critical' })
        .click();
    await expect(page).toHaveURL(/priority/);
    await expect(page.getByRole('list', { name: 'Active filters' })).toContainText(
        'Priority: Critical',
    );
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();

    // Browser history restores the previous query state, in both directions.
    await page.goBack();
    await expect(page).not.toHaveURL(/priority/);
    await expect.poll(titles).toEqual(['E2E WP4 beta', 'E2E WP4 alpha']);
    await page.goForward();
    await expect(page).toHaveURL(/priority/);
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();

    // Clear filters drops the filters and the search, and keeps the view and the sort.
    await page
        .getByRole('group', { name: 'Filter tasks' })
        .getByRole('button', { name: 'Clear filters' })
        .click();
    await expect(page).not.toHaveURL(/priority|q=/);
    await expect(page).toHaveURL(/sort=title/);

    // Owner decision B: a well-formed id the server did not label is applied (zero rows), shown as
    // a type-only chip, and cleared like any other. No id or name is shown.
    await page.goto('/tasks?project=987654');
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();
    await expect(page.getByRole('list', { name: 'Active filters' })).toContainText(
        'Project filter',
    );
    await expect(page.locator('main')).not.toContainText('987654');
    await page.getByRole('button', { name: 'Remove filter: Project filter' }).click();
    await expect(page).not.toHaveURL(/project=/);

    // All Tasks is the operator's (tasks.view_all); the assignee filter exists only there.
    await page.goto('/tasks?view=all');
    await expect(page.getByRole('heading', { level: 1, name: 'All tasks' })).toBeVisible();
    await expect(page.getByRole('combobox', { name: 'Assignee' })).toBeVisible();

    // A viewer without the permission asking for view=all is served My tasks, with no Assignee
    // control and no All tasks view in the drawer: the server, not the URL, decides.
    const memberContext = await contextFor('member');
    try {
        const memberPage = await memberContext.newPage();
        await memberPage.goto('/tasks?view=all');
        await expect(memberPage.getByRole('heading', { level: 1, name: 'My tasks' })).toBeVisible();
        await expect(memberPage.getByRole('combobox', { name: 'Assignee' })).toHaveCount(0);
        await memberPage.getByRole('button', { name: 'Show workspace views' }).click();
        await expect(drawerLink(memberPage, 'Tasks', 'My tasks')).toBeVisible();
        await expect(drawerLink(memberPage, 'Tasks', 'All tasks')).toHaveCount(0);
    } finally {
        await memberContext.close();
    }
});

test('row shortcuts move focus, E completes and hands focus to the next row, and X with the bulk bar completes the selection', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const titles = ['E2E WP4 key a', 'E2E WP4 key b', 'E2E WP4 key c'];
    await seedAssigned(page, cleanup, 'E2E WP4 keyboard project', titles);

    await page.goto('/tasks?q=E2E+WP4+key&sort=title');
    const [a, b, c] = titles.map((title) => rowOf(page, title));
    await expect(c).toBeVisible();

    // J / K move the row focus (rows are in title order); they are inert while focus is in a text field.
    await a.focus();
    await page.keyboard.press('j');
    await expect(b).toBeFocused();
    await page.keyboard.press('k');
    await expect(a).toBeFocused();
    await page.getByRole('searchbox', { name: 'Search tasks' }).focus();
    await page.keyboard.type('jxe');
    await expect(page.getByRole('toolbar', { name: 'Bulk actions' })).toHaveCount(0);
    await expect(page.getByRole('searchbox', { name: 'Search tasks' })).toHaveValue(
        'E2E WP4 keyjxe',
    );
    await page.getByRole('searchbox', { name: 'Search tasks' }).fill('E2E WP4 key');

    // E completes the focused row and Direction D §14.2 hands focus to the next one.
    await a.focus();
    await page.keyboard.press('e');
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    await expect(a).toHaveCount(0);
    await expect(b).toBeFocused();

    // X selects, the bulk bar names the count, Escape clears, and Complete is one request.
    await b.focus();
    await page.keyboard.press('x');
    await page.keyboard.press('j');
    await page.keyboard.press('x');
    const bar = page.getByRole('toolbar', { name: 'Bulk actions' });
    await expect(bar).toContainText('2 tasks selected');
    await page.keyboard.press('Escape');
    await expect(bar).toHaveCount(0);

    await page.keyboard.press('x');
    await page.keyboard.press('k');
    await page.keyboard.press('x');
    await expect(bar).toContainText('2 tasks selected');
    await bar.getByRole('button', { name: 'Complete' }).click();
    await expect(page.getByRole('status').filter({ hasText: '2 tasks completed.' })).toBeVisible();
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();
    await expect(bar).toHaveCount(0);
    // The bar (and the rows) the user was in are gone; the empty state has focus rather than the document.
    await expect(
        page
            .locator('#main-content [tabindex="-1"]')
            .filter({ hasText: 'No tasks match these filters.' }),
    ).toBeFocused();

    // All three are Done now: the Done view lists them.
    await page.goto('/tasks?q=E2E+WP4+key&completion=done');
    await expect(page.getByRole('row').filter({ hasText: 'E2E WP4 key' })).toHaveCount(3);
});

test('the list is a strict two-band row at 390px for ordinary and worst-case rows, and stays a table above S, in both themes', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    // No expired-session, throttled or server-error response anywhere in the flow.
    const failures: string[] = [];
    page.on('response', (response) => {
        const status = response.status();
        if (status === 419 || status === 429 || status >= 500) {
            failures.push(`${status} ${response.url()}`);
        }
    });
    // The ordinary row that failed the third-line audit: In progress-like status, Medium, a project,
    // and the widest ordinary due date.
    const ordinary = 'E2E D9 ordinary dated task';
    await seedAssigned(page, cleanup, 'E2E D9 ordinary project', [ordinary], {
        due: '2099-12-31',
    });
    // The worst case with permitted values: a long title, a long project name, an overdue Critical
    // task with a running timer (mocked at the active-timers endpoint, so no timer row is written and
    // the parallel Time specs are undisturbed).
    const worst =
        'E2E D9 phone task with a deliberately long title that has to run past the right edge of a phone';
    const worstProject = 'E2E D9 phone project with a deliberately very long name that cannot fit';
    const worstProjectId = await seedAssigned(page, cleanup, worstProject, [worst], {
        priority: 'Critical',
        due: '2020-01-01',
    });
    await page.goto(`/projects/${worstProjectId}/board`);
    const worstHref = await page.getByRole('link', { name: worst }).getAttribute('href');
    const worstTaskId = Number(worstHref!.match(/\/tasks\/(\d+)$/)![1]);
    await page.route('**/time/timers/active', (route) =>
        route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify([
                {
                    id: 987654,
                    started_at: new Date().toISOString(),
                    server_now: new Date().toISOString(),
                    description: null,
                    context: { type: 'Task', id: worstTaskId, label: worst, url: null },
                },
            ]),
        }),
    );

    // A standalone task: the tag, a long title and a due date. Completed at the end, like the other
    // standalone fixtures (A9.7 closes in WP7).
    const standalone = `E2E D9 standalone phone task with a title long enough to need truncating ${Date.now()}`;
    await page.goto('/tasks');
    await page.getByRole('button', { name: 'New task' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New task' });
    await dialog.getByLabel(/Title/).fill(standalone);
    await dialog.getByLabel('Due date').fill('2099-12-31');
    await dialog.getByRole('button', { name: 'Create task' }).click();
    await expect(dialog).toHaveCount(0);

    try {
        for (const theme of ['light', 'dark'] as const) {
            await page.evaluate((value) => localStorage.setItem('theme', value), theme);

            // The S boundary is 768: 767 reflows, 768 is a table.
            for (const [width, display] of [
                [390, 'flex'],
                [767, 'flex'],
                [768, 'table-row'],
                [1400, 'table-row'],
            ] as const) {
                await page.setViewportSize({ width, height: 900 });
                await page.goto('/tasks?sort=title&dir=asc&q=E2E+D9');
                await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
                const at = `${theme} ${width}`;

                expect(await hasHorizontalOverflow(page), `${at} document overflow`).toBe(false);
                // The running state really renders (the worst case is not silently the plain row).
                await expect(
                    rowOf(page, worst).getByText('Timer running'),
                    `${at} timer state`,
                ).toBeVisible();
                await expect(
                    rowOf(page, worst).getByText('Overdue'),
                    `${at} overdue cue`,
                ).toBeAttached();

                for (const title of [ordinary, worst, standalone]) {
                    const row = rowOf(page, title);
                    await expect(row).toBeVisible();
                    expect(
                        await row.evaluate((node) => getComputedStyle(node).display),
                        `${at} ${title} row display`,
                    ).toBe(display);

                    // The full title is always in the DOM, whatever is drawn.
                    await expect(row.getByText(title, { exact: true })).toHaveCount(1);

                    if (width >= 768) continue;

                    // D9, strict: the selection, ring and title cells are one band; the status, priority,
                    // context and due cells are the second; nothing else. A cell that wraps (its box
                    // taller than one text line) or drops to a third line fails here.
                    const cells = await row.evaluate((node) => {
                        const rect = (cell: Element) => {
                            const box = cell.getBoundingClientRect();

                            return {
                                top: box.top,
                                bottom: box.bottom,
                                left: box.left,
                                right: box.right,
                            };
                        };
                        const tds = [...node.querySelectorAll(':scope > td')];

                        return {
                            row: rect(node),
                            // 0 selection, 1 ring, 2 title, 3 status, 4 priority, 5 context, 6 assignee (WP5: the
                            // compact control when assignable), 7 due
                            first: [0, 1, 2].map((i) => rect(tds[i]!)),
                            second: [3, 4, 5, 6, 7].map((i) => rect(tds[i]!)),
                            titleScroll: (() => {
                                const text = tds[2]!.querySelector('a, span');

                                return text ? [text.scrollWidth, text.clientWidth] : [0, 0];
                            })(),
                        };
                    });
                    const mid = (box: { top: number; bottom: number }) =>
                        (box.top + box.bottom) / 2;
                    const firstMid = mid(cells.first[1]!);
                    const secondMid = mid(cells.second[1]!);

                    for (const box of cells.first) {
                        expect(
                            Math.abs(mid(box) - firstMid),
                            `${at} ${title} first band centre`,
                        ).toBeLessThan(6);
                        // One text line (a wrapped title or tag makes the cell taller than this).
                        expect(
                            box.bottom - box.top,
                            `${at} ${title} first band is one line`,
                        ).toBeLessThanOrEqual(44);
                    }
                    for (const box of cells.second) {
                        expect(
                            Math.abs(mid(box) - secondMid),
                            `${at} ${title} second band centre`,
                        ).toBeLessThan(6);
                        expect(
                            box.bottom - box.top,
                            `${at} ${title} second band is one line`,
                        ).toBeLessThanOrEqual(28);
                    }
                    expect(
                        secondMid - firstMid,
                        `${at} ${title} bands are distinct`,
                    ).toBeGreaterThan(24);
                    expect(
                        cells.row.bottom - cells.row.top,
                        `${at} ${title} two bands tall`,
                    ).toBeLessThanOrEqual(96);
                    for (const box of [...cells.first, ...cells.second]) {
                        expect(box.left, `${at} ${title} inside left`).toBeGreaterThanOrEqual(-1);
                        expect(box.right, `${at} ${title} inside right`).toBeLessThanOrEqual(
                            width + 1,
                        );
                    }

                    const ring = await row
                        .getByRole('button', { name: /^Complete / })
                        .boundingBox();
                    expect(ring!.width, `${at} ring hit area`).toBeGreaterThanOrEqual(36);
                }

                // A long title is cut visually, not shortened.
                if (width === 390) {
                    const [scroll, client] = await rowOf(page, worst)
                        .getByRole('link', { name: worst })
                        .evaluate((node) => [node.scrollWidth, node.clientWidth]);
                    expect(scroll, 'the long title is truncated, not wrapped').toBeGreaterThan(
                        client,
                    );

                    // The hidden select-all is not a focus stop, and every control left in the table can be seen.
                    await expect(
                        page.getByRole('checkbox', { name: 'Select all tasks' }),
                    ).toBeHidden();
                    const unseen = await page.evaluate(() =>
                        [...document.querySelectorAll('table input, table button, table a')]
                            .filter((node) => {
                                // `display: none` is not a Tab stop at all; what must not exist is a
                                // control that is rendered, so focusable, yet cannot be seen.
                                if (getComputedStyle(node).display === 'none') return false;
                                const box = node.getBoundingClientRect();

                                return (
                                    box.width < 2 ||
                                    box.height < 2 ||
                                    getComputedStyle(node).visibility === 'hidden'
                                );
                            })
                            .map((node) => node.outerHTML.slice(0, 80)),
                    );
                    expect(unseen, `${at} focusable controls that cannot be seen`).toEqual([]);
                } else if (width === 1400) {
                    await expect(
                        page.getByRole('checkbox', { name: 'Select all tasks' }),
                    ).toBeVisible();
                }
            }
        }
    } finally {
        await page.evaluate(() => localStorage.setItem('theme', 'light'));
        await page.setViewportSize({ width: 1400, height: 900 });
        await page.goto('/tasks?q=E2E+D9+standalone+phone');
        await rowOf(page, standalone)
            .getByRole('button', { name: `Complete ${standalone}` })
            .click();
        await expect(rowOf(page, standalone)).toHaveCount(0);
    }
    expect(failures).toEqual([]);
});

test('removing a filter chip leaves focus on a surviving filter control', async ({ page }) => {
    await signedIn(page);
    await page.goto('/tasks?completion=any&priority=high');
    const chips = page.getByRole('list', { name: 'Active filters' });
    await expect(chips.getByRole('button')).toHaveCount(2);

    await chips.getByRole('button', { name: 'Remove filter: Completion: Any' }).click();
    await expect(chips.getByRole('button')).toHaveCount(1);
    await expect(
        chips.getByRole('button', { name: 'Remove filter: Priority: High' }),
    ).toBeFocused();

    await chips.getByRole('button', { name: 'Remove filter: Priority: High' }).click();
    await expect(chips).toHaveCount(0);
    await expect(page.getByRole('searchbox', { name: 'Search tasks' })).toBeFocused();
});

test('completing the only visible row hands focus to the empty state, and clearing a selection hands it back to a row', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const titles = ['E2E WP4 last a', 'E2E WP4 last b'];
    await seedAssigned(page, cleanup, 'E2E WP4 last row project', titles);

    await page.goto('/tasks?q=E2E+WP4+last&sort=title');
    const [a, b] = titles.map((title) => rowOf(page, title));
    await expect(b).toBeVisible();

    // Clear selection removes the bar the user was in; focus returns to the row that held it.
    await b.focus();
    await page.keyboard.press('x');
    const bar = page.getByRole('toolbar', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Clear selection' }).click();
    await expect(bar).toHaveCount(0);
    await expect(b).toBeFocused();

    // Complete one: focus goes to the other; Complete that: nothing is left, and the empty state has it.
    await a.focus();
    await page.keyboard.press('e');
    await expect(a).toHaveCount(0);
    await expect(b).toBeFocused();
    await page.keyboard.press('e');
    await expect(b).toHaveCount(0);
    const empty = page
        .locator('#main-content [tabindex="-1"]')
        .filter({ hasText: 'No tasks match these filters.' });
    await expect(empty).toBeFocused();
});
