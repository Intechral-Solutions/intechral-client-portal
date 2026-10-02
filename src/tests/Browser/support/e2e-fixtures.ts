import type { Page } from '@playwright/test';

import { expect, test as base } from './auth';

/**
 * The browser suite runs against the shared development database, so every record a test
 * creates must be removed again, including when the test fails halfway.
 *
 * Timer entries are recorded from the start responses (their ids only exist in the response), the
 * project fixture is registered as soon as its URL is known, and a standalone task is registered
 * with `trackTask` as soon as its id is known (`standaloneTaskId`). The fixture teardown below runs
 * whether the test passed or not, and deletes everything through the same HTTP endpoints the
 * application exposes: never raw SQL. Deleting an entry cascades its allocation blocks; deleting the
 * project cascades its columns and tasks; a standalone task goes through `DELETE /tasks/{task}`, the
 * supported route with the recorded-time guard (EPIC-014 WP7 closed A9.7 this way; before WP2 there
 * was no such route and standalone tasks were left behind).
 *
 * A standalone task created on this page but never registered fails the teardown by name, so a
 * new leak is reported by the test that causes it, not only by `./dev test:e2e`'s count warning.
 * Creations are recognised by the store's own "Task created." flash on the redirect that follows
 * `POST /tasks`. Only the test's own `page` is watched: a task created from a `contextFor(...)`
 * page must be registered on that page's own cleanup (no spec does that today).
 */
export class E2eCleanup {
    private readonly timerEntryIds = new Set<number>();
    private readonly projectIds = new Set<number>();
    private readonly taskIds = new Set<number>();
    private standaloneCreations = 0;

    constructor(private readonly page: Page) {
        page.on('response', async (response) => {
            const origin = response.request().redirectedFrom();

            if (
                origin?.method() !== 'POST' ||
                new URL(origin.url()).pathname !== '/tasks' ||
                !response.ok()
            ) {
                return;
            }

            try {
                const body = (await response.json()) as {
                    props?: { flash?: { success?: string } };
                };
                if (body.props?.flash?.success === 'Task created.') this.standaloneCreations++;
            } catch {
                // Not an Inertia JSON response, or the page is already navigating away.
            }
        });

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

    /** A standalone task, removed through `DELETE /tasks/{task}`; one the test deleted itself 404s. */
    trackTask(id: number) {
        this.taskIds.add(id);
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

        // Last, after every time entry is gone: a task with recorded time is refused by design
        // (INV-11, a 422 here), and that refusal is reported like any other failed removal.
        for (const id of this.taskIds) {
            await remove(`standalone task ${id}`, `/tasks/${id}`);
        }

        if (this.standaloneCreations > this.taskIds.size) {
            failures.push(
                `${this.standaloneCreations - this.taskIds.size} standalone task(s) created on this page ` +
                    'were never registered with cleanup.trackTask, so they would be left behind',
            );
        }

        // Drain the session's flash bag. Tests in a worker share that worker's authenticated session
        // (support/auth.ts), and Laravel flash data survives exactly one subsequent request — so the
        // "Project deleted." this teardown just produced would otherwise be rendered by the NEXT
        // test in this worker, whose own `getByRole('status')` assertion would then resolve to the
        // wrong banner. One throwaway read consumes it, keeping the session free of this test's
        // leftovers.
        await page.goto('/time');

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
 * The id of the standalone task titled `title` (titles are run-unique), read from its row link. The
 * creator's own My Tasks always holds it, assigned to them or unassigned (Q4), so the search needs
 * no view. Leaves the page on that search.
 */
export async function standaloneTaskId(page: Page, title: string) {
    await page.goto(`/tasks?completion=any&kind=standalone&q=${encodeURIComponent(title)}`);
    const href = await page
        .getByRole('row')
        .filter({ hasText: title })
        .getByRole('link', { name: title, exact: true })
        .getAttribute('href');

    return Number(href!.match(/\/tasks\/(\d+)$/)![1]);
}

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
