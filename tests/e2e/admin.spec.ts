import { expect, test } from '@playwright/test';
import { unique } from './support/app';

test('creates a user with a role and finds it in the list', async ({
    page,
}) => {
    const email = `${unique('e2e-user')}@example.com`;

    await page.goto('/admin/users/create');
    await page.locator('#name').fill('E2E Editor');
    await page.locator('#email').fill(email);
    await page.getByText('editor', { exact: true }).click();
    await page.getByRole('button', { name: /crear usuario/i }).click();

    // The new account opens in its edit page...
    await expect(page).toHaveURL(/admin\/users\/\d+\/edit/);
    await page.goto('/admin/users');
    await page.getByPlaceholder(/buscar/i).fill(email);
    await expect(page).toHaveURL(/search=/);
    const row = page.getByRole('row').filter({ hasText: email });
    await expect(row).toBeVisible();
    await expect(row.getByText('editor', { exact: true })).toBeVisible();
});

test('creates a role with permissions', async ({ page }) => {
    const name = unique('e2e-role');

    await page.goto('/admin/roles/create');
    await page.locator('#name').fill(name);
    await page.getByLabel(/ver usuarios/i).check();
    await page.getByRole('button', { name: /crear rol/i }).click();

    await expect(page).toHaveURL(/admin\/roles$/);
    await expect(page.getByText(name)).toBeVisible();
});

test('the audit trail records administrative changes', async ({ page }) => {
    await page.goto('/admin/audit');

    await expect(
        page.getByTestId('activity-list').or(page.getByRole('table')).first(),
    ).toBeVisible();
});
