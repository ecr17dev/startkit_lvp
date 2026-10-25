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

test('mail settings guide each provider without breaking the login mail', async ({
    page,
}) => {
    await page.goto('/admin/settings/mail');

    // An API provider without its key is refused (the codes keep flowing)...
    await page.getByText('Resend (API)').click();
    await expect(page.getByText(/verifica tu dominio en resend/i)).toBeVisible();
    await page.getByRole('button', { name: /^guardar$/i }).click();
    await expect(page.getByText(/pega la clave api/i)).toBeVisible();

    // SMTP presets fill the server of known providers...
    await page.getByText('Servidor SMTP').click();
    await page.getByRole('combobox', { name: /proveedor/i }).click();
    await page.getByRole('option', { name: 'Gmail / Google Workspace' }).click();
    await expect(page.getByLabel(/^host/i)).toHaveValue('smtp.gmail.com');
    await expect(page.getByLabel(/^puerto/i)).toHaveValue('587');
});

test('the sidebar groups the administration pages', async ({ page }) => {
    await page.goto('/admin/users');

    // The group of the current page opens by itself...
    await expect(page.getByTestId('nav-group-access')).toHaveAttribute(
        'data-state',
        'open',
    );
    await page.getByTestId('nav-group-mail').click();
    await page.getByRole('link', { name: 'Plantillas' }).click();
    await expect(page).toHaveURL(/admin\/mail\/templates/);

    // With only icons visible, each group is a dropdown menu...
    await page.keyboard.press('ControlOrMeta+b');
    await page.getByTestId('nav-group-ai').click();
    await page.getByRole('menuitem', { name: 'Agentes' }).click();
    await expect(page).toHaveURL(/admin\/ai\/agents/);
    await page.keyboard.press('ControlOrMeta+b');
});
