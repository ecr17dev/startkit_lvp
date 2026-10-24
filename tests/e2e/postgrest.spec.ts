import { expect, test } from '@playwright/test';

test('PostgREST stays off unless the deployment enables it', async ({
    page,
}) => {
    await page.goto('/admin/integrations/postgrest');

    await expect(
        page.getByText(/PostgREST está desactivado|PostgREST is disabled/),
    ).toBeVisible();
    await expect(page.getByTestId('postgrest-test')).toBeDisabled();
});
