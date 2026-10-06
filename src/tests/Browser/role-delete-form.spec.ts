import type { Page, Request } from '@playwright/test';

import { expect, test } from './support/e2e-fixtures';

/**
 * Hotfix: `admin/roles/edit` nested the "Delete Role" <form> inside the update <form>. A browser drops the
 * inner <form> start tag, so both buttons belonged to the update form, its `_method` fields were PUT then
 * DELETE (PHP keeps the last), and Save Changes and Delete Role BOTH sent DELETE, with the delete form's
 * confirm() lost.
 *
 * This drives the real controls on a throwaway custom role: Save Changes must send PUT only, with no
 * confirmation, and keep the role; Delete Role must run the native confirm() (dismissing it deletes nothing,
 * by pointer and by keyboard), and only accepting it sends DELETE and removes the role. The role is created
 * through the application's own route and removed through its own DELETE route afterwards, pass or fail.
 */

const roleName = `e2e-role-delete-form-${Date.now().toString(36)}`;

let rolePath: string | null = null;

async function csrf(page: Page) {
    await page.goto('/admin/roles');

    return (await page.locator('meta[name="csrf-token"]').getAttribute('content')) ?? '';
}

async function createRole(page: Page): Promise<string> {
    const token = await csrf(page);
    const response = await page.request.post('/admin/roles', {
        form: { _token: token, name: roleName, 'permissions[]': 'tickets.view' },
        headers: { 'X-CSRF-TOKEN': token },
        maxRedirects: 0,
    });

    expect(response.status(), 'POST /admin/roles').toBe(302);

    await page.goto('/admin/roles');
    const edit = page.getByRole('row').filter({ hasText: roleName }).getByRole('link', { name: 'Edit' });
    const href = await edit.getAttribute('href');

    expect(href, 'the created role has an edit link').not.toBeNull();

    return new URL(href as string, 'http://localhost').pathname.replace(/\/edit$/, '');
}

test.afterEach(async ({ page }) => {
    if (rolePath === null) {
        return;
    }

    const token = await csrf(page);
    const response = await page.request.delete(rolePath, {
        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        maxRedirects: 0,
    });

    rolePath = null;
    expect([200, 204, 302, 404], 'role fixture cleanup').toContain(response.status());

    // Drain the flash bag the deletion just wrote.
    await page.goto('/admin/roles');
});

/** The `_method` values a submitted form body carries (Laravel's method spoofing). */
const methodsOf = (request: Request) => new URLSearchParams(request.postData() ?? '').getAll('_method');

const roleExists = async (page: Page) => (await page.request.get(`${rolePath}/edit`)).status() === 200;

test('Save Changes sends PUT only and keeps the role; Delete Role is confirmed before it sends DELETE', async ({ page }) => {
    test.setTimeout(120_000);

    rolePath = await createRole(page);
    const main = page.locator('#main-content');
    const dialogs: string[] = [];

    page.on('dialog', async (dialog) => {
        dialogs.push(`${dialog.type()}: ${dialog.message()}`);
        await dialog.dismiss();
    });

    // ── Save Changes ──────────────────────────────────────────────────────────────────────────────
    await page.goto(`${rolePath}/edit`);
    await expect(main.locator('input[name="permissions[]"][value="tickets.view"]')).toBeChecked();
    await main.locator('input[name="permissions[]"][value="tickets.create"]').check();

    const saveRequest = page.waitForRequest((request) => request.method() === 'POST' && request.url().endsWith(rolePath as string));
    await main.getByRole('button', { name: 'Save Changes' }).click();
    const save = await saveRequest;

    // Only PUT: the old nested markup submitted `_method=PUT&…&_method=DELETE`.
    expect(methodsOf(save)).toEqual(['PUT']);
    await expect(page).toHaveURL(/\/admin\/roles$/);
    await expect(page.getByText(`Role "${roleName}" updated.`)).toBeVisible();
    expect(dialogs, 'Save Changes asks for no confirmation').toEqual([]);

    // The role still exists and the change persisted.
    expect(await roleExists(page), 'the role survived Save Changes').toBe(true);
    await page.goto(`${rolePath}/edit`);
    await expect(main.locator('input[name="permissions[]"][value="tickets.create"]')).toBeChecked();
    await expect(main.locator('input[name="permissions[]"][value="tickets.view"]')).toBeChecked();

    // ── Delete Role: the native confirm runs; dismissing it deletes nothing (pointer, then keyboard) ──
    const deleteRole = main.getByRole('button', { name: 'Delete Role' });
    const message = `confirm: Delete role '${roleName}'?`;

    await deleteRole.click();
    expect(dialogs).toEqual([message]);
    await expect(page).toHaveURL(new RegExp(`${rolePath}/edit$`));
    expect(await roleExists(page), 'dismissing the confirmation deletes nothing').toBe(true);

    await deleteRole.focus();
    await page.keyboard.press('Enter');
    await expect.poll(() => dialogs.length, 'Enter on Delete Role asks for the confirmation too').toBe(2);
    expect(dialogs[1]).toBe(message);
    expect(await roleExists(page), 'dismissing by keyboard deletes nothing').toBe(true);

    // ── Accepting the confirmation sends DELETE and removes the role ───────────────────────────────
    page.removeAllListeners('dialog');
    page.once('dialog', (dialog) => void dialog.accept());

    const deleteRequest = page.waitForRequest((request) => request.method() === 'POST' && request.url().endsWith(rolePath as string));
    await deleteRole.click();
    const removal = await deleteRequest;

    expect(methodsOf(removal)).toEqual(['DELETE']);
    await expect(page).toHaveURL(/\/admin\/roles$/);
    await expect(page.getByText(`Role "${roleName}" deleted.`)).toBeVisible();
    expect(await roleExists(page), 'accepting the confirmation deleted the role').toBe(false);
});
