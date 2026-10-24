import { execFileSync } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, type Page } from '@playwright/test';

export const ROOT = resolve(__dirname, '../../..');
export const APP = resolve(ROOT, 'apps/platform');
/**
 * A fresh owner per run: one-time codes have a resend cooldown and hourly
 * limits per address.
 */
export const OWNER_EMAIL =
    process.env.E2E_OWNER_EMAIL ??
    `e2e-owner-${Date.now().toString(36)}@example.com`;

/**
 * Create (or promote) an account that can sign in, with its own limits.
 */
export function account(prefix: string): string {
    const email = `${unique(prefix)}@example.com`;
    artisan('starter:owner', email, '--name=E2E Account');

    return email;
}

/**
 * Run an Artisan command of the application under test.
 */
export function artisan(...args: string[]): string {
    return execFileSync(process.env.E2E_PHP ?? 'php', ['artisan', ...args], {
        cwd: APP,
        encoding: 'utf8',
        env: { ...process.env },
    });
}

/**
 * A value unique to this test run and worker (data isolation without
 * resetting the shared database).
 */
export function unique(prefix: string): string {
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
}

/**
 * Where the mailbox is now, so only newer codes are accepted: the size of
 * the mail log, or the current time for Mailpit.
 */
export function mailMarker(): number {
    if (process.env.E2E_MAILPIT_URL) {
        return Date.now() - 1000;
    }

    try {
        return statSync(logPath()).size;
    } catch {
        return 0;
    }
}

/**
 * The first one-time code sent to an email after the marker: Mailpit API
 * when E2E_MAILPIT_URL is set, otherwise the mail log (MAIL_MAILER=log).
 * Mails may be queued: it waits for the worker.
 */
export async function latestCode(
    email: string,
    marker: number,
): Promise<string> {
    const mailpit = process.env.E2E_MAILPIT_URL;

    for (let attempt = 0; attempt < 80; attempt++) {
        const code = mailpit
            ? await codeFromMailpit(mailpit, email, marker)
            : codeFromLog(email, marker);

        if (code) {
            return code;
        }

        await new Promise((done) => setTimeout(done, 250));
    }

    throw new Error(`No one-time code found for ${email}`);
}

function logPath(): string {
    return process.env.E2E_LOG ?? resolve(APP, 'storage/logs/laravel.log');
}

async function codeFromMailpit(
    base: string,
    email: string,
    since: number,
): Promise<string | null> {
    const search = await fetch(
        `${base}/api/v1/search?query=${encodeURIComponent(`to:${email}`)}&limit=5`,
    );
    const { messages } = (await search.json()) as {
        messages: { ID: string; Created: string }[];
    };
    const recent = (messages ?? []).find(
        (message) => Date.parse(message.Created) >= since,
    );

    if (!recent) {
        return null;
    }

    const message = (await (
        await fetch(`${base}/api/v1/message/${recent.ID}`)
    ).json()) as {
        Text: string;
    };

    return message.Text.match(/\b(\d{6})\b/)?.[1] ?? null;
}

function codeFromLog(email: string, offset: number): string | null {
    // The marker is a byte offset (the log has multibyte text)...
    const log = readFileSync(logPath()).subarray(offset).toString('utf8');
    const start = log.indexOf(`To: ${email}`);

    return start === -1
        ? null
        : (log.slice(start).match(/#\s*(\d{6})/)?.[1] ?? null);
}

/**
 * Passwordless sign in with a code sent by email.
 */
export async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByTestId('login-email-input').fill(email);
    const marker = mailMarker();
    await page.getByTestId('send-login-code-button').click();
    await page.waitForURL('**/login/verify');

    const code = await latestCode(email, marker);
    await page
        .locator(
            '[data-test=otp-code-input] input, input[data-test=otp-code-input]',
        )
        .first()
        .click();
    await page.keyboard.type(code);
    await page.getByTestId('verify-code-button').click();
    await expect(page).toHaveURL(/dashboard|onboarding/);
}

/**
 * Read a toast or inline confirmation.
 */
export async function expectToast(page: Page, text: string | RegExp) {
    await expect(
        page.locator('[data-sonner-toast]').filter({ hasText: text }).first(),
    ).toBeVisible();
}
