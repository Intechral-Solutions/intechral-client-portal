import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { personas, signedIn } from './support/auth';

/**
 * EPIC-011E WP7 critical flows: the project task detail page as a React/Inertia page (§24 item
 * 8), replacing the deleted `tasks/show.blade.php`. Focused flows only; every field and
 * permission permutation is covered by Pest (ProjectTaskDetailInertiaTest.php) and Vitest
 * instead. Runs against the shared development database: every project is registered with
 * `cleanup.trackProject` as soon as its URL is known.
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

/** The "Time" panel, scoped away from the persistent bar's own identically-named controls. */
function timePanel(page: Page): Locator {
    return page.getByRole('heading', { name: 'Time' }).locator('..');
}

test('board to task detail and back over Inertia, with the persistent timer surviving navigation', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP7 task nav project');
    await quickAdd(page, 'Backlog', 'E2E WP7 nav task');

    // exact: true — the running timer bar renders its own "Task: <title>" link once the timer
    // starts below, which is otherwise a substring match for the same accessible name.
    await page.getByRole('link', { name: 'E2E WP7 nav task', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const taskUrl = page.url();

    // Start the task's own timer through the persistent provider, from the task page itself.
    await timePanel(page).getByRole('button', { name: 'Start timer' }).click();
    await expect(page.getByText('Running for this task')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Active timers' })).toContainText(
        'E2E WP7 nav task',
    );

    // Board and back: an Inertia navigation, so the running timer bar is never remounted.
    await page.getByRole('link', { name: 'E2E WP7 task nav project', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/board$`));
    await expect(page.getByRole('region', { name: 'Active timers' })).toBeVisible();

    await page.getByRole('link', { name: 'E2E WP7 nav task', exact: true }).click();
    await expect(page).toHaveURL(taskUrl);
    await expect(page.getByText('Running for this task')).toBeVisible();

    // Stop from the task panel; the summary updates without a full reload.
    await timePanel(page).getByRole('button', { name: 'Stop timer' }).click();
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();
    await expect(timePanel(page).getByText(/time logged/)).toBeVisible();
});

test('a manager edits task fields and assigns, then clears, a milestone (I4)', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP7 edit project');

    await page.goto(`/projects/${projectId}/milestones`);
    await page.getByRole('button', { name: 'New milestone' }).click();
    await page.getByLabel('Name', { exact: false }).fill('E2E milestone');
    await page.getByLabel('Due date', { exact: false }).fill('2030-01-01');
    await page.getByRole('button', { name: 'Create milestone' }).click();
    await expect(page.getByText('E2E milestone')).toBeVisible();

    await page.goto(`/projects/${projectId}/board`);
    await quickAdd(page, 'Backlog', 'E2E WP7 edit task');
    await page.getByRole('link', { name: 'E2E WP7 edit task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));

    await page.getByLabel('Title').fill('E2E WP7 edited title');
    await page.getByLabel('Priority').selectOption('high');
    await page.getByLabel('Milestone').selectOption({ label: 'E2E milestone' });
    // exact: true — otherwise matches the timer panel's "Timer description" field too.
    await page.getByLabel('Description', { exact: true }).fill('Edited from the task page.');
    await page.getByRole('button', { name: 'Save changes' }).click();

    await expect(page.getByRole('heading', { name: 'E2E WP7 edited title' })).toBeVisible();
    // Scoped to the header's badges: the Priority <select> also has an "High" option.
    await expect(page.locator('header').getByText('High', { exact: true })).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'E2E milestone' })).toBeVisible();
    // Scoped to the read-only paragraph: the still-mounted edit form's own textarea holds the
    // identical value.
    await expect(
        page.getByRole('paragraph').filter({ hasText: 'Edited from the task page.' }),
    ).toBeVisible();

    // Clearing the milestone is a supported edit (§12).
    await page.getByLabel('Milestone').selectOption('');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByLabel('Milestone')).toHaveValue('');
});

test('a manager adds a checklist item; a plain member can toggle it but not author or remove it (D5)', async ({
    page,
    browser,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP7 checklist project');
    await quickAdd(page, 'Backlog', 'E2E WP7 checklist task');

    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Update members' }).click();
    await expect(page.getByRole('status')).toContainText('Members updated.');

    await page.goto(`/projects/${projectId}/board`);
    await page.getByRole('link', { name: 'E2E WP7 checklist task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const taskUrl = page.url();

    await page.getByPlaceholder('Add an item…').fill('E2E checklist item');
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    const checkbox = page.getByRole('checkbox', { name: 'E2E checklist item' });
    await expect(checkbox).toBeVisible();
    await expect(checkbox).not.toBeChecked();

    const memberContext = await browser.newContext({ storageState: personas.member.storageState });
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(taskUrl);

        const memberCheckbox = memberPage.getByRole('checkbox', { name: 'E2E checklist item' });
        await expect(memberCheckbox).toBeVisible();
        await expect(
            memberPage.getByRole('button', { name: /Remove "E2E checklist item"/ }),
        ).toHaveCount(0);
        await expect(memberPage.getByPlaceholder('Add an item…')).toHaveCount(0);

        await memberCheckbox.click();
        await expect(memberCheckbox).toBeChecked();
    } finally {
        await memberContext.close();
    }

    // The manager's own view reconciles to the same authoritative state.
    await page.reload();
    await expect(page.getByRole('checkbox', { name: 'E2E checklist item' })).toBeChecked();
});

test('an unreferenced task deletes; a task with recorded time is blocked and historical time is untouched (D4)', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP7 delete project');

    await quickAdd(page, 'Backlog', 'E2E WP7 deletable task');
    await page.getByRole('link', { name: 'E2E WP7 deletable task' }).click();
    await page.getByRole('button', { name: 'Delete task' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/board$`), { timeout: 15000 });
    await expect(page.getByRole('link', { name: 'E2E WP7 deletable task' })).toHaveCount(0);

    await quickAdd(page, 'Backlog', 'E2E WP7 blocked task');
    await page.getByRole('link', { name: 'E2E WP7 blocked task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const blockedTaskUrl = page.url();

    // Recorded time against the task (a completed entry, via start-then-stop): D4 must refuse
    // the delete even though the timer is no longer running.
    await timePanel(page).getByRole('button', { name: 'Start timer' }).click();
    await expect(page.getByText('Running for this task')).toBeVisible();
    await timePanel(page).getByRole('button', { name: 'Stop timer' }).click();
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();

    await page.getByRole('button', { name: 'Delete task' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete task' }).click();
    await expect(page.getByRole('alert')).toContainText(
        'This task has recorded time and cannot be deleted.',
        { timeout: 15000 },
    );
    await expect(page).toHaveURL(blockedTaskUrl);
});
