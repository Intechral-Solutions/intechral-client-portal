import { execSync } from 'node:child_process';
import type { Page } from '@playwright/test';
import { expect, test } from './support/e2e-fixtures';
import { signIn } from './support/sign-in';

/**
 * EPIC-011E WP3 critical flows: the projects index, create and edit pages as React/Inertia
 * pages. Focused flows only (§24 items 2 and 3); every field and permission permutation is
 * covered by Pest and Vitest instead. Runs against the shared development database: every
 * project is registered with `cleanup.trackProject` as soon as its URL is known, and any
 * fixture user created directly is removed in a `finally` block.
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

function artisan(php: string) {
    return execSync(`php artisan tinker --execute='${php}'`, {
        cwd: process.env.PORTAL_APP_DIR ?? '/var/www/app',
        encoding: 'utf8',
    });
}

test('index to create, and the create page as projects.admin adds a member and lands on the board', async ({
    page,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');

    await page.goto('/projects');
    await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible();
    await page.getByRole('link', { name: 'New project' }).click();
    await expect(page).toHaveURL(/\/projects\/create$/);

    // The required name field is a native HTML constraint: an empty submit is blocked by the
    // browser itself, before any request, and the page is not lost.
    await page.getByRole('button', { name: 'Create project' }).click();
    expect(
        await page.getByLabel('Project name').evaluate((el: HTMLInputElement) => el.validity.valid),
    ).toBe(false);
    await expect(page).toHaveURL(/\/projects\/create$/);

    await page.getByLabel('Project name').fill('E2E WP3 admin project');
    await page.getByRole('button', { name: 'Add member' }).click();
    await page
        .getByRole('combobox', { name: 'Member 1', exact: true })
        .selectOption({ label: 'Dev User (user@intechral.test)' });
    await page.getByRole('button', { name: 'Create project' }).click();

    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    const projectId = Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]);
    cleanup.trackProject(projectId);
    // The board is a React page as of WP5, so this landed as an ordinary Inertia navigation
    // (no more Inertia::location() full-page-visit workaround, EPIC-011E §21); the shared
    // FlashRegion renders a success message with role="status", not "alert" (that was the old
    // Blade board's own markup, reached via the pre-WP5 location-visit fallback).
    await expect(page.getByRole('status')).toContainText('Project created successfully.');
});

test('edit-page safety regression: saving renames without deleting, and delete needs confirmation and works', async ({
    page,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E WP3 edit regression');
    await page.goto(`/projects/${projectId}/edit`);

    // The details, companies, and member forms are independent siblings, never nested (S2).
    const nestedForms = await page.evaluate(() => document.querySelectorAll('form form').length);
    expect(nestedForms).toBe(0);

    await page.getByLabel('Project name').fill('E2E WP3 edit regression renamed');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByRole('status')).toContainText('Project updated.');
    await expect(page.getByLabel('Project name')).toHaveValue('E2E WP3 edit regression renamed');
    expect((await page.request.get(`/projects/${projectId}/board`)).status()).toBe(200);

    // Deleting needs the dialog's own confirmation, not a bare click.
    await page.getByRole('button', { name: 'Delete project' }).click();
    const dialog = page.getByRole('dialog', { name: 'Delete this project?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    expect((await page.request.get(`/projects/${projectId}/board`)).status()).toBe(200);

    await page.getByRole('button', { name: 'Delete project' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete project' }).click();
    await expect(page).toHaveURL(/\/projects$/);
    expect(
        (await page.request.get(`/projects/${projectId}/board`, { maxRedirects: 0 })).status(),
    ).toBe(404);
});

test('a project with recorded time cannot be deleted and the refusal shows in the dialog (D4)', async ({
    page,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E WP3 D4 project');

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
    await page.getByRole('button', { name: 'Delete project' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Delete project' }).click();

    await expect(page.getByRole('dialog').getByRole('alert')).toContainText(
        'This project has recorded time and cannot be deleted.',
    );
    expect((await page.request.get(`/projects/${projectId}/board`)).status()).toBe(200);
});

test('administrator membership editing, a hostile member name stays text, and a non-admin manager sees it read-only', async ({
    page,
    browser,
    cleanup,
}) => {
    await signIn(page, 'operator@intechral.test');
    const projectId = await createProject(page, cleanup, 'E2E WP3 membership project');

    const hostileEmail = `e2e-wp3-hostile-${Date.now()}@intechral.test`;
    const hostileName = '</select><img src=x onerror="window.__xss=1">';
    const managerEmail = `e2e-wp3-manager-${Date.now()}@intechral.test`;
    artisan(
        `\\App\\Models\\User::forceCreate(["name" => ${JSON.stringify(hostileName).replace(/\$/g, '\\$')}, "email" => "${hostileEmail}", "password" => bcrypt("unused-e2e-password"), "email_verified_at" => now()]);`,
    );
    artisan(
        `$u = \\App\\Models\\User::forceCreate(["name" => "E2E WP3 Manager", "email" => "${managerEmail}", "password" => bcrypt("unused-e2e-password"), "email_verified_at" => now()]); $u->assignRole("user"); $u->givePermissionTo("projects.manage");`,
    );

    try {
        await page.goto(`/projects/${projectId}/edit`);

        // Add the hostile-named user and the non-admin manager as members.
        await page.getByRole('button', { name: 'Add member' }).click();
        await page
            .getByRole('combobox', { name: 'Member 1', exact: true })
            .selectOption({ label: `${hostileName} (${hostileEmail})` });
        await page.getByRole('button', { name: 'Add member' }).click();
        await page
            .getByRole('combobox', { name: 'Member 2', exact: true })
            .selectOption({ label: `E2E WP3 Manager (${managerEmail})` });
        await page
            .getByRole('combobox', { name: 'Role for member 2', exact: true })
            .selectOption('manager');
        await page.getByRole('button', { name: 'Update members' }).click();
        await expect(page.getByRole('status')).toContainText('Members updated.');

        // No injected element and no script execution. (The admin's own view shows the name only
        // as a native <select> option's text, not as freestanding markup; the plain-text render
        // is checked below, on the read-only list the non-admin manager sees.)
        expect(await page.locator('img[src="x"]').count()).toBe(0);
        expect(
            await page.evaluate(() => (window as unknown as { __xss?: number }).__xss),
        ).toBeUndefined();

        // The non-admin manager: read-only membership, no candidate directory, still full
        // access to the rest of the edit page.
        const managerContext = await browser.newContext();
        const managerPage = await managerContext.newPage();
        try {
            await signIn(managerPage, managerEmail, 'unused-e2e-password');
            await managerPage.goto(`/projects/${projectId}/edit`);

            const membersSection = managerPage
                .getByRole('heading', { name: 'Members' })
                .locator('xpath=ancestor::section[1]');
            await expect(
                membersSection.getByText('Project membership is managed by an administrator.'),
            ).toBeVisible();
            // No editor controls in the members section specifically: the details section above
            // it legitimately still has its own Status <select>, since a non-admin manager keeps
            // every ordinary project-management capability (only membership is locked).
            await expect(membersSection.getByRole('button', { name: 'Add member' })).toHaveCount(0);
            await expect(membersSection.getByRole('combobox')).toHaveCount(0);
            await expect(managerPage.locator('body')).not.toContainText(managerEmail);
            await expect(managerPage.locator('body')).not.toContainText(hostileEmail);
            await expect(managerPage.getByText(hostileName)).toBeVisible();

            await managerPage.getByLabel('Project name').fill('E2E WP3 renamed by manager');
            await managerPage.getByRole('button', { name: 'Save changes' }).click();
            await expect(managerPage.getByRole('status')).toContainText('Project updated.');
        } finally {
            await managerContext.close();
        }
    } finally {
        artisan(
            `\\App\\Models\\User::whereIn("email", ["${hostileEmail}", "${managerEmail}"])->delete();`,
        );
    }
});

test('the projects index and create page are usable at a phone viewport with no document scroll', async ({
    page,
    cleanup,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page, 'operator@intechral.test');

    await page.goto('/projects');
    await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible();
    const indexOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(indexOverflow).toBe(false);

    await page.getByRole('link', { name: 'New project' }).click();
    await page.getByLabel('Project name').fill('E2E WP3 mobile project');
    const createOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(createOverflow).toBe(false);

    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page).toHaveURL(/\/projects\/(\d+)\/board$/);
    cleanup.trackProject(Number(page.url().match(/\/projects\/(\d+)\/board$/)![1]));
});
