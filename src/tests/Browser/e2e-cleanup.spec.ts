import type { Page } from '@playwright/test';
import { E2eCleanup, expect, standaloneTaskId, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';

/**
 * EPIC-014 WP7, A9.7: the cleanup fixture itself. Standalone tasks used to outlive the browser suite
 * (two per run, from `tasks-migration.spec.ts`), because there was no delete route and later no
 * registration. These flows pin the repair at the level of the individual row, not only through
 * `./dev test:e2e`'s count warning:
 *
 *  - a registered task is removed by the fixture's teardown even when the test FAILS after creating
 *    it, and it is that exact task that is gone;
 *  - a standalone task created on the page but never registered fails the teardown by name.
 *
 * Every removal goes through `DELETE /tasks/{task}`, the supported route; nothing here touches SQL.
 */

async function createStandalone(page: Page, title: string) {
    await page.goto('/tasks');
    await page.getByRole('button', { name: 'New task' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'New task' });
    await dialog.getByLabel(/Title/).fill(title);
    await dialog.getByRole('button', { name: 'Create task' }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('status').filter({ hasText: 'Task created.' })).toBeVisible();
}

test.describe('a registered standalone task is removed by teardown, not by the happy path', () => {
    // In order, in one worker: the second test reads what the first registered.
    test.describe.configure({ mode: 'serial' });
    let registered = 0;

    test('a test that fails after registering its standalone task', async ({ page, cleanup }) => {
        test.fail(true, 'Deliberate: only the fixture teardown can remove the task.');
        await signedIn(page);
        const title = `E2E WP7 teardown ${Date.now()}`;
        await createStandalone(page, title);
        registered = await standaloneTaskId(page, title);
        cleanup.trackTask(registered);

        throw new Error('Deliberate failure after the task was registered.');
    });

    test('leaves exactly that task deleted', async ({ page }) => {
        expect(registered, 'the failing test registered a task').toBeGreaterThan(0);
        await signedIn(page);

        expect((await page.request.get(`/tasks/${registered}`)).status()).toBe(404);
    });
});

test('a standalone task created on the page but never registered fails the teardown', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    // A second cleanup on the same page watches the same responses as the fixture's own.
    const probe = new E2eCleanup(page);
    const title = `E2E WP7 unregistered ${Date.now()}`;
    await createStandalone(page, title);

    await expect(probe.run()).rejects.toThrow(/never registered with cleanup\.trackTask/);

    // Registered, the same teardown passes and removes it; the fixture's own then sees a 404.
    const id = await standaloneTaskId(page, title);
    cleanup.trackTask(id);
    probe.trackTask(id);
    await probe.run();
    expect((await page.request.get(`/tasks/${id}`)).status()).toBe(404);
});
