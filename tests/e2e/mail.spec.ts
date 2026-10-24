import { expect, test } from '@playwright/test';
import { expectToast, unique } from './support/app';

test('the template editor previews variables, drafts with AI and saves', async ({
    page,
}) => {
    const name = unique('E2E plantilla');

    await page.goto('/admin/mail/templates');
    await expect(page.getByTestId('template-auth.login_code')).toBeVisible();

    // A custom template starts from the announcement...
    await page.getByTestId('new-template').click();
    await page.getByLabel(/^nombre/i).fill(name);
    await page.getByRole('button', { name: /crear plantilla/i }).click();
    await expect(page).toHaveURL(/admin\/mail\/templates\/custom\.[^/]+\/edit/);

    // Variables are inserted where the cursor is and previewed with samples...
    const subject = page.getByTestId('template-subject');
    await subject.fill('Hola ');
    await page
        .getByTestId('variables')
        .getByRole('button', { name: 'recipient_name' })
        .click();
    await expect(subject).toHaveValue('Hola {{ recipient_name }}');
    await expect(page.getByTestId('preview-subject')).toHaveText(
        'Hola Ana Pérez',
    );

    // Unknown placeholders are flagged before saving...
    await page.getByTestId('template-body').fill('Hola {{ nombre }}');
    await expect(page.getByTestId('unknown-variables')).toBeVisible();

    // The simulated provider writes a draft without calling an AI...
    await page.getByTestId('ai-open').click();
    await page
        .getByTestId('ai-description')
        .fill('Bienvenida con los primeros pasos y un botón para entrar.');
    await page.getByTestId('ai-generate').click();
    await expect(page.getByTestId('template-body')).toHaveValue(
        /\{\{ recipient_name \}\}/,
    );
    await expect(page.getByTestId('unknown-variables')).toBeHidden();
    await expect(
        page.frameLocator('[data-test=preview-frame]').getByText(/ana pérez/i),
    ).toBeVisible();

    await page.getByTestId('template-save').click();
    await expectToast(page, /plantilla guardada/i);
});

test('creates, pauses and deletes a welcome automation', async ({ page }) => {
    const name = unique('E2E bienvenida');

    await page.goto('/admin/mail/automations');
    await page.getByTestId('new-automation').click();
    await expect(page).toHaveURL(/admin\/mail\/automations\/create/);

    await page.getByTestId('automation-name').fill(name);
    await expect(page.getByTestId('automation-summary')).toContainText(
        /al crear una cuenta, de inmediato/i,
    );
    await page.getByTestId('trigger-inactivity').click();
    await page.getByTestId('inactivity-days').fill('45');
    await expect(page.getByTestId('automation-summary')).toContainText(
        /tras 45 días sin entrar/i,
    );
    await page.getByTestId('trigger-user_created').click();
    await page.getByTestId('automation-save').click();

    await expect(page).toHaveURL(/admin\/mail\/automations$/);
    await expectToast(page, /automatización guardada/i);
    const item = page.getByRole('listitem').filter({ hasText: name });
    await expect(item).toContainText(/correo de bienvenida/i);

    // Pause it, then delete it (the shared database stays clean)...
    await item.getByRole('switch').click();
    await expect(item.getByText(/en pausa/i)).toBeVisible();
    await item.getByRole('button', { name: /acciones/i }).click();
    await page.getByRole('menuitem', { name: /eliminar/i }).click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: /eliminar/i })
        .click();
    await expect(page.getByText(name)).toBeHidden();
});
