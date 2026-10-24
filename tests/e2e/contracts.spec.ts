import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import { ROOT } from './support/app';

test('the served OpenAPI matches the committed contract', async ({ page }) => {
    const committed = JSON.parse(
        readFileSync(resolve(ROOT, 'tooling/contracts/openapi.json'), 'utf8'),
    ) as { paths: Record<string, unknown> };

    // Outside local environments the document needs api.docs.read (owner session)...
    await page.goto('/dashboard');
    const live = (await (await page.request.get('/docs/api.json')).json()) as {
        paths: Record<string, unknown>;
    };

    expect(Object.keys(live.paths).sort()).toEqual(
        Object.keys(committed.paths).sort(),
    );

    // The interactive viewer (scripts from a CDN) embeds the same document...
    const viewer = await page.request.get('/docs/api');
    expect(viewer.ok()).toBeTruthy();
    expect(await viewer.text()).toContain('apiDescriptionDocument');
});
