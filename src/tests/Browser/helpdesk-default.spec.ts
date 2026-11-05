import { expect, signedIn, test } from './support/auth';
import { drawerLink, railLink } from './support/shell';

/**
 * Release 1.0 — selecting Helpdesk opens the operator Queue; a customer-only user still opens My requests.
 *
 * The destination is the first surviving contextual view the server computes (NavigationBuilder::helpdesk),
 * so these tests click the real workspace link instead of asserting a URL. No data is created or changed.
 */

const XL = { width: 1440, height: 900 };
const M = { width: 900, height: 900 };

test('an operator who selects Helpdesk lands on the Queue, with My requests and Reports still offered', async ({
    page,
}) => {
    await page.setViewportSize(XL);
    await signedIn(page, '/dashboard');

    await railLink(page, 'Helpdesk').click();
    await expect(page).toHaveURL(/\/operator\/tickets$/);

    const views = page.getByRole('navigation', { name: 'Helpdesk views' });
    await expect(views.getByRole('link')).toHaveText(['Queue', 'My requests', 'Reports']);
    await expect(views.locator('[aria-current="page"]')).toHaveText('Queue');

    await drawerLink(page, 'Helpdesk', 'My requests').click();
    await expect(page).toHaveURL(/\/tickets$/);
    await expect(
        page.getByRole('navigation', { name: 'Helpdesk views' }).locator('[aria-current="page"]'),
    ).toHaveText('My requests');

    await drawerLink(page, 'Helpdesk', 'Reports').click();
    await expect(page).toHaveURL(/\/operator\/tickets\/reports$/);
});

test('at M the Queue is first in the navigation sheet', async ({ page }) => {
    await page.setViewportSize(M);
    await signedIn(page, '/operator/tickets');

    await page.getByRole('button', { name: 'Open navigation' }).click();
    const sheet = page.getByRole('dialog', { name: 'Navigation' });

    await expect(
        sheet.getByRole('navigation', { name: 'Helpdesk views' }).getByRole('link'),
    ).toHaveText(['Queue', 'My requests', 'Reports']);
});

test.describe('a customer-only user', () => {
    test.use({ persona: 'customer', viewport: XL });

    test('still opens My requests from Support and is offered no operator view', async ({
        page,
    }) => {
        await signedIn(page, '/dashboard');

        await page.getByRole('link', { name: 'Support', exact: true }).first().click();
        await expect(page).toHaveURL(/\/tickets$/);
        expect((await page.request.get('/operator/tickets')).status()).toBe(403);
    });
});
