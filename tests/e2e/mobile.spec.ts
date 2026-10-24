import { expect, test } from '@playwright/test';

test('the sidebar opens on a phone and navigates', async ({ page }) => {
    await page.goto('/dashboard');

    await page
        .getByRole('button', { name: /toggle sidebar|alternar/i })
        .first()
        .click();
    const menu = page.getByRole('dialog');
    await expect(menu).toBeVisible();
    await menu.getByRole('link', { name: 'Proyectos' }).click();

    await expect(page).toHaveURL(/\/projects/);
    // The sheet closes after choosing a page...
    await expect(menu).toBeHidden();
    await expect(
        page.getByRole('heading', { name: 'Proyectos' }),
    ).toBeVisible();
});

test('the dark theme applies and persists', async ({ page }) => {
    await page.goto('/settings/appearance');
    await page.getByRole('button', { name: /oscuro|dark/i }).click();

    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload();
    await expect(page.locator('html')).toHaveClass(/dark/);

    await page.getByRole('button', { name: /claro|light/i }).click();
    await expect(page.locator('html')).not.toHaveClass(/dark/);
});

test('the login form works with the keyboard only', async ({ browser }) => {
    const context = await browser.newContext({
        storageState: { cookies: [], origins: [] },
    });
    const page = await context.newPage();
    await page.goto('/login');

    // Tab reaches the email field, Enter sends the code...
    const email = page.getByTestId('login-email-input');

    for (
        let i = 0;
        i < 15 &&
        !(await email.evaluate((input) => input === document.activeElement));
        i++
    ) {
        await page.keyboard.press('Tab');
    }

    await expect(email).toBeFocused();
    await page.keyboard.type('nobody-keyboard@example.com');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/login\/verify/);
    await context.close();
});
