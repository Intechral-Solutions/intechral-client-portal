import type { Page } from '@playwright/test';

import { expect, signedIn, test } from './support/auth';
import { drawerLink, railLink } from './support/shell';

/**
 * Release 1.0 (DIR-1) — Directory and System navigation, in real Chromium, as the operator persona: Directory offers People and
 * Organizations; the administrative index of portal organizations is System → Organization access; System → Users shows an account's
 * memberships and filters by account type and status; and the membership cross-links (organization ↔ user) are one click each.
 *
 * Presentation and read queries only. Nothing here creates, edits or deletes anything, so it needs no cleanup and is safe to re-run. It
 * reads two fixtures DevSeeder always creates: the account `E2E Customer` and the organization `E2E Customer Org` it is a member of.
 * (The seeded organizations have no Directory profile; that is why this journey starts from System → Organization access, not from a
 * Directory profile.) The customer-isolation boundary (404 in closed and pilot modes) is proved in Pest and by customer-pilot.spec.ts.
 */

const XL = { width: 1440, height: 900 };
const ACCOUNT = 'E2E Customer';
const ORGANIZATION = 'E2E Customer Org';

const heading = (page: Page, name: string) =>
    page.getByRole('heading', { name, level: 1, exact: true });
const currentRail = (page: Page) =>
    page.getByRole('navigation', { name: 'Workspaces' }).locator('[aria-current="page"]');

test('Directory offers People and Organizations; Organization access lives in System', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await railLink(page, 'Directory').click();
    await expect(page).toHaveURL(/\/crm\/contacts$/);
    await expect(currentRail(page)).toHaveText('Directory');
    await expect(heading(page, 'People')).toBeVisible();

    // Exactly the two entries, with the page title equal to the label of the entry that opened it.
    await expect(drawerLink(page, 'Directory', 'People')).toHaveAttribute('aria-current', 'page');
    await expect(drawerLink(page, 'Directory', 'Organizations')).toBeVisible();
    await expect(drawerLink(page, 'Directory', 'Portal access')).toHaveCount(0);
    await expect(
        page.getByRole('navigation', { name: 'Directory views' }).getByRole('link'),
    ).toHaveCount(2);

    await drawerLink(page, 'Directory', 'Organizations').click();
    await expect(page).toHaveURL(/\/crm\/companies$/);
    await expect(heading(page, 'Organizations')).toBeVisible();
    await expect(drawerLink(page, 'Directory', 'Organizations')).toHaveAttribute(
        'aria-current',
        'page',
    );
    // The list states each Organization's portal access.
    await expect(page.getByRole('columnheader', { name: 'Portal access' })).toBeVisible();

    await railLink(page, 'System').click();
    await expect(currentRail(page)).toHaveText('System');
    await drawerLink(page, 'System', 'Organization access').click();
    await expect(page).toHaveURL(/\/organizations$/);
    await expect(heading(page, 'Organization access')).toBeVisible();
    await expect(drawerLink(page, 'System', 'Organization access')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(page.getByRole('columnheader', { name: 'Portal access' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Enabled by' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Owner' })).toHaveCount(0);
});

test('the membership cross-links: organization to user, user to organization, in one click each', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/organizations');

    // Organization access → the organization (still inside System).
    await page.getByRole('link', { name: ORGANIZATION, exact: true }).first().click();
    await expect(heading(page, ORGANIZATION)).toBeVisible();
    await expect(currentRail(page)).toHaveText('System');
    await expect(drawerLink(page, 'System', 'Organization access')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(page.getByText('Portal access enabled by')).toBeVisible();

    // Member row → the user page (the operator holds users.view).
    await page.getByRole('link', { name: ACCOUNT, exact: true }).click();
    await expect(page).toHaveURL(/\/admin\/users\/\d+$/);
    await expect(heading(page, ACCOUNT)).toBeVisible();
    await expect(currentRail(page)).toHaveText('System');
    await expect(drawerLink(page, 'System', 'Users')).toHaveAttribute('aria-current', 'page');

    // The Memberships section names the organization, read-only, and links back to it.
    const memberships = page.getByRole('region', { name: 'Memberships' });
    await expect(memberships).toBeVisible();
    await expect(memberships.getByRole('button')).toHaveCount(0);
    await memberships.getByRole('link', { name: ORGANIZATION, exact: true }).click();
    await expect(heading(page, ORGANIZATION)).toBeVisible();
});

test('System → Users filters by account type and status, shows memberships, and keeps the filters in the address', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page);

    await railLink(page, 'System').click();
    await expect(heading(page, 'Users')).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Organizations' })).toBeVisible();

    await page.getByRole('searchbox', { name: 'Search by name or email…' }).fill(ACCOUNT);
    await page.getByLabel('Account type').selectOption('customer');
    await page.getByLabel('Account status').selectOption('active');
    await page.getByRole('button', { name: 'Search', exact: true }).click();

    await expect(page).toHaveURL(/search=E2E\+Customer&type=customer&status=active/);
    const row = page.getByRole('row').filter({ hasText: 'e2e-customer@intechral.test' });
    await expect(row).toHaveCount(1);
    // The Organizations column names the membership and links to it for an operator.
    await expect(row.getByRole('link', { name: ORGANIZATION, exact: true })).toBeVisible();

    // An operator filter excludes that customer, whatever the search matches.
    await page.getByLabel('Account type').selectOption('operator');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page).toHaveURL(/type=operator/);
    await expect(
        page.getByRole('row').filter({ hasText: 'e2e-customer@intechral.test' }),
    ).toHaveCount(0);

    // Clear returns to the unfiltered list.
    await page.getByRole('link', { name: 'Clear' }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);

    // From the user's membership column, the organization is one click away.
    await page
        .getByRole('searchbox', { name: 'Search by name or email…' })
        .fill('e2e-customer@intechral.test');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    await page
        .getByRole('row')
        .filter({ hasText: 'e2e-customer@intechral.test' })
        .getByRole('link', { name: ORGANIZATION, exact: true })
        .click();
    await expect(heading(page, ORGANIZATION)).toBeVisible();
});

test('the role editor marks permissions nothing checks, and they stay assignable', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/admin/roles/create');

    await expect(page.getByText('Not used in this release').first()).toBeVisible();
    // A marked permission is an ordinary, enabled checkbox whose description it points at.
    const unused = page.locator('input[name="permissions[]"][value="org.admin"]');
    await expect(unused).toBeEnabled();
    await unused.check();
    await expect(unused).toBeChecked();
    await expect(unused).toHaveAccessibleDescription(
        /Customer-side organization delegation is a later release/,
    );

    // A permission the product does use is not marked.
    await expect(
        page.locator('input[name="permissions[]"][value="crm.manage"]'),
    ).not.toHaveAccessibleDescription(/later release/);
});
