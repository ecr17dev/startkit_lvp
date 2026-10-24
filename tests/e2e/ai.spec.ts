import { expect, test } from '@playwright/test';

test('AI administration pages show the catalog and costs', async ({ page }) => {
    await page.goto('/admin/ai/connections');
    await expect(page.getByText(/simulated|simulad/i).first()).toBeVisible();

    await page.goto('/admin/ai/models');
    await expect(page.getByText('simulated-chat').first()).toBeVisible();

    await page.goto('/admin/ai/agents');
    await expect(
        page.getByRole('heading', { name: /agentes/i }).first(),
    ).toBeVisible();

    await page.goto('/admin/ai/usage');
    await expect(
        page.getByRole('heading', { name: /uso y costos/i }).first(),
    ).toBeVisible();
});
