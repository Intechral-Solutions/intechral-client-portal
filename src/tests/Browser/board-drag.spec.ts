import { devices } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { E2eCleanup, expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';

/**
 * EPIC-011E WP6 critical flows: pointer/touch drag through dnd-kit, layered over the WP5 board.
 * Every flow that has a keyboard/Move-menu equivalent is covered there already
 * (`board-migration.spec.ts`); this file is pointer- and touch-specific (§24 item 4, WP6
 * remediation §24 items 1-3, 4-5, 6, 7-9, 11-13). Field and permission permutations stay in Pest
 * and Vitest. Runs against the shared development database; every project is registered with
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

function columnByName(page: Page, name: string): Locator {
    return page.locator('[data-column-id]').filter({ hasText: name });
}

function cardByTitle(page: Page, title: string): Locator {
    return page.getByRole('article', { name: title });
}

/** The card's pointer/touch drag affordance: present only for a manager, aria-hidden, no role. */
function handleFor(page: Page, title: string): Locator {
    return cardByTitle(page, title).getByTestId('task-drag-handle');
}

/**
 * Presses the pointer down on `handle` and moves it just past dnd-kit's 6px activation distance,
 * leaving the drag "live" (mouse still down) for the caller to steer and release. dnd-kit's
 * `PointerSensor` measures activation distance and updates collisions on animation frames, not
 * on the raw event stream, so a short settle after the initial move gives that first frame a
 * chance to run before further input arrives.
 */
async function beginDrag(page: Page, handle: Locator) {
    const start = await handle.boundingBox();
    if (!start) throw new Error('drag handle has no bounding box');

    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(start.x + start.width / 2 + 15, start.y + start.height / 2 + 15, {
        steps: 5,
    });
    await page.waitForTimeout(75);
}

/** Moves the live pointer to the given page coordinates, settling briefly for dnd-kit's own
 * `onDragOver`-driven collision/preview update to run before the next input. */
async function moveDragTo(page: Page, x: number, y: number) {
    await page.mouse.move(x, y, { steps: 20 });
    await page.waitForTimeout(100);
    // Re-aim once more at the same, now-settled coordinates: the preview may itself have shifted
    // the layout under the pointer since the move above started (WP0's "drop below the last
    // card" finding — re-aiming after the shift is what made that drop land reliably).
    await page.mouse.move(x, y, { steps: 5 });
    await page.waitForTimeout(100);
}

async function endDrag(page: Page) {
    await page.mouse.up();
    await page.waitForTimeout(100);
}

/** A complete drag: down on `handle`, over to the middle of `target` (or `target` plus `offset`
 * if given), then up. */
async function dragTo(
    page: Page,
    handle: Locator,
    target: Locator,
    offset?: { x: number; y: number },
) {
    await beginDrag(page, handle);

    const end = await target.boundingBox();
    if (!end) throw new Error('drop target has no bounding box');
    await moveDragTo(
        page,
        end.x + (offset?.x ?? end.width / 2),
        end.y + (offset?.y ?? end.height / 2),
    );

    await endDrag(page);
}

/**
 * Waits until no move is in flight. A drop's optimistic transform paints the card in its new
 * column immediately, so asserting on the moved card only proves the guess landed, not that the
 * server accepted it — reloading at that point aborts the still-pending PUT and the move is lost.
 * `aria-busy` on the board region is exactly the single-flight guard's own signal: it is cleared
 * in the move's `onFinish`, i.e. once the authoritative response has arrived (EPIC-011E §8).
 * Every test that reloads to prove persistence must await this first (found in WP10: the two
 * reload tests below failed under full-suite host contention for this reason alone, and passed
 * whenever the request happened to win the race).
 */
async function expectMoveSettled(page: Page) {
    await expect(page.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'false',
    );
}

test.describe('pointer drag (EPIC-011E WP6)', () => {
    test('drags a card upward within a column by its handle', async ({ page, cleanup }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 drag up project');
        await quickAdd(page, 'Backlog', 'E2E drag first');
        await quickAdd(page, 'Backlog', 'E2E drag second');

        const backlog = columnByName(page, 'Backlog');
        await expect(backlog.getByRole('link')).toHaveText(['E2E drag first', 'E2E drag second']);

        await dragTo(page, handleFor(page, 'E2E drag second'), cardByTitle(page, 'E2E drag first'));

        await expect(backlog.getByRole('link')).toHaveText(['E2E drag second', 'E2E drag first']);
        await expectMoveSettled(page);
        await page.reload();
        await expect(backlog.getByRole('link')).toHaveText(['E2E drag second', 'E2E drag first']);
    });

    test('drags a card downward within a column by its handle', async ({ page, cleanup }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 drag down project');
        await quickAdd(page, 'Backlog', 'E2E down first');
        await quickAdd(page, 'Backlog', 'E2E down second');

        const backlog = columnByName(page, 'Backlog');

        await dragTo(page, handleFor(page, 'E2E down first'), cardByTitle(page, 'E2E down second'));

        await expect(backlog.getByRole('link')).toHaveText(['E2E down second', 'E2E down first']);
    });

    test('drags a card across columns and it persists after a reload', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 cross-column project');
        await quickAdd(page, 'Backlog', 'E2E cross column task');

        const toDo = columnByName(page, 'To Do');

        await dragTo(page, handleFor(page, 'E2E cross column task'), toDo);

        await expect(toDo.getByRole('link', { name: 'E2E cross column task' })).toBeVisible();
        await expectMoveSettled(page);
        await page.reload();
        await expect(toDo.getByRole('link', { name: 'E2E cross column task' })).toBeVisible();
    });

    test('drops a card into an empty column', async ({ page, cleanup }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 empty column project');
        await quickAdd(page, 'Backlog', 'E2E empty column task');

        const done = columnByName(page, 'Done');
        await expect(done.getByRole('link')).toHaveCount(0);

        await dragTo(page, handleFor(page, 'E2E empty column task'), done);

        await expect(done.getByRole('link', { name: 'E2E empty column task' })).toBeVisible();
    });

    test('drops a card after the last card in a populated column (append)', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 append project');
        await quickAdd(page, 'To Do', 'E2E append existing');
        // Padding cards in a sibling column only, so the board's flex columns (default
        // align-items: stretch) give To Do real empty body space below its own single card to
        // aim at — with every column sized to its own one-card content there is nothing "below
        // the last card" to land on at all.
        await quickAdd(page, 'Backlog', 'E2E append padding one');
        await quickAdd(page, 'Backlog', 'E2E append padding two');
        await quickAdd(page, 'Backlog', 'E2E append padding three');
        await quickAdd(page, 'Backlog', 'E2E append dragged');

        const toDo = columnByName(page, 'To Do');

        // Aim near the bottom of the column's own (now-stretched) body, below its one card,
        // resolving to the column's own droppable id rather than the card itself
        // (EPIC-011E §9, WP0-proven).
        await dragTo(page, handleFor(page, 'E2E append dragged'), toDo, {
            x: (await toDo.boundingBox())!.width / 2,
            y: (await toDo.boundingBox())!.height - 10,
        });

        await expect(toDo.getByRole('link')).toHaveText([
            'E2E append existing',
            'E2E append dragged',
        ]);
    });

    test('a drag that ends over no valid target sends no request and leaves the board unchanged', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 invalid drop project');
        await quickAdd(page, 'Backlog', 'E2E invalid drop task');

        const backlog = columnByName(page, 'Backlog');
        const handle = handleFor(page, 'E2E invalid drop task');
        const start = (await handle.boundingBox())!;

        await beginDrag(page, handle);
        // Above the board entirely: not a droppable column body, not a card.
        await moveDragTo(page, start.x, 4);
        await endDrag(page);

        // Still exactly where it started, and no live-region announcement of a move that never
        // happened.
        await expect(backlog.getByRole('link', { name: 'E2E invalid drop task' })).toBeVisible();
        await expect(
            page.locator(
                // Excludes the shell's announcer and WP6's timer-pill announcer, leaving the board's.
                '[aria-live="polite"].sr-only:not([data-shell-announcer]):not([data-shell-timer-announce])',
            ),
        ).toHaveText('');
    });

    test('single-flight: the Move menu is disabled while a dragged move is reconciling, and re-enabled once it settles', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 busy project');
        await quickAdd(page, 'Backlog', 'E2E busy task one');
        await quickAdd(page, 'Backlog', 'E2E busy task two');

        // A test-only network delay on the move response (nothing about the product's own
        // timing changes) so the busy window is wide enough to reliably observe in a real
        // browser, rather than racing a request that a local dev stack answers in a handful of
        // milliseconds.
        await page.route('**/tasks/*/move', async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 1500));
            await route.continue();
        });

        const toDo = columnByName(page, 'To Do');
        await dragTo(page, handleFor(page, 'E2E busy task one'), toDo);

        // While that move is still in flight, a different card's Move menu is disabled — the
        // same guard the Move menu's own single-flight test exercises, now driven by a drop
        // instead of a menu selection (EPIC-011E §8, WP6).
        const otherMoveButton = page.getByRole('button', { name: 'Move "E2E busy task two"' });
        await expect(otherMoveButton).toHaveAttribute('aria-disabled', 'true');

        // It settles and the board becomes usable again.
        await expect(otherMoveButton).not.toHaveAttribute('aria-disabled', 'true', {
            timeout: 5000,
        });
        await expect(toDo.getByRole('link', { name: 'E2E busy task one' })).toBeVisible();
    });

    test('handle-only: the card body does not start a drag, only the handle does', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 handle-only project');
        await quickAdd(page, 'Backlog', 'E2E body task');

        const backlog = columnByName(page, 'Backlog');
        const toDo = columnByName(page, 'To Do');
        const card = cardByTitle(page, 'E2E body task');
        const box = (await card.boundingBox())!;
        const target = (await toDo.boundingBox())!;

        // A drag gesture starting on the card body, not the handle.
        await page.mouse.move(box.x + box.width / 2, box.y + 8);
        await page.mouse.down();
        await moveDragTo(page, target.x + target.width / 2, target.y + target.height / 2);
        await endDrag(page);

        // Nothing moved: the body is not a drag activator.
        await expect(backlog.getByRole('link', { name: 'E2E body task' })).toBeVisible();
        await expect(toDo.getByRole('link', { name: 'E2E body task' })).toHaveCount(0);

        // The handle, on the same card, does work.
        await dragTo(page, handleFor(page, 'E2E body task'), toDo);
        await expect(toDo.getByRole('link', { name: 'E2E body task' })).toBeVisible();
    });

    test('the title link still navigates, and the Move menu is unaffected by dnd-kit being mounted', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        const projectId = await createProject(page, cleanup, 'E2E WP6 parity project');
        await quickAdd(page, 'Backlog', 'E2E parity task');

        await page.getByRole('link', { name: 'E2E parity task' }).click();
        await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
        await page.goBack();

        const toDo = columnByName(page, 'To Do');
        await page.getByRole('button', { name: 'Move "E2E parity task"' }).focus();
        await page.keyboard.press('Enter');
        await page.getByRole('menuitem', { name: 'Move to To Do' }).click();
        await expect(toDo.getByRole('link', { name: 'E2E parity task' })).toBeVisible();
    });

    test('a read-only member gets no drag handle and no Move menu', async ({
        page,
        contextFor,
        cleanup,
    }) => {
        await signedIn(page);
        const projectId = await createProject(page, cleanup, 'E2E WP6 read-only drag project');
        await quickAdd(page, 'Backlog', 'E2E read-only task');

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

            await expect(
                memberPage.getByRole('link', { name: 'E2E read-only task' }),
            ).toBeVisible();
            await expect(memberPage.getByTestId('task-drag-handle')).toHaveCount(0);
            await expect(memberPage.getByRole('button', { name: /Move "/ })).toHaveCount(0);
        } finally {
            await memberContext.close();
        }
    });

    test('a real drag lands through the same reconciliation machinery the Move menu uses', async ({
        page,
        cleanup,
    }) => {
        await signedIn(page);
        await createProject(page, cleanup, 'E2E WP6 reconcile project');
        await quickAdd(page, 'Backlog', 'E2E reconcile task');

        const backlog = columnByName(page, 'Backlog');
        const toDo = columnByName(page, 'To Do');

        // A genuine 4xx/5xx server rejection needs a throwaway endpoint a live server does not
        // offer; that path is exhaustively covered against the real reconciliation machinery in
        // board.test.tsx (Vitest), which can inject arbitrary failures deterministically
        // (EPIC-011E §24). What a real browser proves instead is the successful round trip:
        // drop → the optimistic guess → the authoritative response replacing it → persistence.
        await dragTo(page, handleFor(page, 'E2E reconcile task'), toDo);

        await expect(toDo.getByRole('link', { name: 'E2E reconcile task' })).toBeVisible();
        await expect(backlog.getByRole('link', { name: 'E2E reconcile task' })).toHaveCount(0);
        await expectMoveSettled(page);
        await page.reload();
        await expect(toDo.getByRole('link', { name: 'E2E reconcile task' })).toBeVisible();
    });
});

