import { expect, test } from '@playwright/test';

/**
 * UI kit catalog (/ui-kit, STARTER_FEATURE_UI_KIT): the reusable pieces
 * work end to end with demo data.
 */
test('the wizard checks each step and returns to a server error', async ({
    page,
}) => {
    await page.goto('/dashboard');
    await page.getByRole('link', { name: 'Kit de UI' }).click();
    await page
        .getByRole('link', { name: /formulario por pasos/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/ui-kit\/wizard$/);

    const next = page.getByRole('button', { name: /siguiente/i });

    // Client-side check of the first step...
    await next.click();
    await expect(
        page.getByText('Este campo es obligatorio.').first(),
    ).toBeVisible();

    await page.getByLabel('Nombre').fill('Ana Pérez');
    await page.getByLabel('Correo').fill('ana@example.com');
    await next.click();

    await page.getByRole('combobox', { name: 'Plan' }).click();
    await page.getByRole('option', { name: 'Team' }).click();
    await page.getByLabel('Fecha de inicio').click();
    // The calendar opens on today: pick it with the keyboard...
    await page.keyboard.press('Enter');
    await next.click();

    await page.getByRole('checkbox', { name: /acepto los términos/i }).click();
    await next.click();
    await page.getByRole('button', { name: 'Crear cuenta' }).click();

    // The server rejects example.com: back to the first step with the error...
    await expect(page.getByText(/example\.com está reservado/)).toBeVisible();
    await page.getByLabel('Correo').fill('ana@acme.cl');
    await next.click();
    await next.click();
    await next.click();
    await page.getByRole('button', { name: 'Crear cuenta' }).click();

    await expect(
        page.getByText(
            'El servidor aceptó el formulario (demo: no se guardó nada).',
        ),
    ).toBeVisible();
});

test('the data table searches, sorts, selects and switches to cards', async ({
    page,
}) => {
    await page.goto('/ui-kit/tables');
    const table = page.getByTestId('data-table').first();
    const rows = table.getByTestId('data-table-row');

    await table
        .getByRole('searchbox', { name: 'Buscar clientes' })
        .fill('andes');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('Andes Foods');
    await table.getByRole('searchbox', { name: 'Buscar clientes' }).fill('');

    const mrr = table.getByRole('button', { name: 'MRR', exact: true });
    await mrr.click();
    await expect(rows.first()).toContainText('Acme Ltda.');
    await mrr.click();
    await expect(rows.first()).toContainText('Kawésqar Tours');

    const checkboxes = table.getByRole('checkbox', {
        name: 'Seleccionar fila',
    });
    await checkboxes.nth(0).click();
    await checkboxes.nth(1).click();
    await expect(table.getByText('2 seleccionados')).toBeVisible();
    await table.getByRole('button', { name: 'Archivar' }).click();
    await expect(page.getByText('2 clientes archivados (demo).')).toBeVisible();

    await table.getByRole('button', { name: 'Tarjetas' }).click();
    await expect(table.getByRole('table')).toHaveCount(0);
    await expect(table.getByRole('listitem')).toHaveCount(8);
});

test('the modal validates and the command palette navigates', async ({
    page,
}) => {
    await page.goto('/ui-kit/overlays');
    await page.getByRole('button', { name: 'Invitar usuario' }).click();
    const dialog = page.getByRole('dialog');
    const send = dialog.getByRole('button', { name: 'Enviar invitación' });

    await send.click();
    await expect(dialog.getByText('Ingresa un correo válido.')).toBeVisible();
    await dialog.getByLabel('Correo').fill('nuevo@acme.cl');
    await send.click();
    await expect(dialog).toBeHidden();
    await expect(
        page.getByText('Invitación enviada a nuevo@acme.cl (demo).'),
    ).toBeVisible();

    await page.goto('/ui-kit/navigation');
    await page.keyboard.press('ControlOrMeta+k');
    const palette = page.getByRole('dialog');
    await palette.getByPlaceholder('Escribe un comando o busca…').fill('gráf');
    await palette.getByRole('option', { name: 'Gráficos' }).click();

    await expect(page).toHaveURL(/\/ui-kit\/charts$/);
    await expect(
        page.getByRole('heading', { name: 'Tarjetas KPI' }),
    ).toBeVisible();
});
