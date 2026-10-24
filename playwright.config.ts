import { defineConfig, devices } from '@playwright/test';

/**
 * End to end tests against a real Laravel + PostgreSQL + queue worker.
 * Chromium runs on every PR; Firefox and WebKit with E2E_ALL_BROWSERS=1
 * (scheduled runs and releases). Codes are read from Mailpit
 * (E2E_MAILPIT_URL) or from the log (MAIL_MAILER=log). See docs/e2e.md.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';
const allBrowsers = process.env.E2E_ALL_BROWSERS === '1';
const owner = 'tests/e2e/.auth/owner.json';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './test-results',
    // One application database: tests isolate their data with unique names...
    fullyParallel: false,
    workers: process.env.CI ? 1 : 2,
    retries: process.env.CI ? 1 : 0,
    forbidOnly: !!process.env.CI,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI
        ? [['list'], ['html', { open: 'never' }], ['github']]
        : [['list'], ['html', { open: 'never' }]],
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL,
        testIdAttribute: 'data-test',
        locale: 'es-CL',
        timezoneId: 'America/Santiago',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], storageState: owner },
            testIgnore: /mobile\.spec\.ts/,
        },
        {
            name: 'mobile',
            use: { ...devices['Pixel 7'], storageState: owner },
            testMatch: /mobile\.spec\.ts/,
        },
        ...(allBrowsers
            ? [
                  {
                      name: 'firefox',
                      use: {
                          ...devices['Desktop Firefox'],
                          storageState: owner,
                      },
                      testMatch: /(auth|admin|projects)\.spec\.ts/,
                  },
                  {
                      name: 'webkit',
                      use: {
                          ...devices['Desktop Safari'],
                          storageState: owner,
                      },
                      testMatch: /(auth|admin|projects)\.spec\.ts/,
                  },
              ]
            : []),
    ],
    webServer: process.env.E2E_BASE_URL
        ? undefined
        : {
              command:
                  'php artisan serve --host=127.0.0.1 --port=8000 --no-reload',
              cwd: 'apps/platform',
              url: `${baseURL}/up`,
              reuseExistingServer: !process.env.CI,
              env: { PHP_CLI_SERVER_WORKERS: '4' },
              timeout: 120_000,
          },
});
