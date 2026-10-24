# Identidad, acceso y auditoría

Laravel es la única autoridad de autenticación. Este documento describe lo
implementado en la etapa 2 y dónde vive cada pieza.

## Login con código de un solo uso (OTP)

1. `GET /login` (vista de Fortify) pide el correo.
2. `POST /login/code` crea un desafío (`login_challenges`) y envía el código con
   Laravel Notifications, **sin cola** (`notifyNow`): el acceso no espera trabajos
   de indexación.
3. `GET /login/verify` muestra el campo OTP; el desafío queda ligado a la sesión
   (su id nunca viaja en la URL).
4. `POST /login/verify` verifica y consume el código de forma atómica, autentica con
   el guard `web`, regenera la sesión y redirige al destino previsto. Si el usuario
   tiene 2FA (TOTP), pasa por `two-factor.login`.

Propiedades (`Starter\Identity\Otp\OtpBroker`):

| Propiedad | Implementación |
| --- | --- |
| Valores iniciales | 6 dígitos, 5 minutos, 5 intentos, 60 s entre reenvíos (`STARTER_OTP_*`) |
| Código nunca almacenado | `verifier` = HMAC-SHA256 de `id del desafío | propósito | código` con `STARTER_OTP_SECRET` (o `APP_KEY`) |
| Intentos atómicos | `UPDATE … SET attempts = attempts + 1 WHERE attempts < max_attempts` antes de comparar |
| Uso único | `UPDATE … SET consumed_at = now() WHERE consumed_at IS NULL` (gana una sola petición) |
| Reenvío | Respeta el cooldown, invalida los desafíos previos del mismo correo y propósito |
| Sin enumeración | Correos desconocidos o cuentas desactivadas reciben un desafío *no entregable*: misma respuesta, ningún correo, nunca verifica |
| Límites | `throttle:otp-request` (por IP y por correo) y `throttle:otp-verify` (por IP) |
| Retención | Desafíos vencidos se eliminan cada hora (`model:prune`) |

Propósitos: `login`, `register`, `invitation` y `confirm` (confirmación de acciones
sensibles). Las llaves de acceso (passkeys) siguen disponibles como alternativa sin
contraseña.

### Contraseñas

El método es una decisión de despliegue: `STARTER_AUTH_METHOD=email-otp` (por
defecto) deshabilita con 404 el login por contraseña, los restablecimientos, el
cambio de contraseña y la confirmación por contraseña
(`App\Http\Middleware\EnforceAuthMethod`). Con `email-otp+password` se habilitan.
No existe interruptor en el panel.

### Confirmación de acciones sensibles

El middleware `password.confirm` de Laravel sigue protegiendo las pantallas
sensibles (seguridad, eliminar cuenta). La página de confirmación ofrece llave de
acceso, código por correo (`POST /user/confirm-code` y `/user/confirm-code/verify`)
y, si el despliegue lo permite, contraseña.

## Registro e invitaciones

El modo se guarda en `settings` (`auth.registration`) y se cambia en
**Configuración → Autenticación** o durante el onboarding:

| Modo | Efecto |
| --- | --- |
| `open` | `/register` pide nombre y correo; la cuenta se crea al verificar el código |
| `invite-only` (inicial) | Solo invitaciones; `/register` responde 404 |
| `closed` | Sin registros ni invitaciones; los administradores crean usuarios |

- Registrarse con un correo existente envía un código de login (sin duplicar cuentas
  ni revelar que existe).
- Los usuarios se crean dentro de una transacción con `createOrFirst`: reintentos de
  entrega no generan cuentas duplicadas.
- El rol por defecto nunca es `owner` ni `admin` (se fuerza `reader`).
- Invitaciones (`invitations`): token aleatorio del que solo se guarda el SHA-256,
  vencimiento (`STARTER_INVITATION_TTL_DAYS`), reenvío con token nuevo, revocación y
  aceptación con código. Solo se invita con roles que el invitador puede otorgar.

## Propietario y onboarding

```bash
php artisan starter:owner tu@correo.com --name="Tu Nombre"
```

