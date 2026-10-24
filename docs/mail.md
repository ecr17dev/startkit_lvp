# Correo

El correo lleva los códigos de acceso, las invitaciones, la recuperación de contraseña y las
notificaciones. Sin correo no se puede iniciar sesión, así que el starter nunca activa un
proveedor que no tenga su clave.

## Proveedores

| Proveedor | Cuándo | Qué necesitas |
| --- | --- | --- |
| **Resend (API)** — recomendado | producción, configuración en minutos | clave `re_…` y un dominio verificado en Resend |
| **SMTP** | servidor propio o cualquier proveedor | host, puerto, cifrado, usuario y contraseña |
| **Postmark (API)** | alternativa transaccional | token de API del servidor |
| **Mailgun (API)** | alternativa transaccional | clave privada, dominio de envío y región (EE. UU. o UE) |
| **`.env`** | lo que digan las variables `MAIL_*` | — |
| **Log** | desarrollo sin servidor | los correos quedan en `storage/logs/laravel.log` |

En desarrollo, Mailpit (<http://localhost:8025>) captura todo sin enviar nada.

El SMTP trae ajustes listos que llenan host, puerto y cifrado: servidor propio, Gmail/Google
Workspace (contraseña de aplicación), Amazon SES (credenciales SMTP, no las claves de AWS),
Brevo, SendGrid (usuario `apikey`), Zoho y Resend por SMTP (usuario `resend`).

## Tres formas de configurarlo

### 1. Al instalar (Resend por defecto)

```bash
RESEND_API_KEY=re_xxx ./setup.sh --owner=tu@correo.com --mail-from=hola@tudominio.com
```

Con `RESEND_API_KEY` en el entorno (o ya escrita en `apps/platform/.env`), `setup.sh` deja
`MAIL_MAILER=resend`. Sin dominio verificado usa `onboarding@resend.dev`, que solo entrega al
correo de tu cuenta de Resend: sirve para entrar como propietario mientras verificas el dominio.
Sin clave, el correo queda en Mailpit (o en el log si no hay Mailpit). `--mail=resend|mailpit|log`
fuerza la opción.

### 2. Desde el panel

**Administración → Configuración del sistema → Servidor de correo**. Elige el proveedor, pega
la clave, guarda y usa **Enviar prueba**.

- Las claves se guardan cifradas con `APP_KEY` y nunca vuelven al navegador; la pantalla solo
  muestra de dónde salen (guardada `••••abcd`, referencia `env:…`, variable del `.env` o falta).
- Una clave en blanco conserva la guardada. Si no hay ninguna, se usa la del `.env`
  (`RESEND_API_KEY`, `POSTMARK_API_KEY`, `MAILGUN_SECRET`).
- También puedes escribir una referencia: `env:RESEND_API_KEY`, `env:POSTMARK_API_KEY`,
  `env:MAILGUN_SECRET`, `env:MAIL_PASSWORD` o `env:STARTER_MAIL_SECRET_*`. Otras variables
  (`APP_KEY`, `DB_*`…) se rechazan.
- Guardar exige `settings.update`, queda en la auditoría (sin secretos) y reinicia los workers
  de la cola para que tomen la configuración.

### 3. Con `starter.config.json`

```json
"mail": {
    "transport": "resend",
    "from": "hola@tudominio.com",
    "fromName": "Mi proyecto",
    "credentialRef": "env:RESEND_API_KEY"
}
```

| `transport` | `credentialRef` | Extra |
| --- | --- | --- |
| `resend` | `env:RESEND_API_KEY` (o `null`: se lee del `.env`) | — |
| `postmark` | `env:POSTMARK_API_KEY` | — |
| `mailgun` | `env:MAILGUN_SECRET` | `domain`, `region` (`us` o `eu`) |
| `smtp` | referencia a una URL `smtps://usuario:clave@host:465` | — |
| `log`, `mailpit` | — | `mailpit` deja las variables `MAIL_*` del `.env` |

`./setup.sh --config=starter.config.json` (o `php artisan starter:config-apply`) usa la misma
Action que el panel. Si la clave no está disponible, avisa y deja el proveedor actual para no
romper el inicio de sesión. Las claves nunca van escritas en el archivo: el validador rechaza
valores que parecen secretos, incluidas las claves `re_…`.

## Por variables de entorno

```dotenv
MAIL_MAILER=resend
RESEND_API_KEY=re_xxx
MAIL_FROM_ADDRESS="hola@tudominio.com"
MAIL_FROM_NAME="${APP_NAME}"

# Postmark: MAIL_MAILER=postmark y POSTMARK_API_KEY
# Mailgun:  MAIL_MAILER=mailgun, MAILGUN_DOMAIN, MAILGUN_SECRET y MAILGUN_ENDPOINT=api.eu.mailgun.net en la UE
# SMTP:     MAIL_MAILER=smtp y MAIL_HOST, MAIL_PORT, MAIL_SCHEME (smtp o smtps), MAIL_USERNAME, MAIL_PASSWORD
```

El panel en modo **Usar la configuración del .env** respeta estas variables. Lo elegido en el
panel tiene prioridad sobre el `.env`.

## Verificar

- `php artisan starter:doctor` muestra el proveedor y el remitente, y advierte si falta la clave
  o si el remitente es `onboarding@resend.dev`.
- **Probar esta configuración** en el panel envía un correo con los valores del formulario,
  aunque no estén guardados (las claves en blanco usan las guardadas), y muestra el error del
  proveedor si falla (por ejemplo, "API key is invalid" o un dominio sin verificar). El resultado
  queda como «Última prueba» en la pantalla y en la auditoría (`settings.mail_tested`).

## Archivos

| Qué | Dónde |
| --- | --- |
| Reglas, guardado y auditoría | `apps/platform/app/Actions/Settings/UpdateMailSettings.php` |
| Pantalla | `apps/platform/resources/js/pages/admin/settings/Mail.vue` |
| Claves: cifrado, referencias y respaldo del `.env` | `packages/php/administration/src/Settings/MailCredentials.php` |
| Aplicación en cada solicitud y trabajo | `packages/php/administration/src/Settings/RuntimeConfiguration.php` |
| Transportes | `config/mail.php`, `config/services.php` (`resend/resend-php`, `symfony/postmark-mailer`, `symfony/mailgun-mailer`) |
