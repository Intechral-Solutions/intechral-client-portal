import type { Page } from '@playwright/test';

import { expect, test } from './support/e2e-fixtures';

/**
 * Release 0.9 (R0.9-1D, decision H4): the built-in roles are owned by the application. The Roles screens show them
 * read-only, and a crafted request to change or delete one is refused (HTTP 409 for a JSON caller) with nothing changed.
 *
 * Read-only against the development database: it only looks, and sends requests that the application must refuse.
 */

async function csrf(page: Page) {
    await page.goto('/admin/roles');

    return (await page.locator('meta[name="csrf-token"]').getAttribute('content')) ?? '';
}

/** `/admin/roles/{id}` of the built-in role named $name, from its row's View link. */
async function builtInRolePath(page: Page, name: string) {
    await page.goto('/admin/roles');
    const row = page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) });
    const href = await row.getByRole('link', { name: 'View' }).getAttribute('href');

    expect(href, `the ${name} row offers View`).not.toBeNull();

    return new URL(href as string, 'http://localhost').pathname.replace(/\/edit$/, '');
}

test('the Roles list marks both built-in roles and offers no delete for them', async ({ page }) => {
    await page.goto('/admin/roles');

    for (const name of ['operator', 'user']) {
        const row = page.getByRole('row').filter({ has: page.getByText(name, { exact: true }) });

        await expect(row.getByText('Built-in')).toBeVisible();
        await expect(row.getByRole('link', { name: 'View' })).toBeVisible();
        await expect(row.getByRole('link', { name: 'Edit' })).toHaveCount(0);
        await expect(row.getByRole('button', { name: 'Delete' })).toHaveCount(0);
    }
});

test('a built-in role page is read-only: permissions are shown but disabled, with nothing to save or delete', async ({ page }) => {
    const path = await builtInRolePath(page, 'user');
    await page.getByRole('row').filter({ has: page.getByText('user', { exact: true }) }).getByRole('link', { name: 'View' }).click();
    const main = page.locator('#main-content');

    await expect(page).toHaveURL(new RegExp(`${path}/edit$`));
    await expect(main.getByText('cannot be edited or deleted here')).toBeVisible();
    await expect(main.getByRole('button', { name: 'Save Changes' })).toHaveCount(0);
    await expect(main.getByRole('button', { name: 'Delete Role' })).toHaveCount(0);

    const boxes = main.locator('input[name="permissions[]"]');
    expect(await boxes.count()).toBeGreaterThan(0);
    for (const box of await boxes.all()) {
        await expect(box).toBeDisabled();
    }
});

test('a crafted request to change or delete a built-in role is refused with 409 and nothing changes', async ({ page }) => {
    const path = await builtInRolePath(page, 'user');
    await page.goto(`${path}/edit`);
    const checked = () => page.locator('#main-content input[name="permissions[]"]:checked').count();
    const before = await checked();
    // The role's own permissions, so that a refusal which ever regressed would save exactly what is stored and could
    // not strip grants from the development database.
    const current = await page.locator('#main-content input[name="permissions[]"]:checked').evaluateAll((boxes) => boxes.map((box) => (box as HTMLInputElement).value));
    expect(current.length, 'the role has permissions to send back').toBe(before);
    const token = await csrf(page);
    const headers = { 'X-CSRF-TOKEN': token, Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    const update = await page.request.put(path, { headers, data: { permissions: current }, maxRedirects: 0 });
    const removal = await page.request.delete(path, { headers, maxRedirects: 0 });

    expect(update.status(), 'PUT on a built-in role').toBe(409);
    expect(removal.status(), 'DELETE on a built-in role').toBe(409);
    expect((await update.json()).message).toContain('built-in');

    await page.goto(`${path}/edit`);
    expect(await checked(), 'the built-in role is unchanged').toBe(before);
});
