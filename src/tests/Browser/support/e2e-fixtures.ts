import { expect, test as base } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The browser suite runs against the shared development database, so every record a test
 * creates must be removed again, including when the test fails halfway. Timer entries are
 * recorded from the start responses (their ids only exist in the response), the project
 * fixture is registered as soon as its URL is known, and the fixture teardown below deletes
 * everything through the same HTTP endpoints the application exposes. Deleting an entry
 * cascades its allocation blocks; deleting the project cascades its columns and tasks.
 */
export class E2eCleanup {
    private readonly timerEntryIds = new Set<number>();
    private readonly projectIds = new Set<number>();

    constructor(private readonly page: Page) {
        page.on('response', async (response) => {
            const request = response.request();

            if (
                request.method() !== 'POST' ||
                !response.ok() ||
                new URL(response.url()).pathname !== '/time/timer/start'
            ) {
                return;
            }

            try {
                this.timerEntryIds.add(((await response.json()) as { id: number }).id);
            } catch {
                // The page may already be navigating away; the entry then has no id to record.
            }
        });
    }

    /** A test may create more than one project (e.g. one per actor); every one is cleaned up. */
    trackProject(id: number) {
        this.projectIds.add(id);
    }

    async run() {
        const { page } = this;

        // A test that failed before signing in created nothing to remove.
        await page.goto('/time');
        if (!page.url().endsWith('/time')) return;

        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const headers = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token ?? '',
        };

        // Attempt every removal even if one fails, then report all failures together.
        const failures: string[] = [];
        const remove = async (label: string, url: string) => {
            const response = await page.request.delete(url, { headers, maxRedirects: 0 });

            if (![200, 204, 302, 404].includes(response.status())) {
                failures.push(`${label} (${url}) returned ${response.status()}`);
            }
        };

        for (const id of this.timerEntryIds) {
            await remove(`time entry ${id}`, `/time/${id}`);
        }

        for (const id of this.projectIds) {
            // A project the test itself already deleted (e.g. the delete-flow test) 404s here,
            // which `remove` already treats as success.
            await remove(`project ${id}`, `/projects/${id}`);
        }

        await this.deleteManualEntries();

        expect(failures, 'E2E fixture cleanup').toEqual([]);
    }

    /** Manual entries have no id in any response, so match the descriptions the tests use. */
    private async deleteManualEntries() {
        const { page } = this;
        await page.goto('/time');
        await page.waitForLoadState('networkidle');

        for (;;) {
            const row = page.getByRole('row').filter({ hasText: 'Browser manual entry' }).first();
            if ((await row.count()) === 0) break;

            await row.getByRole('button', { name: /Delete entry/ }).click();
            await page.getByRole('button', { name: 'Delete entry', exact: true }).click();
            await expect(
                page.getByRole('button', { name: 'Delete entry', exact: true }),
            ).toHaveCount(0);
        }
    }
}

export const test = base.extend<{ cleanup: E2eCleanup }>({
    cleanup: [
        async ({ page }, use) => {
            const cleanup = new E2eCleanup(page);
            await use(cleanup);
            await cleanup.run();
        },
        { auto: true },
    ],
});

export { expect };

/**
 * Timers that share a wall-clock 15-minute slot land in one allocation slot. Starting just
 * before a slot boundary could split the two test timers across slots, so wait it out.
 */
export async function avoidSlotBoundary(page: Page, marginMs = 25_000) {
    const untilBoundary = 900_000 - (Date.now() % 900_000);

    if (untilBoundary < marginMs) {
        await page.waitForTimeout(untilBoundary + 1_000);
    }
}
