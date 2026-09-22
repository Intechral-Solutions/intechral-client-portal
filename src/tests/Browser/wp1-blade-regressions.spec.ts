import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signIn } from './support/sign-in';

/**
 * EPIC-011E WP1 browser regression for the Blade board. Temporary: deleted together with
 * `projects/board.blade.php` (WP5); its React successor gets equivalent coverage.
 *
 * S1, S2 and the project half of D4, previously here against the Blade create/edit pages, are
 * superseded now that those pages are React (WP3): see `projects-migration.spec.ts`.
 *
 * Runs against the shared development database, so the project is registered for the fixture
 * teardown.
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

test('D1: a plain project member gets a read-only board and can still comment', async ({
    page,
    browser,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E D1 read-only board');

    // Manager: quick-add is offered and draggable cards exist.
    await page.locator('.add-task-btn').first().click();
    await page.getByPlaceholder('Task title…').first().fill('E2E D1 task');
    await page.getByRole('button', { name: 'Add', exact: true }).first().click();
    await expect(page.getByRole('link', { name: 'E2E D1 task' })).toBeVisible();
    await expect(page.locator('.task-card[draggable="true"]')).toHaveCount(1);

    // Administrators may add members: pick the dev user through the React member editor.
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Update members' }).click();
    await expect(page.getByRole('status')).toContainText('Members updated.');

    // The plain member sees the board without any structural control.
    const memberContext = await browser.newContext();
    const memberPage = await memberContext.newPage();
    try {
        await signIn(memberPage, 'user@intechral.test');
        await memberPage.goto(`/projects/${projectId}/board`);
        await expect(memberPage.getByRole('link', { name: 'E2E D1 task' })).toBeVisible();
        await expect(memberPage.locator('.add-task-btn')).toHaveCount(0);
        await expect(memberPage.locator('[draggable="true"]')).toHaveCount(0);
        await expect(memberPage.getByRole('link', { name: 'Settings' })).toHaveCount(0);

        await memberPage.getByRole('link', { name: 'E2E D1 task' }).click();
        await expect(memberPage.getByText('Edit Task')).toHaveCount(0);
        await expect(memberPage.getByRole('button', { name: 'Delete Task' })).toHaveCount(0);

        // Collaboration stays open to members.
        await memberPage.getByPlaceholder('Add a comment…').fill('E2E member comment');
        await memberPage.getByRole('button', { name: 'Post Comment' }).click();
        await expect(memberPage.getByText('E2E member comment')).toBeVisible();
    } finally {
        await memberContext.close();
    }
});
