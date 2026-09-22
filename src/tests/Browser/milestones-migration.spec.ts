import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signIn } from './support/sign-in';

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
    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]);
    cleanup.trackProject(projectId);

    return projectId;
}

test('board to milestones, create, edit and delete an unreferenced milestone', async ({
    page,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E WP4 milestones project');

    // The board and milestones are both React pages as of WP5: an Inertia navigation.
    await page.getByRole('link', { name: 'Milestones' }).click();
    await expect(page).toHaveURL(`/projects/${projectId}/milestones`);
    await expect(page.getByRole('heading', { name: 'Milestones' })).toBeVisible();
    await expect(page.getByText('No milestones yet.')).toBeVisible();

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
    const card = page.getByRole('article', { name: 'Beta launch' });
    await expect(card).toBeVisible();
    await expect(card).toContainText('0 tasks');
    await expect(card).toContainText('0% complete');

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
    await expect(page.getByRole('article', { name: 'Beta launch renamed' })).toBeVisible();
    // A successful save closes the dialog via its onSuccess callback, not a dismiss gesture
    // (Escape/Cancel): confirmed here in a real browser, since that specific path is not
    // reliably observable under jsdom (see the Vitest suite's note on this).
    await expect(
        page
            .getByRole('article', { name: 'Beta launch renamed' })
            .getByRole('button', { name: /Edit/ }),
    ).toBeFocused();

    // Delete: needs confirmation, is not optimistic.
    const renamedCard = page.getByRole('article', { name: 'Beta launch renamed' });
    await renamedCard.getByRole('button', { name: 'Delete Beta launch renamed' }).click();
    const deleteDialog = page.getByRole('dialog', { name: 'Delete Beta launch renamed?' });
    await expect(deleteDialog).toContainText(
        'Tasks on this milestone stay, but lose their milestone.',
    );
    await deleteDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('article', { name: 'Beta launch renamed' })).toBeVisible();

    await renamedCard.getByRole('button', { name: 'Delete Beta launch renamed' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete milestone' }).click();
    await expect(page.getByRole('status')).toContainText('Milestone deleted.');
    await expect(page.getByText('No milestones yet.')).toBeVisible();
});

test('a plain project member sees milestones read-only, with no create, edit or delete controls', async ({
    page,
    browser,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
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
    const memberContext = await browser.newContext();
    const memberPage = await memberContext.newPage();
    try {
        await signIn(memberPage, 'user@intechral.test');
        await memberPage.goto(`/projects/${projectId}/milestones`);

        await expect(memberPage.getByRole('article', { name: 'Keeps its task' })).toBeVisible();
        await expect(memberPage.getByRole('button', { name: 'New milestone' })).toHaveCount(0);
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
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E WP4 mobile project');

    await page.goto(`/projects/${projectId}/milestones`);
    await expect(page.getByRole('heading', { name: 'Milestones' })).toBeVisible();
    const indexOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(indexOverflow).toBe(false);

    await page.getByRole('button', { name: 'Create the first milestone' }).click();
    await page.getByLabel(/Name/).fill('Mobile milestone');
    await page.getByLabel(/Due date/).fill('2026-12-31');
    const dialogOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(dialogOverflow).toBe(false);
    await page.getByRole('button', { name: 'Create milestone' }).click();
    await expect(page.getByRole('article', { name: 'Mobile milestone' })).toBeVisible();
});
