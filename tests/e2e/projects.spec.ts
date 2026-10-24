import { expect, test } from '@playwright/test';
import { unique } from './support/app';

/**
 * The Projects module, generated with starter:make-module.
 */
test('creates, finds, edits and deletes a project', async ({ page }) => {
    const code = unique('P').slice(0, 20);

    await page.goto('/projects/create');
    await page.locator('#name').fill('Proyecto E2E');
    await page.locator('#code').fill(code);
    await page.locator('#status').click();
    await page.getByRole('option', { name: 'Activo' }).click();
    await page.locator('#budget').fill('1500.25');
    await page.getByRole('button', { name: /crear proyecto/i }).click();

    await expect(page).toHaveURL(/\/projects$/);
    await page.getByPlaceholder(/buscar proyectos/i).fill(code);
    // Wait for the debounced search before navigating...
    await expect(page).toHaveURL(/search=/);
    const row = page.getByRole('row').filter({ hasText: code });
    await expect(row).toBeVisible();
    await expect(row.getByText('Activo')).toBeVisible();

    await row.getByRole('link', { name: 'Proyecto E2E' }).click();
    await page.locator('#name').fill('Proyecto E2E editado');
    await page.getByRole('button', { name: /guardar cambios/i }).click();
    await expect(page).toHaveURL(/\/projects$/);

    await page.getByPlaceholder(/buscar proyectos/i).fill(code);
    await expect(page).toHaveURL(/search=/);
    const edited = page
        .getByRole('row')
        .filter({ hasText: 'Proyecto E2E editado' });
    await expect(edited).toBeVisible();
    await edited.getByRole('button', { name: /eliminar/i }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: /eliminar/i })
        .click();
    await expect(page.getByRole('row').filter({ hasText: code })).toHaveCount(
        0,
    );
});
