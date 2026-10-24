import { expect, test } from '@playwright/test';
import { unique } from './support/app';

/**
 * The floating chat (simulated provider in local/CI) runs operator tools
 * through the same Actions as the panel; sensitive changes wait for a
 * confirmed proposal.
 */
test('a sensitive change is proposed and runs after confirmation', async ({
    page,
}) => {
    const email = `${unique('e2e-chat')}@example.com`;

    // A user to operate on, created in the panel...
    await page.goto('/admin/users/create');
    await page.locator('#name').fill('E2E Chat Target');
    await page.locator('#email').fill(email);
    await page.getByRole('button', { name: /crear usuario/i }).click();
    await page.waitForURL(/admin\/users\/\d+\/edit/);
    const id = page.url().match(/users\/(\d+)\/edit/)?.[1];

    await page.goto('/dashboard');
    await page.getByTestId('floating-chat-toggle').click();
    const chat = page.getByTestId('floating-chat');
    await chat
        .getByTestId('chat-input')
        .fill(`/tool users_disable {"user_id": ${id}}`);
    await chat.getByTestId('chat-send').click();

    // The card of this change (the conversation may hold earlier ones)...
    const card = chat.getByTestId('proposal-card').filter({ hasText: email });
    await expect(card).toBeVisible({ timeout: 20_000 });
    await expect(card).toContainText('E2E Chat Target');
    await card.getByTestId('proposal-confirm').click();

    await expect(card).toContainText(/Hecho|Done/, { timeout: 20_000 });

    await page.goto(`/admin/users?search=${encodeURIComponent(email)}`);
    await expect(
        page.getByRole('row').filter({ hasText: email }),
    ).toContainText(/Desactivado|Disabled/);
});
