import { expect, test } from '@playwright/test';
import { account, latestCode, mailMarker, signIn } from './support/app';

// These tests start signed out...
test.use({ storageState: { cookies: [], origins: [] } });

test('signs in with a one-time code sent by email', async ({ page }) => {
    await signIn(page, account('e2e-login'));

    await expect(page).toHaveURL(/dashboard|onboarding/);
});

test('a wrong code is rejected and the right one works', async ({ page }) => {
    const email = account('e2e-code');
    await page.goto('/login');
    await page.getByTestId('login-email-input').fill(email);
    const marker = mailMarker();
    // Keyboard only: Enter submits the form...
    await page.getByTestId('login-email-input').press('Enter');
    await page.waitForURL('**/login/verify');

    const code = await latestCode(email, marker);
    const wrong = code === '000000' ? '111111' : '000000';
    await page
        .locator(
            '[data-test=otp-code-input] input, input[data-test=otp-code-input]',
        )
        .first()
        .click();
    await page.keyboard.type(wrong);
    await page.getByTestId('verify-code-button').click();
    await expect(page).toHaveURL(/login\/verify/);
    await expect(
        page.getByText(/no es válido|is not valid|invalid/i),
    ).toBeVisible();

    await page
        .locator(
            '[data-test=otp-code-input] input, input[data-test=otp-code-input]',
        )
        .first()
        .click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type(code);
    await page.getByTestId('verify-code-button').click();
    await expect(page).toHaveURL(/dashboard|onboarding/);
});

test('protected pages redirect to the login', async ({ page }) => {
    await page.goto('/admin/users');

    await expect(page).toHaveURL(/login/);
    await expect(page.getByTestId('login-email-input')).toBeVisible();
});