Crea o promueve al propietario desde una consola confiable; el correo queda sin
verificar hasta el primer código. Nunca se otorgan privilegios al primer visitante.
Tras ingresar, el propietario completa el onboarding (nombre, nombre de la
aplicación, idioma, zona horaria y modo de registro). Los usuarios sin nombre
completan su perfil.

## Roles y permisos

Catálogo único en `Starter\Identity\Permissions` (sincronizado con
`php artisan starter:permissions`, también ejecutado por el servicio `migrate` de
Docker y por `db:seed`). Los módulos agregan permisos con `Permissions::register()`.

| Rol | Permisos iniciales |
| --- | --- |
| `owner` | Todos, siempre (rol inmutable) |
| `admin` | Todos excepto `permissions.manage` |
| `editor` | `users.read`, `ai.chat`, `ai.usage.read`, RAG de contenido, `api.docs.read`, `api.tokens.create` |
| `reader` | `ai.chat`, `rag.collections.read`, `rag.documents.read`, `rag.playground.use` |

No hay `Gate::before`: las Policies (`UserPolicy`, `RolePolicy`,
`InvitationPolicy`) aplican a todos. Reglas de delegación
(`Starter\Identity\Authorization\Delegation`):

- Nadie otorga ni quita permisos que no tiene (directamente o mediante un rol).
- Solo un propietario otorga el rol `owner` y gestiona a otros propietarios.
- Nadie cambia sus propios roles, ni se desactiva o elimina desde el panel.
- El último propietario activo no puede perder el rol, desactivarse ni eliminarse.
- Los roles de sistema no se renombran ni eliminan.

Los casos de uso viven en `app/Actions` (`Users`, `Roles`, `Invitations`) y los
comparten el panel, la API y (en la etapa 4) las herramientas del chat. Cada Action
autoriza, valida y audita.

## Usuarios y sesiones

- Desactivar un usuario revoca sus sesiones (tabla `sessions`) y tokens Sanctum; si
  aún tiene una petición en curso, `EnsureUserIsActive` cierra su sesión.
- Eliminar anonimiza nombre, correo, 2FA y passkeys y aplica *soft delete*: la
  auditoría y el libro de consumo conservan referencias consistentes.
- **Ajustes → Sesiones** lista los dispositivos propios y permite cerrar uno o
  todos los demás; **Administración → Sesiones** y el detalle de usuario permiten
  revocar sesiones ajenas (`users.update`). Los ids de sesión nunca se exponen:
  se usa un HMAC.

## Auditoría

`audit_events` (paquete `administration`) registra actor, acción, recurso, canal
(`web`, `api`, `cli`, `chat`, `system`), IP, user agent e id de correlación
(`X-Request-Id`, propagado a jobs y logs mediante Laravel Context). Las claves
sensibles (`password`, `token`, `api_key`, `code`, …) se redactan. Retención:
`STARTER_AUDIT_RETENTION_DAYS` (365 por defecto) con `model:prune` diario. La página
**Administración → Auditoría** requiere `audit.read`.

## API

| Método | Ruta | Uso |
| --- | --- | --- |
| POST | `/api/v1/auth/otp` | Solicita un código; siempre 202 con `challenge_id` |
| POST | `/api/v1/auth/otp/verify` | Cambia código por token (requiere `api.tokens.create`) |
| POST | `/api/v1/auth/token` | Token con contraseña (solo con `email-otp+password`) |
| GET/POST/PATCH/DELETE | `/api/v1/users…` | Mismas Actions y Policies que el panel |
| PATCH | `/api/v1/users/{user}/status` | Desactivar o activar |
| GET/POST/PATCH/DELETE | `/api/v1/roles…`, GET `/api/v1/permissions` | Roles y catálogo |
| GET/POST/DELETE | `/api/v1/invitations…` | Invitaciones |

Las abilities del token (`read`, `write`, `ai`) acotan el acceso pero no sustituyen
las Policies.

## Páginas de error

`bootstrap/app.php` renderiza `errors/Error` (Inertia) para 403, 404, 419 y 429 y,
fuera de local/testing, 500 y 503. La API mantiene respuestas JSON. Las URL
desconocidas pasan por la ruta *fallback* para conservar idioma y sesión.
