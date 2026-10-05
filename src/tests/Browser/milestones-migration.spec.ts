import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';

import { signedIn } from './support/auth';
import { hasHorizontalOverflow } from './support/shell';

/**
 * EPIC-011E WP4 critical flows: the project milestones page as a React/Inertia page. Focused
 * flows only (§24); every field and permission permutation is covered by Pest and Vitest
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

/**
 * One milestone row (EPIC-015 WP4: ruled rows in a list named "Milestones", which replaced the cards).
 * `exact`: the StagePath is a list too, named "Milestones: N of M complete".
 */
function milestoneRow(page: Page, name: string) {
    return page
        .getByRole('list', { name: 'Milestones', exact: true })
        .getByRole('listitem', { name, exact: true });
}

function milestonesHeading(page: Page) {
    return page.getByRole('heading', { level: 2, name: 'Milestones' });
}

test('board to milestones, create, edit and delete an unreferenced milestone', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP4 milestones project');

    // The board and milestones are both React pages as of WP5: an Inertia navigation. `exact`:
    // since EPIC-015 WP2 the board's breadcrumb names this project, whose name contains "milestones".
    await page.getByRole('link', { name: 'Milestones', exact: true }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/milestones`);
    await expect(milestonesHeading(page)).toBeVisible();
    await expect(page.getByText('No milestones yet')).toBeVisible();

    // Create.
    await page.getByRole('button', { name: 'New milestone' }).click();
    const createDialog = page.getByRole('dialog', { name: 'New milestone' });
    await expect(createDialog).toBeVisible();

    // The required name and due-date fields are native HTML constraints: an empty submit is
    // blocked by the browser itself, before any request, and the dialog is not lost.
    await page.getByRole('button', { name: 'Create milestone' }).click();
    expect(
        await page.getByLabel(/Name/).evaluate((el: HTMLInputElement) => el.validity.valid),
    ).toBe(false);
    await expect(createDialog).toBeVisible();

    await page.getByLabel(/Name/).fill('Beta launch');
    await page.getByLabel(/Due date/).fill('2026-12-31');
    await page.getByLabel('Description').fill('Ship the beta build.');
    await page.getByRole('button', { name: 'Create milestone' }).click();

    await expect(createDialog).toBeHidden();
    await expect(page.getByRole('status')).toContainText('Milestone created.');
    const card = milestoneRow(page, 'Beta launch');
    await expect(card).toBeVisible();
    // FLIPPED IN EPIC-015 WP4: the card read "0 tasks" and "0% complete". Explicit completion and
    // linked-task progress are now separate facts, and task progress is never worded as completion.
    await expect(card).toContainText('No linked tasks');
    await expect(card).toContainText('Not completed');
    await expect(card).not.toContainText('% complete');

    // Edit: prefilled, focus returns to its own Edit button on Escape.
    const editOpener = card.getByRole('button', { name: 'Edit Beta launch' });
    await editOpener.click();
    const editDialog = page.getByRole('dialog', { name: 'Edit milestone' });
    await expect(page.getByLabel(/Name/)).toHaveValue('Beta launch');
    await expect(page.getByLabel(/Due date/)).toHaveValue('2026-12-31');
    await page.keyboard.press('Escape');
    await expect(editDialog).toBeHidden();
    await expect(editOpener).toBeFocused();

    await editOpener.click();
    await page.getByLabel(/Name/).fill('Beta launch renamed');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(editDialog).toBeHidden();
    await expect(milestoneRow(page, 'Beta launch renamed')).toBeVisible();
    // A successful save closes the dialog via its onSuccess callback, not a dismiss gesture
    // (Escape/Cancel): confirmed here in a real browser, since that specific path is not
    // reliably observable under jsdom (see the Vitest suite's note on this).
    await expect(
        milestoneRow(page, 'Beta launch renamed').getByRole('button', { name: /Edit/ }),
    ).toBeFocused();

    // Delete: needs confirmation, is not optimistic.
    const renamedCard = milestoneRow(page, 'Beta launch renamed');
    await renamedCard.getByRole('button', { name: 'Delete Beta launch renamed' }).click();
    const deleteDialog = page.getByRole('dialog', { name: 'Delete Beta launch renamed?' });
    await expect(deleteDialog).toContainText(
        'Tasks on this milestone stay, but lose their milestone.',
    );
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(milestoneRow(page, 'Beta launch renamed')).toBeVisible();

    await renamedCard.getByRole('button', { name: 'Delete Beta launch renamed' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete milestone' }).click();
    await expect(page.getByRole('status')).toContainText('Milestone deleted.');
    await expect(page.getByText('No milestones yet')).toBeVisible();
});

test('a manager completes and reopens a milestone explicitly, and focus stays on the control', async ({
    page,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP4 completion project');
    await page.goto(`/projects/${projectId}/milestones`);

    // The shared project frame: one h1, the tabs with Milestones current (FLIPPED IN EPIC-015 WP5:
    // plus the optional Time tab, which the operator is offered), the shell's trail.
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    const tabs = page.getByRole('navigation', { name: 'Project', exact: true });
    await expect(tabs.getByRole('link')).toHaveText([
        'Overview',
        'Board',
        'Tasks',
        'Milestones',
        'Time',
    ]);
    await expect(tabs.getByRole('link', { name: 'Milestones' })).toHaveAttribute(
        'aria-current',
        'page',
    );
    const crumbs = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(crumbs).toHaveCount(1);
    await expect(crumbs.locator('[aria-current="page"]')).toHaveText('Milestones');

    await page.getByRole('button', { name: 'Create the first milestone' }).click();
    await page.getByLabel(/Name/).fill('Sign-off');
    await page.getByLabel(/Due date/).fill('2020-01-15');
    await page.getByRole('button', { name: 'Create milestone' }).click();
    await expect(page.getByRole('status')).toContainText('Milestone created.');

    // Past due and never completed: overdue (a zero-task milestone can be), and the current stage.
    const row = milestoneRow(page, 'Sign-off');
    await expect(row).toHaveAttribute('data-milestone-state', 'overdue');
    await expect(row).toContainText('Overdue');
    const path = page.getByRole('list', { name: 'Milestones: 0 of 1 complete' });
    await expect(path.locator('[aria-current="step"]')).toContainText('Sign-off');

    await row.getByRole('button', { name: 'Complete Sign-off' }).click();
    await expect(page.getByRole('status')).toContainText('Milestone completed.');
    await expect(row).toHaveAttribute('data-milestone-state', 'completed');
    await expect(row).toContainText(/Completed .* by /);
    await expect(row).not.toContainText('Overdue');
    await expect(page.getByRole('list', { name: 'Milestones: 1 of 1 complete' })).toBeVisible();
    // The same control, now Reopen, still holds focus: nothing fell to the document.
    const reopen = row.getByRole('button', { name: 'Reopen Sign-off' });
    await expect(reopen).toBeFocused();

    await page.keyboard.press('Enter');
    await expect(page.getByRole('status')).toContainText('Milestone reopened.');
    await expect(row).toHaveAttribute('data-milestone-state', 'overdue');
    await expect(row.getByRole('button', { name: 'Complete Sign-off' })).toBeFocused();
});

test('a plain project member sees milestones read-only, with no create, edit or delete controls', async ({
    page,
    contextFor,
    cleanup,
}) => {
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP4 read-only project');

    // Add the seeded Dev User as a plain member through the (WP3) React edit page: a mixed
    // navigation between two already-migrated pages.
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Update members' }).click();
    await expect(page.getByRole('status')).toContainText('Members updated.');

    await page.goto(`/projects/${projectId}/milestones`);
    await page.getByRole('button', { name: 'New milestone' }).click();
    await page.getByLabel(/Name/).fill('Keeps its task');
    await page.getByLabel(/Due date/).fill('2026-12-31');
    await page.getByRole('button', { name: 'Create milestone' }).click();
    await expect(page.getByRole('status')).toContainText('Milestone created.');

    // The plain member: no New milestone button, no edit or delete controls.
    const memberContext = await contextFor('member');
    const memberPage = await memberContext.newPage();
    try {
        await memberPage.goto(`/projects/${projectId}/milestones`);

        await expect(milestoneRow(memberPage, 'Keeps its task')).toBeVisible();
        await expect(memberPage.getByRole('button', { name: 'New milestone' })).toHaveCount(0);
        // No completion control and no Settings: both follow the server's manage ability.
        await expect(memberPage.getByRole('button', { name: /^(Complete|Reopen) / })).toHaveCount(
            0,
        );
        await expect(
            memberPage.getByRole('main').getByRole('link', { name: 'Settings' }),
        ).toHaveCount(0);
        await expect(memberPage.getByRole('button', { name: /Edit Keeps its task/ })).toHaveCount(
            0,
        );
        await expect(memberPage.getByRole('button', { name: /Delete Keeps its task/ })).toHaveCount(
            0,
        );
    } finally {
        await memberContext.close();
    }
});

test('the milestones page is usable at a phone viewport with no document scroll', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signedIn(page);
    const projectId = await createProject(page, cleanup, 'E2E WP4 mobile project');

    await page.goto(`/projects/${projectId}/milestones`);
    await expect(milestonesHeading(page)).toBeVisible();
    expect(await hasHorizontalOverflow(page)).toBe(false);

    await page.getByRole('button', { name: 'Create the first milestone' }).click();
    await page.getByLabel(/Name/).fill('Mobile milestone');
    await page.getByLabel(/Due date/).fill('2026-12-31');
    const dialogOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(dialogOverflow).toBe(false);
    await page.getByRole('button', { name: 'Create milestone' }).click();
    const row = milestoneRow(page, 'Mobile milestone');
    await expect(row).toBeVisible();

    // At 390px the row's actions, the project tabs and Settings stay reachable, and nothing scrolls
    // the document sideways.
    await expect(row.getByRole('button', { name: 'Complete Mobile milestone' })).toBeInViewport();
    await expect(row.getByRole('button', { name: 'Delete Mobile milestone' })).toBeInViewport();
    for (const label of ['Overview', 'Board', 'Tasks', 'Milestones', 'Time']) {
        await expect(
            page.getByRole('navigation', { name: 'Project', exact: true }).getByRole('link', {
                name: label,
            }),
        ).toBeInViewport();
    }
    await expect(page.getByRole('main').getByRole('link', { name: 'Settings' })).toBeInViewport();
    expect(await hasHorizontalOverflow(page)).toBe(false);
});
