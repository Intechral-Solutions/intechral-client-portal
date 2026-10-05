import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-015 WP3 critical flows: a project's Tasks tab (`projects.tasks.index`). The Overview's counts
 * open it already filtered; the four-link project navigation reaches it and leaves it; it lists this
 * project's board tasks and nothing else, with the milestone in place of a project column; filters,
 * search and sort live in the URL and survive back/forward; row and bulk actions reuse the Tasks
 * endpoints; a customer member gets the same rows with only their own actions; and the page, its
 * navigation strip and its rows hold together at 390px and on desktop, light and dark.
 *
 * Every DTO, filter and authorization permutation is covered by Pest (ProjectTaskQueryTest,
 * ProjectTasksPageTest) and Vitest; this proves the real page in a real browser. It runs against the
 * shared development database: every project is registered with `cleanup.trackProject` as soon as
 * its id is known, and its milestones and tasks cascade with it.
 */

type Seeded = {
    projectId: number;
    milestones: { launch: number; empty: number };
};

const T = {
    overdue: 'E2E WP3 overdue launch task',
    docs: 'E2E WP3 open docs task',
    done: 'E2E WP3 released task',
    soon: 'E2E WP3 next week task',
    elsewhere: 'E2E WP3 task in another project',
};

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

            return { status: response.status };
        },
        { method, url, body },
    );
}

/** The Inertia page object the server sent with the current document. */
async function pageProps<T>(page: Page): Promise<T> {
    const raw = await page.evaluate(() => {
        const script = document.querySelector('script[data-page]');
        const element = document.querySelector('[data-page]');

        return script?.textContent ?? element?.getAttribute('data-page') ?? '{}';
    });

    return (JSON.parse(raw) as { props: T }).props;
}

function isoDay(offsetDays: number) {
    const day = new Date();
    day.setUTCDate(day.getUTCDate() + offsetDays);

    return day.toISOString().slice(0, 10);
}

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

/**
 * A project with two milestones (one with tasks, one without) and four board tasks across columns,
 * plus a second project with one task that must never appear. Created through the real routes.
 */
async function seed(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
): Promise<Seeded> {
    await signedIn(page);
    const otherId = await createProject(page, cleanup, `${name} (other)`);
    const projectId = await createProject(page, cleanup, name);

    for (const [milestone, offset] of [
        ['E2E WP3 Launch', 10],
        ['E2E WP3 Empty checkpoint', 20],
    ] as const) {
        expect(
            (
                await send(page, 'POST', `/projects/${projectId}/milestones`, {
                    name: milestone,
                    due_date: isoDay(offset),
                })
            ).status,
        ).toBeLessThan(400);
    }

    await page.goto(`/projects/${projectId}`);
    const overview = await pageProps<{
        milestones: { items: { id: number; name: string }[] };
        members: { id: number; name: string }[];
    }>(page);
    const launch = overview.milestones.items.find((item) => item.name === 'E2E WP3 Launch')!.id;
    const empty = overview.milestones.items.find(
        (item) => item.name === 'E2E WP3 Empty checkpoint',
    )!.id;
    const user = overview.members.find((member) => member.name === 'Dev User')!.id;
    const operator = overview.members.find((member) => member.name !== 'Dev User')!.id;

    const columnsOf = async (id: number) => {
        await page.goto(`/projects/${id}/board`);
        const board = await pageProps<{ columns: { id: number; name: string; isDone: boolean }[] }>(
            page,
        );

        return board.columns;
    };
    const columns = await columnsOf(projectId);
    const [first, second] = columns.filter((column) => !column.isDone);
    const doneColumn = columns.find((column) => column.isDone)!;

    const tasks: [string, Record<string, unknown>][] = [
        [
            T.overdue,
            {
                column_id: first!.id,
                priority: 'high',
                due_date: isoDay(-2),
                milestone_id: launch,
                assignee_id: user,
            },
        ],
        [T.docs, { column_id: second!.id, priority: 'low' }],
        [
            T.done,
            {
                column_id: doneColumn.id,
                priority: 'medium',
                milestone_id: launch,
                assignee_id: operator,
            },
        ],
        [
            T.soon,
            {
                column_id: second!.id,
                priority: 'critical',
                due_date: isoDay(3),
                assignee_id: operator,
            },
        ],
    ];
    for (const [title, attributes] of tasks) {
        expect(
            (await send(page, 'POST', `/projects/${projectId}/tasks`, { title, ...attributes }))
                .status,
        ).toBeLessThan(400);
    }

    const [otherColumn] = await columnsOf(otherId);
    expect(
        (
            await send(page, 'POST', `/projects/${otherId}/tasks`, {
                title: T.elsewhere,
                column_id: otherColumn!.id,
                priority: 'high',
                due_date: isoDay(-2),
            })
        ).status,
    ).toBeLessThan(400);

    return { projectId, milestones: { launch, empty } };
}

