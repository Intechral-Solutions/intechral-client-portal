import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';
import { timerPill } from './support/shell';

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
    // EPIC-015 WP2 (Q5): creation lands on the project Overview; this spec works on the board.
    await expect(page).toHaveURL(/\/projects\/(\d+)$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)$/)![1]);
    cleanup.trackProject(projectId);
    await page.goto(`/projects/${projectId}/board`);

    return projectId;
}

async function quickAdd(page: Page, columnName: string, title: string) {
    await page.getByRole('button', { name: `Add task to ${columnName}` }).click();
    await page.getByLabel('New task title').fill(title);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    await expect(page.getByRole('link', { name: title })).toBeVisible();
}

/** The "Time" panel (a labelled section), scoped away from the persistent bar's own identically-named controls. */
function timePanel(page: Page): Locator {
    return page.getByRole('region', { name: 'Time' });
}

test('board to task detail and back over Inertia, with the persistent timer surviving navigation', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP7 task nav project');
    await quickAdd(page, 'Backlog', 'E2E WP7 nav task');

    // exact: true — the timer pill renders its own "Task: <title>" label once the timer
    // starts below, which is otherwise a substring match for the same accessible name.
    await page.getByRole('link', { name: 'E2E WP7 nav task', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    const taskUrl = page.url();

    // Start the task's own timer through the persistent provider, from the task page itself.
    await timePanel(page).getByRole('button', { name: 'Start timer' }).click();
    await expect(
        timePanel(page).getByRole('button', { name: 'Stop timer: this task' }),
    ).toBeVisible();
    await expect(timerPill(page)).toContainText('E2E WP7 nav task');

    // Board and back: an Inertia navigation, so the timer pill is never remounted. Since EPIC-015
    // WP2 the trail's project segment opens the project's Overview; its navigation reaches the board.
    await page.getByRole('link', { name: 'E2E WP7 task nav project', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}$`));
    await expect(timerPill(page)).toBeVisible();
    await page.getByRole('navigation', { name: 'Project' }).getByRole('link', { name: 'Board' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/board$`));
    await expect(timerPill(page)).toBeVisible();

    await page.getByRole('link', { name: 'E2E WP7 nav task', exact: true }).click();
    await expect(page).toHaveURL(taskUrl);
    await expect(
        timePanel(page).getByRole('button', { name: 'Stop timer: this task' }),
    ).toBeVisible();

    // Stop from the task panel; the summary updates without a full reload.
    await timePanel(page)
        .getByRole('button', { name: /^Stop timer/ })
        .click();
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

    // WP5: the edit form is a dialog opened from the entity header, over the same endpoint.
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Edit task' });
    await dialog.getByLabel('Title').fill('E2E WP7 edited title');
    await dialog.getByLabel('Priority').selectOption('high');
    await dialog.getByLabel('Milestone').selectOption({ label: 'E2E milestone' });
    await dialog.getByLabel('Description', { exact: true }).fill('Edited from the task page.');
    await dialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(dialog).toHaveCount(0);

    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E WP7 edited title' }),
    ).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'High' })).toBeVisible();
    await expect(page.getByRole('definition').filter({ hasText: 'E2E milestone' })).toBeVisible();
    await expect(
        page.getByRole('paragraph').filter({ hasText: 'Edited from the task page.' }),
    ).toBeVisible();

    // Clearing the milestone is a supported edit (§12).
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    await page.getByRole('dialog', { name: 'Edit task' }).getByLabel('Milestone').selectOption('');
    await page
        .getByRole('dialog', { name: 'Edit task' })
        .getByRole('button', { name: 'Save changes' })
        .click();
    await expect(page.getByRole('definition').filter({ hasText: 'E2E milestone' })).toHaveCount(0);
});

test('a manager adds a checklist item; a plain member can toggle it but not author or remove it (D5)', async ({
    page,
    contextFor,
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

    const memberContext = await contextFor('member');
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
    await expect(
        timePanel(page).getByRole('button', { name: 'Stop timer: this task' }),
    ).toBeVisible();
    await timePanel(page)
        .getByRole('button', { name: /^Stop timer/ })
        .click();
    await expect(timePanel(page).getByRole('button', { name: 'Start timer' })).toBeVisible();

    await page.getByRole('button', { name: 'Delete task' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete task' }).click();
    await expect(page.getByRole('alert')).toContainText(
        'This task has recorded time and cannot be deleted.',
        { timeout: 15000 },
    );
    await expect(page).toHaveURL(blockedTaskUrl);
});
