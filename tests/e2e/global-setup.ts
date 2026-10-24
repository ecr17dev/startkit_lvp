import { mkdirSync } from 'node:fs';
import { chromium, selectors, type FullConfig } from '@playwright/test';
import { artisan, OWNER_EMAIL, signIn } from './support/app';

/**
 * Prepare the owner account and save its session for the tests (the
 * catalog of permissions is synced by starter:owner).
 */
export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL as string;

    artisan('starter:owner', OWNER_EMAIL, '--name=E2E Owner');
    mkdirSync('tests/e2e/.auth', { recursive: true });

    selectors.setTestIdAttribute('data-test');
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL, locale: 'es-CL' });

    await signIn(page, OWNER_EMAIL);

    if (page.url().includes('onboarding')) {
        await page.getByTestId('onboarding-app-name').fill('Starter E2E');
        await page.getByTestId('onboarding-submit').click();
        await page.waitForURL('**/dashboard');
    }

    await page.context().storageState({ path: 'tests/e2e/.auth/owner.json' });
    await browser.close();
}
