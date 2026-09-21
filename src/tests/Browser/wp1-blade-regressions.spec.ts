import { execSync } from 'node:child_process';
import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signIn } from './support/sign-in';

/**
 * EPIC-011E WP1 browser regressions for the Blade project pages. Temporary: this spec is
 * deleted together with the Blade views (WP3 for the create/edit pages, WP5 for the board,
 * WP7 for the task page); their React successors get equivalent coverage.
 *
 * Runs against the shared development database, so every project is registered for the
 * fixture teardown and the hostile-name user is removed again in a finally block.
 */

async function createProject(page: Page, cleanup: { trackProject: (id: number) => void }, name: string) {
    await page.goto('/projects/create');
    await page.getByLabel('Project Name').fill(name);
    await page.getByRole('button', { name: /Create Project/ }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]);
    cleanup.trackProject(projectId);

    return projectId;
}

function artisan(php: string) {
    return execSync(`php artisan tinker --execute='${php}'`, {
        cwd: process.env.PORTAL_APP_DIR ?? '/var/www/app',
        encoding: 'utf8',
    });
}

test('S2: Save Changes on the edit page saves and never deletes the project', async ({ page, cleanup }) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E S2 regression project');
    await page.goto(`/projects/${projectId}/edit`);

    // The delete form is a sibling of the details form, not inside it.
    const structure = await page.evaluate((id) => {
        const forms = [...document.querySelectorAll('form')];
        const details = forms.find((f) => f.querySelector('input[name=name]'))!;
        const deleteButton = [...document.querySelectorAll('button')].find((b) =>
            b.textContent?.includes('Delete Project'),
        )!;

        return {
            nestedForms: document.querySelectorAll('form form').length,
            detailsMethods: [...details.querySelectorAll('input[name=_method]')].map(
                (i) => (i as HTMLInputElement).value,
            ),
            deleteButtonInsideDetails: details.contains(deleteButton),
            deleteFormAction: deleteButton.form?.getAttribute('action')?.endsWith(`/projects/${id}`),
        };
    }, projectId);
    expect(structure).toEqual({
        nestedForms: 0,
        detailsMethods: ['PUT'],
        deleteButtonInsideDetails: false,
        deleteFormAction: true,
    });

    // Save: a PUT, and the project survives with its new name.
    await page.getByLabel('Project Name').fill('E2E S2 regression renamed');
    const [request] = await Promise.all([
        page.waitForRequest(
            (r) => r.url().endsWith(`/projects/${projectId}`) && r.method() === 'POST',
        ),
        page.getByRole('button', { name: 'Save Changes' }).click(),
    ]);
    expect(request.postData()).toContain('_method=PUT');
    expect(request.postData()).not.toContain('_method=DELETE');
    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/edit$`));
    await expect(page.getByRole('alert')).toContainText('Project updated.');
    await expect(page.getByLabel('Project Name')).toHaveValue('E2E S2 regression renamed');
    expect((await page.request.get(`/projects/${projectId}/board`)).status()).toBe(200);

    // The explicit Delete button still deletes, after its confirmation.
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete Project' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    expect((await page.request.get(`/projects/${projectId}/board`, { maxRedirects: 0 })).status()).toBe(404);
});

test('D4: a project with recorded time cannot be deleted and says why', async ({ page, cleanup }) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E D4 project with time');

    // Record time through the page so the fixture teardown removes the entry before the project.
    const entryId = await page.evaluate(async (id) => {
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        const headers = {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
        };
        const started = await fetch('/time/timer/start', {
            method: 'POST',
            headers,
            body: JSON.stringify({ project_id: id }),
        });
        const entry = (await started.json()) as { id: number };
        await fetch(`/time/timer/${entry.id}/stop`, { method: 'POST', headers });

        return entry.id;
    }, projectId);
    expect(entryId).toBeGreaterThan(0);

    await page.goto(`/projects/${projectId}/edit`);
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Delete Project' }).click();

    await expect(page).toHaveURL(new RegExp(`/projects/${projectId}/edit$`));
    await expect(page.getByRole('alert')).toContainText(
        'This project has recorded time and cannot be deleted.',
    );
    expect((await page.request.get(`/projects/${projectId}/board`)).status()).toBe(200);
});

test('S1: a hostile user name stays inert text in the member editor', async ({ page, cleanup }) => {
    const email = `e2e-hostile-${Date.now()}@intechral.test`;
    const hostile = '</select><img src=x onerror="window.__xss=1">';
    artisan(
        `\\App\\Models\\User::forceCreate(["name" => ${JSON.stringify(hostile).replace(/\$/g, '\\$')}, "email" => "${email}", "password" => bcrypt("unused-e2e-password"), "email_verified_at" => now()]);`,
    );

    try {
        await signIn(page, 'operator@intechral.test');

        for (const url of ['/projects/create', null]) {
            const target =
                url ?? `/projects/${await createProject(page, cleanup, 'E2E S1 hostile member project')}/edit`;
            await page.goto(target);
            const rowsBefore = await page.locator('.member-row').count();

            await page.getByRole('button', { name: '+ Add member' }).click();
            await page.getByRole('button', { name: '+ Add member' }).click();

            await expect(page.locator('.member-row')).toHaveCount(rowsBefore + 2);
            const result = await page.evaluate(
                ({ hostileName }) => ({
                    xss: (window as unknown as { __xss?: number }).__xss ?? null,
                    injectedImages: document.querySelectorAll('img[src="x"]').length,
                    // every new row keeps exactly its two selects and a remove button
                    lastRowShape: (() => {
                        const row = [...document.querySelectorAll('.member-row')].at(-1)!;
                        return {
                            selects: row.querySelectorAll('select').length,
                            buttons: row.querySelectorAll('button').length,
                            optionShowsHostileText: [...row.querySelectorAll('option')].some((o) =>
                                o.textContent?.includes(hostileName),
                            ),
                        };
                    })(),
                }),
                { hostileName: hostile },
            );

            expect(result.xss).toBeNull();
            expect(result.injectedImages).toBe(0);
            expect(result.lastRowShape).toEqual({ selects: 2, buttons: 1, optionShowsHostileText: true });

            // Removing a row works through the delegated handler (no inline onclick).
            await page.locator('.member-row').last().getByRole('button', { name: 'Remove member' }).click();
            await expect(page.locator('.member-row')).toHaveCount(rowsBefore + 1);
        }
    } finally {
        artisan(`\\App\\Models\\User::where("email", "${email}")->delete();`);
    }
});

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

    // Administrators may add members: pick the dev user from the members form.
    await page.goto(`/projects/${projectId}/edit`);
    await page.getByRole('button', { name: '+ Add member' }).click();
    await page
        .locator('.member-row')
        .last()
        .locator('select')
        .first()
        .selectOption({ label: 'Dev User <user@intechral.test>' });
    await page.getByRole('button', { name: 'Update Members' }).click();
    await expect(page.getByRole('alert')).toContainText('Members updated.');

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
