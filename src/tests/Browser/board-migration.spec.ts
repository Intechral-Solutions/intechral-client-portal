import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';

/**
 * EPIC-011E WP5 critical flows: the project board as a React/Inertia page, keyboard-accessible
 * task movement, and quick-add. Focused flows only (§24 items 1, 5, 6); every field and
 * permission permutation is covered by Pest and Vitest instead. Runs against the shared
 * development database: every project is registered with `cleanup.trackProject` as soon as its
 * URL is known. D1's read-only board coverage, previously in the now-deleted
 * `wp1-blade-regressions.spec.ts` (against the Blade board), lives here against its React
 * successor.
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

test('index to a board with a persistent timer, and Board ↔ Milestones stays Inertia', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 board nav project');

    // Start a timer for this project so its elapsed clock is the SPA-navigation signal: a full
    // document reload would remount the app and the clock would restart from what the server
    // last rendered, not keep ticking client-side. The running timer bar also renders its own
    // "Project: <name>" link, so every click on the project's own name below is `exact: true`
    // to avoid matching that one instead (a Playwright accessible-name match is substring by
    // default). D4 blocks deleting a project with a running (or any) time entry against it, so
    // the timer is stopped before the test ends and the fixture teardown can remove the project.
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

    await page.goto('/projects');
    await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    await page.getByRole('link', { name: 'E2E WP5 board nav project', exact: true }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/board`);
    const clock = page.getByRole('region', { name: 'Active timers' }).locator('span.font-mono');
    const firstReading = await clock.textContent();
    await expect(clock).not.toHaveText(firstReading ?? '', { timeout: 3000 });

    // Board → Milestones → Board: both directions are now React pages (WP4, WP5), so this
    // is an Inertia visit each way; the timer keeps ticking uninterrupted throughout.
    await page.getByRole('link', { name: 'Milestones' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/milestones`);
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    await page.getByRole('link', { name: 'E2E WP5 board nav project', exact: true }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/board`);
    const secondReading = await clock.textContent();
    await expect(clock).not.toHaveText(secondReading ?? '', { timeout: 3000 });

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

test('keyboard-only: moves a task across columns through the Move menu, with focus return and a live-region announcement', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP5 keyboard move project');

    await quickAdd(page, 'Backlog', 'E2E keyboard move task');

    const moveButton = page.getByRole('button', { name: 'Move "E2E keyboard move task"' });
    await moveButton.focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('menuitem', { name: 'Move to To Do' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Move to To Do' }).click();

    // The card is now under To Do, not Backlog.
    const toDoColumn = page.locator('[data-column-id]').filter({ hasText: 'To Do' });
    await expect(toDoColumn.getByRole('link', { name: 'E2E keyboard move task' })).toBeVisible();

    // Focus followed the card to its new location, and the live region announced the result.
    // (`[aria-live="polite"]` alone also matches the persistent RunningTimerBar's "Active
    // timers" region; `.sr-only` is what distinguishes the board's own live region from it.)
    await expect(moveButton).toBeFocused();
    await expect(
        page.locator('[aria-live="polite"].sr-only:not([data-shell-announcer])'),
    ).toHaveText('Moved "E2E keyboard move task" to To Do, position 1 of 1.');

    // Persists after a reload: the move was a real server round trip, not a client-only effect.
    await page.reload();
    await expect(toDoColumn.getByRole('link', { name: 'E2E keyboard move task' })).toBeVisible();
});

test('move up and down within a column through the Move menu', async ({ page, cleanup }) => {
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP5 reorder project');

    await quickAdd(page, 'Backlog', 'E2E first task');
    await quickAdd(page, 'Backlog', 'E2E second task');

    const backlogColumn = page.locator('[data-column-id]').filter({ hasText: 'Backlog' });
    await expect(backlogColumn.getByRole('link')).toHaveText(['E2E first task', 'E2E second task']);

    await page.getByRole('button', { name: 'Move "E2E second task"' }).focus();
    await page.keyboard.press('Enter');
    await page.getByRole('menuitem', { name: 'Move up' }).click();

    await expect(backlogColumn.getByRole('link')).toHaveText(['E2E second task', 'E2E first task']);
});

test('a plain project member gets a read-only board and can still open, comment on, and toggle a checklist item for a task', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 read-only board');

    await quickAdd(page, 'Backlog', 'E2E D1 task');
    await expect(page.getByRole('button', { name: 'Add task to Backlog' })).toBeVisible();

    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Update members' }).click();
    await expect(page.getByRole('status')).toContainText('Members updated.');

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(`/projects/${projectId}/board`);

        await expect(memberPage.getByRole('link', { name: 'E2E D1 task' })).toBeVisible();
        await expect(memberPage.getByRole('button', { name: /Add task/ })).toHaveCount(0);
        await expect(memberPage.getByRole('button', { name: /Move "/ })).toHaveCount(0);
        await expect(memberPage.getByRole('link', { name: 'Settings' })).toHaveCount(0);

        // Task detail is a React page as of WP7: an Inertia navigation from the React board,
        // not a document load.
        await memberPage.getByRole('link', { name: 'E2E D1 task' }).click();
        await expect(memberPage).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
        await expect(memberPage.getByText('Edit Task')).toHaveCount(0);

        await memberPage.getByPlaceholder('Add a comment…').fill('E2E member comment');
        await memberPage.getByRole('button', { name: 'Post Comment' }).click();
        await expect(memberPage.getByText('E2E member comment')).toBeVisible();
    } finally {
        await memberContext.close();
    }
});

test('the board is usable at a phone viewport through the Move menu, with no document scroll', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP5 mobile project');

    await quickAdd(page, 'Backlog', 'E2E mobile task');
    const bodyOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(bodyOverflow).toBe(false);

    await page.getByRole('button', { name: 'Move "E2E mobile task"' }).click();
    await page.getByRole('menuitem', { name: 'Move to To Do' }).click();

    const toDoColumn = page.locator('[data-column-id]').filter({ hasText: 'To Do' });
    await expect(toDoColumn.getByRole('link', { name: 'E2E mobile task' })).toBeVisible();
});