test.describe('touch (EPIC-011E WP6)', () => {
    test('a swipe on the card body scrolls the board; a touch drag from the handle moves the task', async ({
        contextFor,
    }) => {
        // Pixel 7's own 412px viewport fits barely more than one 288px column, which would put
        // "To Do" (the drop target) partly or wholly off-screen — a touch coordinate beyond the
        // visible viewport hits nothing, on a real device as much as here. A wider viewport (kept
        // touch-capable, mobile UA) keeps this test about real touch pointer events, not
        // off-screen scrolling — that is what the separate desktop autoscroll flow and the
        // phone-viewport Move-menu flow (board-migration.spec.ts) already cover.
        const context = await contextFor('operator', {
            ...devices['Pixel 7'],
            viewport: { width: 800, height: 700 },
        });
        const page = await context.newPage();
        const cleanup = new E2eCleanup(page);

        try {
            await signedIn(page);
            await createProject(page, cleanup, 'E2E WP6 touch project');
            await quickAdd(page, 'Backlog', 'E2E touch task');

            const board = page.getByRole('region', { name: 'Kanban board' });
            const client = await context.newCDPSession(page);
            const boardBox = (await board.boundingBox())!;

            const scrollBefore = await board.evaluate((el) => el.scrollLeft);

            // A swipe starting on the card body (not the handle): should scroll the board, not
            // start a drag.
            const cardBox = (await cardByTitle(page, 'E2E touch task').boundingBox())!;
            await client.send('Input.dispatchTouchEvent', {
                type: 'touchStart',
                touchPoints: [{ x: cardBox.x + cardBox.width / 2, y: cardBox.y + 10 }],
            });
            await page.waitForTimeout(50);
            await client.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: [{ x: boardBox.x + 10, y: cardBox.y + 10 }],
            });
            await page.waitForTimeout(50);
            await client.send('Input.dispatchTouchEvent', {
                type: 'touchEnd',
                touchPoints: [],
            });
            await page.waitForTimeout(100);

            const scrollAfter = await board.evaluate((el) => el.scrollLeft);
            expect(scrollAfter).not.toBe(scrollBefore);
            // No move was sent: the task is still in Backlog.
            await expect(
                columnByName(page, 'Backlog').getByRole('link', { name: 'E2E touch task' }),
            ).toBeVisible();

            // Reset scroll, then a touch drag from the handle.
            await board.evaluate((el) => (el.scrollLeft = 0));
            const handleBox = (await handleFor(page, 'E2E touch task').boundingBox())!;
            const toDoBox = (await columnByName(page, 'To Do').boundingBox())!;

            const handleStartX = handleBox.x + handleBox.width / 2;
            const handleStartY = handleBox.y + handleBox.height / 2;

            await client.send('Input.dispatchTouchEvent', {
                type: 'touchStart',
                touchPoints: [{ x: handleStartX, y: handleStartY, id: 1 }],
            });
            await page.waitForTimeout(75);
            // A first small move past the activation distance, held briefly, before the longer
            // travel toward the target — mirrors `beginDrag`'s pointer equivalent.
            await client.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: [{ x: handleStartX + 15, y: handleStartY + 15, id: 1 }],
            });
            await page.waitForTimeout(75);
            for (let step = 1; step <= 20; step++) {
                const x =
                    handleStartX + ((toDoBox.x + toDoBox.width / 2 - handleStartX) * step) / 20;
                const y =
                    handleStartY + ((toDoBox.y + toDoBox.height / 2 - handleStartY) * step) / 20;
                await client.send('Input.dispatchTouchEvent', {
                    type: 'touchMove',
                    touchPoints: [{ x, y, id: 1 }],
                });
                await page.waitForTimeout(25);
            }
            await page.waitForTimeout(150);
            await client.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });

            await expect(
                columnByName(page, 'To Do').getByRole('link', { name: 'E2E touch task' }),
            ).toBeVisible({ timeout: 10_000 });
        } finally {
            await cleanup.run();
            await context.close();
        }
    });
});

