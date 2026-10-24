import { expect, test } from '@playwright/test';

test('the knowledge lab searches the indexed collection', async ({ page }) => {
    const response = await page.goto('/admin/knowledge');
    test.skip(response?.status() === 404, 'Knowledge (RAG) is disabled');

    await expect(page.getByTestId('rag-status')).toBeVisible();

    await page.goto('/admin/knowledge/lab');
    await page.getByTestId('lab-query').fill('¿Cómo se inicia sesión?');
    await expect(page.getByTestId('lab-submit')).toBeEnabled();
    const search = page.waitForResponse(
        (response) =>
            response.url().includes('/admin/knowledge/lab') &&
            response.request().method() === 'POST',
    );
    await page.getByTestId('lab-submit').click();
    expect((await search).status()).toBeLessThan(400);

    await expect(
        page.locator('[data-test^="lab-result-"]').first(),
    ).toBeVisible({
        timeout: 30_000,
    });
});
