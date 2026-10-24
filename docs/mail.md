# Correo

El correo lleva los códigos de acceso, las invitaciones, la recuperación de contraseña, las
notificaciones y las automatizaciones; cada correo sale de una plantilla editable. Sin correo no se puede iniciar sesión, así que el starter nunca activa un
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

## Plantillas

**Administración → Correo → Plantillas** lista todos los correos que envía la plataforma y abre un
editor con vista previa en vivo:

| Plantilla | Clave | Variables propias |
| --- | --- | --- |
| Código de acceso, confirmación de correo, código de invitación, confirmación de acción | `auth.login_code`, `auth.register_code`, `auth.invitation_code`, `auth.confirm_code` | `code`, `expires_minutes` |
| Invitación | `auth.invitation` | `inviter_name`, `accept_url`, `expires_on` |
| Recuperación de contraseña | `auth.password_reset` | `reset_url`, `expires_minutes` |
| Alerta de presupuesto de IA | `ai.budget_threshold` | `budget_name`, `percent`, `period`, `spent`, `limit`, … |
| Correo de prueba | `mail.test` | `mailer` |
| Bienvenida, Te extrañamos, Anuncio, Evento del sistema (bases de las automatizaciones) | `automation.*` | `days_inactive`, `role`, `event_name`, `event_details`, `actor_name` |

Todas aceptan además `app_name`, `app_url`, `login_url`, `recipient_name`, `recipient_email` y
`year`.

- **Sintaxis**: `{{ variable }}` se reemplaza por el valor escapado; `[[Texto|{{ login_url }}]]` en
  su propia línea es un botón (solo enlaces `http(s)` y `mailto:`). El contenido **nunca se compila
  como Blade**: un administrador no puede ejecutar código desde una plantilla.
- **Formatos**: *Simple* (Markdown con el tema de correo de Laravel) o *HTML* (se envía tal cual,
  sin `<script>`, `<iframe>`, formularios, manejadores `on*` ni enlaces `javascript:`). El texto
  plano se genera solo.
- **Idiomas**: cada plantilla se personaliza por idioma; lo que no se personaliza usa el contenido
  por defecto traducido. **Restaurar original** borra la personalización de ese idioma.
- **Editor**: botones de variables (se insertan donde está el cursor), título, negrita, lista y
  botón; vista previa con valores de ejemplo en ancho de computador o teléfono; aviso de variables
  desconocidas (guardar las rechaza); **Enviarme una prueba** manda el contenido sin guardarlo.
- **Generar con IA**: describe el correo y el modelo de texto por defecto escribe asunto y cuerpo
  (Markdown o HTML con diseño) usando solo las variables de la plantilla. Pasa por `AiGateway`
  (libro de consumo y presupuestos) y no guarda nada. Con el proveedor simulado arma un borrador
  local marcado como simulado.
- **Plantillas propias** (`custom.<nombre>`): se crean desde otra plantilla y se usan en las
  automatizaciones; no se pueden borrar mientras una automatización las use.
- Permisos: `mail.read` (ver) y `mail.manage` (editar, probar, generar). Todo queda auditado
  (`mail.template_*`).

En código, envía cualquier plantilla con `new TemplateMail('clave', [...variables])` o
`app(MailTemplates::class)->render('clave', $variables)->toMailMessage()` en una notificación.
Un paquete define las suyas en su service provider:

```php
$this->callAfterResolving(MailTemplates::class, fn (MailTemplates $templates) => $templates->define(new MailTemplateDefinition(
    key: 'billing.invoice', category: 'system', name: 'Invoice', description: 'Sent with each invoice.',
    variables: ['invoice_url' => ['description' => 'Link to the invoice', 'sample' => 'https://…']],
    default: fn () => ['subject' => __('Your invoice'), 'body' => "# ".__('Hello!')."\n\n[[".__('View invoice')."|{{ invoice_url }}]]"],
)));
```

## Automatizaciones

**Administración → Correo → Automatizaciones** envía una plantilla sola, sin programar nada:

| Disparador | Cuándo | Destinatarios |
| --- | --- | --- |
| Bienvenida | al crear una cuenta, de inmediato o con espera (minutos) | la cuenta nueva |
| Inactividad | tras N días sin entrar (`last_login_at` o fecha de creación) | todos los activos o un rol |
| Envío programado | una vez (fecha), cada semana (día) o cada mes (día 1–28), a una hora y zona horaria | todos los activos o un rol |
| Evento del sistema | usuario creado, registro, invitación aceptada, cuenta desactivada/reactivada, cambio de rol, umbral de presupuesto de IA | la persona involucrada o un rol |

- Cada correo es una fila de `mail_deliveries` con una **clave única** (automatización + persona +
  ocurrencia): repetir una ejecución nunca duplica un envío. La inactividad se envía una vez por
  período inactivo; vuelve a salir solo si la persona entra y otra vez se ausenta.
- El envío lo hace la cola `mail` (`App\Jobs\DeliverMail`, 3 intentos). Se omite si la cuenta está
  desactivada o la automatización en pausa; si falla queda el error del proveedor.
- `starter:mail-automations` revisa inactividad y envíos programados; el scheduler lo corre cada 5
  minutos. Los eventos usan la auditoría y la bienvenida la creación del usuario.
- **Envíos** muestra cada correo (pendiente, enviado, fallido u omitido) con su error y permite
  **Reintentar** los fallidos.

Necesitas el worker (`queue:work` con la cola `mail`) y el scheduler; en Docker y Dokploy ya están.

## Archivos

| Qué | Dónde |
| --- | --- |
| Reglas, guardado y auditoría | `apps/platform/app/Actions/Settings/UpdateMailSettings.php` |
| Pantalla | `apps/platform/resources/js/pages/admin/settings/Mail.vue` |
| Claves: cifrado, referencias y respaldo del `.env` | `packages/php/administration/src/Settings/MailCredentials.php` |
| Aplicación en cada solicitud y trabajo | `packages/php/administration/src/Settings/RuntimeConfiguration.php` |
| Transportes | `config/mail.php`, `config/services.php` (`resend/resend-php`, `symfony/postmark-mailer`, `symfony/mailgun-mailer`) |
| Plantillas: registro, render seguro y mailables | `packages/php/administration/src/Mail/` (`MailTemplates`, `TemplateMail`) |
| Plantillas de cada módulo | `IdentityMailTemplates`, `AiMailTemplates`, `apps/platform/app/Mail/AppMailTemplates.php` |
| Acciones de plantillas y automatizaciones | `apps/platform/app/Actions/Mail/` |
| Motor de automatizaciones y envío | `apps/platform/app/Mail/Automation/MailAutomations.php`, `app/Jobs/DeliverMail.php` |
| Redacción con IA | `apps/platform/app/Ai/Mail/MailTemplateWriter.php` |
| Pantallas | `apps/platform/resources/js/pages/admin/mail/` |