test('horizontal autoscroll: holding a drag near the right edge scrolls the board toward hidden columns', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize({ width: 500, height: 700 });
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP6 autoscroll project');
    await quickAdd(page, 'Backlog', 'E2E autoscroll task');

    const board = page.getByRole('region', { name: 'Kanban board' });
    const scrollBefore = await board.evaluate((el) => el.scrollLeft);

    const handle = handleFor(page, 'E2E autoscroll task');
    const boardBox = (await board.boundingBox())!;

    await beginDrag(page, handle);
    // Hold near the right edge of the narrowed viewport long enough for dnd-kit's autoscroll to
    // engage (WP0 measured full-scroll in ~360ms; this waits well past that).
    await page.mouse.move(boardBox.x + boardBox.width - 5, boardBox.y + boardBox.height / 2, {
        steps: 5,
    });
    await page.waitForTimeout(800);

    const scrollDuringDrag = await board.evaluate((el) => el.scrollLeft);
    expect(scrollDuringDrag).toBeGreaterThan(scrollBefore);

    // Release over whatever is now under the pointer, then confirm the board is left usable
    // (the exact destination is not the point of this flow — the scroll is).
    await endDrag(page);
    await expect(page.getByRole('region', { name: 'Kanban board' })).toBeVisible();
});

test('reduced motion: a drop still completes with no lingering overlay', async ({
    page,
    cleanup,
}) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP6 reduced motion project');
    await quickAdd(page, 'Backlog', 'E2E reduced motion task');

    const toDo = columnByName(page, 'To Do');
    await dragTo(page, handleFor(page, 'E2E reduced motion task'), toDo);

    await expect(toDo.getByRole('link', { name: 'E2E reduced motion task' })).toBeVisible();
    // The overlay's own drop animation is a decorative, aria-hidden node that WP0 measured at
    // ~12ms under reduced motion (vs. ~270ms normally); by the time the assertion above settles
    // it is long gone either way, so the only thing worth pinning here in a real browser is that
    // the drop still completes cleanly with this preference set.
    await expect(
        page.locator('[aria-hidden="true"]', { hasText: 'E2E reduced motion task' }),
    ).toHaveCount(0);
});
