import type { Locator, Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';

/**
 * EPIC-015 WP5 (optional S1) critical flows: Complete/Reopen straight from a Board card.
 *
 * The card's ring calls the same `tasks.complete` / `tasks.reopen` endpoints as the Tasks list and
 * task detail. The server moves the task (Done column / first open column) and the Board renders
 * the redirect's columns; nothing is moved in React. Focus follows the task to its control in the
 * new column. The ring is a sibling of the title link and the drag handle, so pressing it neither
 * opens the task nor starts a drag, and drag keeps working beside it. A customer member is offered
 * the ring only on a task they may complete (their own), and no drag or Move at all.
 *
 * Ability parity with TaskPolicy and the query budget are Pest's (ProjectBoardInertiaTest,
 * ProjectQueryBudgetTest); single flight, errors and memo behaviour are Vitest's. Runs against the
 * shared development database; every project is registered with `cleanup.trackProject` at once.
 */

async function createProject(
    page: Page,
    cleanup: { trackProject: (id: number) => void },
    name: string,
    withMember = false,
) {
    await page.goto('/projects/create');
    await page.getByLabel('Project name').fill(name);
    if (withMember) {
        await page.getByRole('button', { name: 'Add member' }).click();
        await page
            .getByRole('combobox', { name: 'Member 1', exact: true })
            .selectOption({ label: 'Dev User (user@intechral.test)' });
    }
    await page.getByRole('button', { name: 'Create project' }).click();
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

function column(page: Page, name: string): Locator {
    return page.locator('[data-column-id]').filter({
        has: page.getByRole('heading', { level: 2, name, exact: true }),
    });
}

function card(page: Page, title: string): Locator {
    return page.getByRole('article', { name: title });
}

async function expectSettled(page: Page) {
    await expect(page.getByRole('region', { name: 'Kanban board' })).toHaveAttribute(
        'aria-busy',
        'false',
    );
}

test('a manager completes and reopens from the card; the server moves it and focus follows', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 card completion project');
    await quickAdd(page, 'To Do', 'E2E card task');
    const boardUrl = new RegExp(`/projects/${projectId}/board$`);

    // Complete, by pointer: the card lands in Done (the server's move), not in a React guess.
    await card(page, 'E2E card task')
        .getByRole('button', { name: 'Complete E2E card task' })
        .click();
    await expect(
        column(page, 'Done').getByRole('article', { name: 'E2E card task' }),
    ).toBeVisible();
    await expect(column(page, 'To Do').getByRole('article', { name: 'E2E card task' })).toHaveCount(
        0,
    );
    await expectSettled(page);
    // The press neither opened the task nor left the board.
    await expect(page).toHaveURL(boardUrl);

    // Focus followed the task to its control, now named for the opposite action.
    const reopen = card(page, 'E2E card task').getByRole('button', {
        name: 'Reopen E2E card task',
    });
    await expect(reopen).toBeFocused();

    // Reopen, by keyboard from where focus already is: back to the first open column (EPIC-014 §8).
    await page.keyboard.press('Enter');
    await expect(
        column(page, 'Backlog').getByRole('article', { name: 'E2E card task' }),
    ).toBeVisible();
    await expectSettled(page);
    await expect(
        card(page, 'E2E card task').getByRole('button', { name: 'Complete E2E card task' }),
    ).toBeFocused();

    // It persisted: a fresh read agrees.
    await page.reload();
    await expect(
        column(page, 'Backlog').getByRole('article', { name: 'E2E card task' }),
    ).toBeVisible();
});

test('drag still works beside the ring, and the ring never starts a drag', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    await createProject(page, cleanup, 'E2E WP5 card drag project');
    await quickAdd(page, 'Backlog', 'E2E drag beside ring');

    // A pointer press-and-move that starts on the ring is not a drag: the card stays put.
    const ring = card(page, 'E2E drag beside ring').getByRole('button', {
        name: 'Complete E2E drag beside ring',
    });
    const ringBox = (await ring.boundingBox())!;
    const toDoBox = (await column(page, 'To Do').boundingBox())!;
    await page.mouse.move(ringBox.x + ringBox.width / 2, ringBox.y + ringBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(toDoBox.x + toDoBox.width / 2, toDoBox.y + 60, { steps: 20 });
    await page.mouse.up();
    // Releasing elsewhere is not a click on the ring, so nothing was sent either.
    await expectSettled(page);
    await expect(
        column(page, 'Backlog').getByRole('article', { name: 'E2E drag beside ring' }),
    ).toBeVisible();

    // The handle still drags the card across columns.
    const handle = card(page, 'E2E drag beside ring').getByTestId('task-drag-handle');
    const start = (await handle.boundingBox())!;
    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(start.x + start.width / 2 + 15, start.y + start.height / 2 + 15, {
        steps: 5,
    });
    await page.waitForTimeout(75);
    await page.mouse.move(toDoBox.x + toDoBox.width / 2, toDoBox.y + toDoBox.height / 2, {
        steps: 20,
    });
    await page.waitForTimeout(100);
    await page.mouse.move(toDoBox.x + toDoBox.width / 2, toDoBox.y + toDoBox.height / 2, {
        steps: 5,
    });
    await page.waitForTimeout(100);
    await page.mouse.up();

    await expect(
        column(page, 'To Do').getByRole('article', { name: 'E2E drag beside ring' }),
    ).toBeVisible();
    await expectSettled(page);

    // The Move menu and quick-add are untouched by the ring.
    await expect(
        card(page, 'E2E drag beside ring').getByRole('button', {
            name: 'Move "E2E drag beside ring"',
        }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Add task to Backlog' })).toBeVisible();
});

test('a customer member completes only their own task, with no drag or Move', async ({
    page,
    contextFor,
    cleanup,
}) => {
    // Measured 28.7 s alone (two personas, a task edit and a completion) against the 30 s default,
    // so this test alone gets the tripled allowance; no global timeout, retry or worker change.
    test.slow();

    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP5 member card project', true);
    await quickAdd(page, 'To Do', 'E2E member own task');
    await quickAdd(page, 'To Do', 'E2E someone else task');

    // Assign the first task to the member through the task's own edit dialog.
    await page.getByRole('link', { name: 'E2E member own task' }).click();
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/tasks/\\d+$`));
    await page.getByRole('button', { name: 'Edit task', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Edit task' });
    await dialog.getByLabel('Assignee').selectOption({ label: 'Dev User' });
    await dialog.getByRole('button', { name: 'Save changes' }).click();
    await expect(dialog).toHaveCount(0);

    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(`/projects/${projectId}/board`);

        await expect(
            card(memberPage, 'E2E member own task').getByRole('button', {
                name: 'Complete E2E member own task',
            }),
        ).toBeVisible();
        await expect(
            card(memberPage, 'E2E someone else task').getByRole('button', {
                name: /^(Complete|Reopen) /,
            }),
        ).toHaveCount(0);
        await expect(memberPage.getByTestId('task-drag-handle')).toHaveCount(0);
        await expect(memberPage.getByRole('button', { name: /Move "/ })).toHaveCount(0);

        await card(memberPage, 'E2E member own task')
            .getByRole('button', { name: 'Complete E2E member own task' })
            .click();
        await expect(
            column(memberPage, 'Done').getByRole('article', { name: 'E2E member own task' }),
        ).toBeVisible();
        await expect(
            card(memberPage, 'E2E member own task').getByRole('button', {
                name: 'Reopen E2E member own task',
            }),
        ).toBeFocused();
        await expect(memberPage.getByRole('alert')).toHaveCount(0);
    } finally {
        await memberContext.close();
    }
});