function projectNav(page: Page) {
    return page.getByRole('navigation', { name: 'Project', exact: true });
}

function table(page: Page) {
    return page.getByRole('table', { name: 'Tasks' });
}

function rowOf(page: Page, title: string): Locator {
    return table(page).getByRole('row').filter({ hasText: title });
}

async function expectRows(page: Page, titles: string[]) {
    await expect(table(page).getByRole('link', { name: /^E2E WP3/ })).toHaveText(titles);
}

async function expectOneBreadcrumbAndHeading(page: Page) {
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
}

test('the Overview counts open the filtered Tasks tab, and the tabs lead everywhere and back', async ({
    page,
    cleanup,
}) => {
    // One linear journey whose cost is `seed()` (two projects through the real UI, ~20s alone), so it
    // gets the tripled timeout rather than a split that would pay the seed twice.
    test.slow();
    const { projectId } = await seed(page, cleanup, 'E2E WP3 navigation project');

    // Overview -> Tasks through the overdue figure: the list shows exactly what was counted.
    await page.goto(`/projects/${projectId}`);
    const summary = page.getByRole('main').getByRole('region', { name: 'Tasks' });
    await summary.getByRole('link', { name: '1 overdue task: view in Tasks' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks?completion=open&due=overdue`);
    await expectRows(page, [T.overdue]);
    await expect(page.getByRole('button', { name: /Due: Overdue/ })).toBeVisible();
    await expectOneBreadcrumbAndHeading(page);
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E WP3 navigation project' }),
    ).toBeVisible();

    // The workspace strip, Tasks current, page links rather than ARIA tabs. FLIPPED IN EPIC-015 WP5:
    // the operator holds a time permission, so the optional Time tab is the fifth link.
    const nav = projectNav(page);
    await expect(nav.getByRole('link')).toHaveText([
        'Overview',
        'Board',
        'Tasks',
        'Milestones',
        'Time',
    ]);
    await expect(nav.getByRole('link', { name: 'Tasks' })).toHaveAttribute('aria-current', 'page');
    await expect(nav.locator('[aria-current="page"]')).toHaveCount(1);
    await expect(page.getByRole('tablist')).toHaveCount(0);
    await expect(page.getByRole('tab')).toHaveCount(0);

    // The shell's one trail: the project segment opens the Overview, the page is "Tasks".
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs.locator('[aria-current="page"]')).toHaveText('Tasks');
    await expect(
        page.getByRole('main').getByRole('navigation', { name: 'Breadcrumb' }),
    ).toHaveCount(0);

    // The open-tasks figure carries completion=open, and the section link the plain list.
    await page.goto(`/projects/${projectId}`);
    await summary.getByRole('link', { name: '3 open tasks: view in Tasks' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks?completion=open`);
    await expect(table(page).getByRole('row')).toHaveCount(4); // header + 3 open rows
    await page.goto(`/projects/${projectId}`);
    await summary.getByRole('link', { name: 'View tasks' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks`);

    // Tasks -> Board, and back to the Overview through the trail (the Board gets tabs in WP4).
    await projectNav(page).getByRole('link', { name: 'Board' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/board`);
    await expect(page.getByRole('region', { name: 'Kanban board' })).toBeVisible();
    await page.goto(`/projects/${projectId}/tasks`);
    await projectNav(page).getByRole('link', { name: 'Milestones' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/milestones`);
    await page.goto(`/projects/${projectId}/tasks`);
    await projectNav(page).getByRole('link', { name: 'Overview' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);
    await page.goto(`/projects/${projectId}/tasks`);
    await crumbs.getByRole('link', { name: 'E2E WP3 navigation project' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}`);

    // Forced overflow (a test fixture, not a breakpoint contract): the four links fit at 390px, so
    // narrow the strip itself until they cannot, and prove it scrolls instead of clipping. Kept in
    // this (already slow) journey so the responsive test below stays within its own budget.
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto(`/projects/${projectId}/tasks`);
    const strip = projectNav(page);
    await strip.evaluate((node) => {
        node.style.width = '180px';
        node.style.maxWidth = '180px';
    });
    const [scrollWidth, clientWidth] = await strip.evaluate((node) => [
        node.scrollWidth,
        node.clientWidth,
    ]);
    expect(scrollWidth, 'forced: the strip overflows its own box').toBeGreaterThan(clientWidth);
    expect(await hasHorizontalOverflow(page), 'forced: the document does not overflow').toBe(false);

    // The last link starts off-screen inside the strip, and scrolling brings it fully into view.
    // EPIC-015 WP5: with the optional Time tab offered, Time is the last of five links.
    const last = strip.getByRole('link', { name: 'Time' });
    await expect(last, 'forced: Time starts clipped').not.toBeInViewport({ ratio: 1 });
    await last.scrollIntoViewIfNeeded();
    await expect(last, 'forced: Time scrolled into view').toBeInViewport({ ratio: 1 });
    expect(
        await strip.evaluate((node) => node.scrollLeft),
        'forced: the strip itself scrolled',
    ).toBeGreaterThan(0);

    // Keyboard focus on the off-screen link is visible, ringed and not clipped.
    await last.focus();
    await expect(last, 'forced: Time focusable').toBeFocused();
    await expect(last, 'forced: focused link in view').toBeInViewport({ ratio: 1 });
    const ring = await last.evaluate((node) => {
        const style = getComputedStyle(node);

        return { style: style.outlineStyle, offset: parseFloat(style.outlineOffset) };
    });
    expect(ring.style, 'forced: focus outline').not.toBe('none');
    expect(ring.offset, 'forced: focus outline inset').toBeLessThanOrEqual(0);
    expect(await hasHorizontalOverflow(page), 'forced: still no document overflow').toBe(false);
});

test('only this project’s board tasks, with the milestone and no project column; filters live in the URL', async ({
    page,
    cleanup,
}) => {
    // Measured 30.8-35.8 s under the focused 3-worker set in EPIC-015 WP4 (a 30 s timeout hit): the
    // cost is `seed()` through the real UI, so this one test gets the tripled timeout (A3.12 #7).
    test.slow();
    const { projectId, milestones } = await seed(page, cleanup, 'E2E WP3 filter project');

    await page.goto(`/projects/${projectId}/tasks?completion=any&sort=title`);
    await expectRows(page, [T.docs, T.overdue, T.soon, T.done].sort());
    await expect(table(page)).not.toContainText(T.elsewhere);
    const headers = table(page).getByRole('columnheader');
    await expect(headers.filter({ hasText: 'Milestone' })).toHaveCount(1);
    await expect(headers.filter({ hasText: 'Context' })).toHaveCount(0);
    await expect(rowOf(page, T.overdue).locator('[data-cell="milestone"]')).toContainText(
        'E2E WP3 Launch',
    );
    await expect(table(page)).not.toContainText('E2E WP3 filter project');

    // Milestone: a URL change, rows narrowed, the chip named from the server's options.
    await page
        .getByRole('combobox', { name: 'Milestone', exact: true })
        .selectOption({ label: 'E2E WP3 Launch' });
    await expect(page).toHaveURL(new RegExp(`[?&]milestone=${milestones.launch}(&|$)`));
    expect(new URL(page.url()).searchParams.get('completion')).toBe('any');
    await expectRows(page, [T.overdue, T.done].sort());
    await expect(page.getByRole('button', { name: /Milestone: E2E WP3 Launch/ })).toBeVisible();

    // A milestone with no tasks is kept and simply matches nothing.
    await page
        .getByRole('combobox', { name: 'Milestone', exact: true })
        .selectOption({ label: 'E2E WP3 Empty checkpoint' });
    await expect(page.getByText('No tasks match these filters.')).toBeVisible();

    // Assignee, then search: each a visit, each in the URL.
    await page
        .getByRole('combobox', { name: 'Milestone', exact: true })
        .selectOption({ label: 'All milestones' });
    await expect(page).not.toHaveURL(/milestone=/);
    await page
        .getByRole('combobox', { name: 'Assignee', exact: true })
        .selectOption({ label: 'Unassigned' });
    await expect(page).toHaveURL(/assignee=none/);
    await expectRows(page, [T.docs]);
    await page
        .getByRole('combobox', { name: 'Assignee', exact: true })
        .selectOption({ label: 'All assignees' });
    await expect(page).not.toHaveURL(/assignee=/);
    await page.getByRole('searchbox', { name: 'Search tasks' }).fill('launch');
    await page.getByRole('searchbox', { name: 'Search tasks' }).press('Enter');
    await expect(page).toHaveURL(/q=launch/);
    await expectRows(page, [T.overdue]);

    // Back restores the previous state, forward the later one, both from the URL alone.
    await page.goBack();
    await expect(page).not.toHaveURL(/q=launch/);
    await expectRows(page, [T.docs, T.overdue, T.soon, T.done].sort());
    await expect(page.getByRole('searchbox', { name: 'Search tasks' })).toHaveValue('');
    await page.goBack();
    await expect(page).toHaveURL(/assignee=none/);
    await expect(page.getByRole('combobox', { name: 'Assignee', exact: true })).toHaveValue('none');
    await expectRows(page, [T.docs]);
    await page.goForward();
    await page.goForward();
    await expect(page).toHaveURL(/q=launch/);
    await expectRows(page, [T.overdue]);

    // Board order: the board's own column, then position order.
    await page.goto(`/projects/${projectId}/tasks?completion=any`);
    await page
        .getByRole('combobox', { name: 'Sort by', exact: true })
        .selectOption({ label: 'Board order' });
    await expect(page).toHaveURL(/sort=board/);
    await expectRows(page, [T.overdue, T.docs, T.soon, T.done]);

    // A forged or foreign parameter never widens the list or labels anything.
    await page.goto(`/projects/${projectId}/tasks?completion=any&milestone=999999&kind=standalone`);
    await expect(page.getByRole('combobox', { name: 'Milestone', exact: true })).toHaveValue('');
    await expect(table(page).getByRole('link', { name: /^E2E WP3/ })).toHaveCount(4);
});

test('row and bulk actions reuse the Tasks endpoints and come back to this tab', async ({
    page,
    cleanup,
}) => {
    // Measured 28.2-36.7 s under the focused 3-worker set in EPIC-015 WP4 (a 30 s timeout hit): the
    // cost is `seed()` through the real UI, so this one test gets the tripled timeout (A3.12 #7).
    test.slow();
    const { projectId } = await seed(page, cleanup, 'E2E WP3 actions project');
    await page.goto(`/projects/${projectId}/tasks`);
    await expectRows(page, [T.overdue, T.soon, T.docs]);

    // Complete from the keyboard: the row leaves the open list and focus moves to the next row.
    await rowOf(page, T.soon).focus();
    await page.keyboard.press('e');
    await expect(page.getByRole('status').filter({ hasText: 'Task completed.' })).toBeVisible();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks`);
    await expectRows(page, [T.overdue, T.docs]);
    await expect(rowOf(page, T.docs)).toBeFocused();

    // Assign from the row's menu (the operator manages this project).
    await rowOf(page, T.docs)
        .getByRole('button', { name: new RegExp(`Change assignee of “${T.docs}”`) })
        .click();
    await page.getByRole('menuitemradio', { name: 'Dev User' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Assignee updated.' })).toBeVisible();
    await expect(rowOf(page, T.docs)).toContainText('Dev User');

    // Bulk Complete, then bulk Reopen from the done list.
    await rowOf(page, T.overdue).getByRole('checkbox').check();
    await rowOf(page, T.docs).getByRole('checkbox').check();
    const bar = page.getByRole('toolbar', { name: 'Bulk actions' });
    await bar.getByRole('button', { name: 'Complete' }).click();
    await expect(page.getByRole('status').filter({ hasText: '2 tasks completed.' })).toBeVisible();
    await expect(page.getByText('No open tasks')).toBeVisible();
    await expect(page.getByText('Every task in this project is done.')).toBeVisible();

    await page.getByRole('button', { name: 'Show all tasks' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/tasks?completion=any`);
    await rowOf(page, T.overdue).getByRole('checkbox').check();
    await bar.getByRole('button', { name: 'Reopen' }).click();
    await expect(page.getByRole('status').filter({ hasText: '1 task reopened.' })).toBeVisible();
    await page.goto(`/projects/${projectId}/tasks`);
    await expectRows(page, [T.overdue]);

    // No create action here: tasks are created on the Board (P8).
    await expect(page.getByRole('button', { name: /new task/i })).toHaveCount(0);
});

test('a customer member sees the same project rows with only their own actions, and nothing gated', async ({
    page,
    contextFor,
    cleanup,
}) => {
    // Reported 30-33 s in EPIC-015 WP4, and timed out at 30 s in the independent review (system-wide
    // 0.6-2 s request latency): the cost is `seed()` through the real UI, so this one test gets the
    // tripled timeout (A3.12 #7).
    test.slow();
    const { projectId } = await seed(page, cleanup, 'E2E WP3 customer project');

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(`/projects/${projectId}/tasks`);
        await expectRows(memberPage, [T.overdue, T.soon, T.docs]);
        await expect(table(memberPage)).not.toContainText(T.elsewhere);
        await expect(
            memberPage.getByRole('main').getByRole('link', { name: 'Settings' }),
        ).toHaveCount(0);

        // Complete only where TaskPolicy allows it: the member's own assigned task.
        await expect(
            rowOf(memberPage, T.overdue).getByRole('button', { name: `Complete ${T.overdue}` }),
        ).toBeVisible();
        await expect(
            rowOf(memberPage, T.soon).getByRole('button', { name: /^Complete / }),
        ).toHaveCount(0);
        // No assignment control: assignment is a manager's.
        await expect(
            table(memberPage).getByRole('button', { name: /Change assignee/ }),
        ).toHaveCount(0);

        // The page's own props: the shell's shared `auth` carries the viewer's own account.
        const own = await pageProps<Record<string, unknown>>(memberPage);
        delete own.auth;
        delete own.navigation;
        const props = JSON.stringify(own);
        expect(props).toContain('"assignee":{"id"');
        expect(props).not.toContain('@intechral.test');
        expect(props).not.toContain('openSettings');
        expect(props).not.toContain(T.elsewhere);
    } finally {
        await memberContext.close();
    }
});

test('the Tasks tab, its four-link strip and its rows hold together at 390px and desktop, light and dark', async ({
    page,
    cleanup,
}) => {
    // Reported 30-33 s in EPIC-015 WP4, and timed out at 30 s in the independent review (system-wide
    // 0.6-2 s request latency): 4 widths/themes after a UI-seeded project, so this one test gets the
    // tripled timeout (A3.12 #7).
    test.slow();
    const { projectId } = await seed(
        page,
        cleanup,
        'E2E WP3 responsive project with a deliberately long name for phones',
    );

    for (const width of [390, 1440]) {
        for (const theme of ['light', 'dark'] as const) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/projects/${projectId}/tasks`);
            await page.evaluate((value) => localStorage.setItem('theme', value), theme);
            await page.reload();
            const at = `${width}px ${theme}`;

            await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
            await expect(table(page), at).toBeVisible();
            expect(await hasHorizontalOverflow(page), `${at} document overflow`).toBe(false);
            await expectOneBreadcrumbAndHeading(page);

            // The navigation component itself: it scrolls inside its own box if it must, never
            // clips a link beyond reach, and the current Tasks link can be brought into view.
            const nav = projectNav(page);
            const [scrollWidth, clientWidth, overflowX] = await nav.evaluate((node) => [
                node.scrollWidth,
                node.clientWidth,
                getComputedStyle(node).overflowX,
            ]);
            expect(overflowX, `${at} nav scrolls rather than wraps`).toBe('auto');
            const navBox = (await nav.boundingBox())!;
            expect(navBox.x, `${at} nav inside left`).toBeGreaterThanOrEqual(0);
            expect(navBox.x + navBox.width, `${at} nav inside right`).toBeLessThanOrEqual(
                width + 1,
            );
            expect(
                await nav.getByRole('list').evaluate((node) => getComputedStyle(node).flexWrap),
                `${at} one line of links`,
            ).toBe('nowrap');
            for (const label of ['Overview', 'Board', 'Tasks', 'Milestones', 'Time']) {
                const link = nav.getByRole('link', { name: label });
                await link.scrollIntoViewIfNeeded();
                await expect(link, `${at} ${label} reachable`).toBeInViewport();
            }
            if (scrollWidth > clientWidth) {
                // Scrolled, not clipped: the strip's last link is reachable inside the strip.
                const last = nav.getByRole('link', { name: 'Time' });
                await last.scrollIntoViewIfNeeded();
                await expect(last, `${at} last link scrolled into view`).toBeInViewport({
                    ratio: 1,
                });
            }

            // Keyboard: the Tasks link takes focus with a visible ring drawn inside its own box, so
            // the strip's overflow can never clip it.
            const tasksLink = nav.getByRole('link', { name: 'Tasks' });
            await tasksLink.focus();
            await expect(tasksLink, `${at} Tasks focusable`).toBeFocused();
            const ring = await tasksLink.evaluate((node) => {
                const style = getComputedStyle(node);

                return { style: style.outlineStyle, offset: parseFloat(style.outlineOffset) };
            });
            expect(ring.style, `${at} focus outline`).not.toBe('none');
            expect(ring.offset, `${at} focus outline inset`).toBeLessThanOrEqual(0);
            await expect(tasksLink, `${at} focused Tasks link in view`).toBeInViewport({
                ratio: 1,
            });

            // Rows: at S a strict two bands, the milestone the flexible item of the second.
            const row = rowOf(page, T.overdue);
            await expect(row).toBeVisible();
            if (width === 390) {
                const bands = await row.evaluate((node) => {
                    const mid = (cell: Element) => {
                        const box = cell.getBoundingClientRect();

                        return (box.top + box.bottom) / 2;
                    };
                    const title = node.querySelector('td a')!.closest('td')!;
                    const milestone = node.querySelector('[data-cell="milestone"]')!.closest('td')!;
                    const due = node.querySelector('[data-cell="due"]')!.closest('td')!;
                    const box = node.getBoundingClientRect();

                    return {
                        title: mid(title),
                        milestone: mid(milestone),
                        due: mid(due),
                        height: box.bottom - box.top,
                        right: Math.max(
                            ...[...node.querySelectorAll(':scope > td')].map(
                                (cell) => cell.getBoundingClientRect().right,
                            ),
                        ),
                    };
                });
                expect(
                    Math.abs(bands.milestone - bands.due),
                    `${at} milestone on the second band`,
                ).toBeLessThan(6);
                expect(bands.milestone - bands.title, `${at} bands are distinct`).toBeGreaterThan(
                    24,
                );
                expect(bands.height, `${at} two bands tall`).toBeLessThanOrEqual(96);
                expect(bands.right, `${at} row inside the viewport`).toBeLessThanOrEqual(width + 1);
                await expect(
                    row.getByRole('button', { name: `Complete ${T.overdue}` }),
                    `${at} ring reachable`,
                ).toBeInViewport();
                await expect(row.getByRole('checkbox'), `${at} selection reachable`).toBeVisible();
            } else {
                expect(
                    await row.evaluate((node) => getComputedStyle(node).display),
                    `${at} a table row`,
                ).toBe('table-row');
            }
        }
    }

    await page.evaluate(() => localStorage.removeItem('theme'));
});
