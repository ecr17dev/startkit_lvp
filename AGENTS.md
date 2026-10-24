# AGENTS.md — guía para agentes de desarrollo

Este repositorio es un **starter kit**: Laravel 13 + Laravel AI SDK (`laravel/ai`) +
Vue 3 / Inertia / TypeScript + shadcn-vue + Tailwind CSS + PostgreSQL/pgvector +
Docker, organizado como monorepo Composer + pnpm. La especificación completa está en
`docs/especificacion.md`.

## Empieza aquí

- **Instalar o reinstalar**: `./setup.sh --owner=correo` según [INSTALL.md](INSTALL.md) (Docker o
  local, no interactivo, repetible, `--json`, códigos de salida documentados). Correo real con
  Resend: `RESEND_API_KEY=re_… ./setup.sh --mail-from=hola@dominio`.
- **Proyecto propio desde esta plantilla**: `git clone --depth 1
  https://github.com/bluesmilecl/startkit_laravel.git mi-app && cd mi-app && ./setup.sh
  --new-project="Mi App" --owner=correo` (historial nuevo, la plantilla queda como remoto
  `starter`, proyecto de Docker propio).
- **Desplegar (siempre en Dokploy)**: servicio Compose desde GitHub con
  `docker/compose.dokploy.yaml`, aislado, con Auto Deploy y dominio HTTPS; sigue
  `skills/deploy-dokploy/SKILL.md` (MCP de Dokploy) y `docs/dokploy.md`.
- **Estado de una instalación**: `php apps/platform/artisan starter:doctor` (o `starter:verify`).
- No edites `apps/platform/.env` a mano salvo para agregar claves; nunca lo subas ni muestres sus
  secretos.

## Qué leer según la tarea

| Tarea | Lee primero | Atajo |
| --- | --- | --- |
| Instalar, configurar, diagnosticar | `skills/install-starter/SKILL.md` | `./setup.sh`, `starter:doctor` |
| Desplegar un piloto o producción en Dokploy | `skills/deploy-dokploy/SKILL.md`, `docs/dokploy.md` | MCP de Dokploy, `docker/compose.dokploy.yaml` |
| Módulo nuevo: tabla, permisos, Actions, API, pantallas, herramientas IA | `skills/extend-starter/SKILL.md`, `docs/modules.md` | `php artisan starter:make-module` |
| Pantallas: listados, formularios, wizards, gráficos, modales | `skills/build-ui/SKILL.md`, `docs/ui-kit.md` | catálogo en `/ui-kit` |
| IA: conexiones, modelos, tarifas, presupuestos, agentes | `skills/configure-ai/SKILL.md`, `docs/ai.md` | proveedor simulado sin claves |
| Conocimiento (RAG) | `skills/manage-rag/SKILL.md`, `docs/knowledge.md` | `STARTER_FEATURE_RAG` |
| Correo (Resend, SMTP, Postmark, Mailgun), plantillas y automatizaciones | `docs/mail.md` | Panel → Correo |
| Login, roles, permisos, auditoría | `docs/identity.md` | `starter:permissions` |
| API REST y cliente TS | `docs/api-reference.md` | `starter:api-docs` |

Claude Code carga estas skills solas (`.claude/skills/` enlaza a `skills/`); otros agentes deben
leer el `SKILL.md` indicado.

## Mapa rápido

| Qué | Dónde (`apps/platform/` salvo indicación) |
| --- | --- |
| Rutas | `routes/web.php`, `admin.php`, `settings.php`, `api.php`, `modules/*.php` |
| Casos de uso (autorizan, validan, auditan) | `app/Actions/*` |
| Controladores | `app/Http/Controllers/{Admin,Api,Auth,Settings,Ai}` |
| Páginas Vue/Inertia | `resources/js/pages/*` (rutas tipadas de Wayfinder en `@/routes`) |
| Kit de UI | `resources/js/components/kit` y `packages/js/ui` |
| Permisos y roles | `packages/php/identity/src/Permissions.php` |
| Ajustes del sistema | `config/starter.php` (`settings.defaults`) y `settings()` |
| IA y conocimiento | `packages/php/ai`, `packages/php/knowledge` |
| Traducciones | `lang/es.json` |
| Módulo de referencia | Proyectos: `docs/modules/projects.md` |
| Pruebas | `tests/Feature/*` (PostgreSQL real) y `tests/e2e/*` en la raíz (Playwright) |

## Stack fijo

No introduzcas otro stack por conveniencia: nada de Vercel AI SDK, backends Node,
Nuxt, NestJS, Redis/Horizon/Octane/Reverb obligatorios ni otro motor vectorial. Toda
llamada a modelos de IA del servidor pasa por `laravel/ai`.

## Estructura y límites

- `apps/platform`: aplicación Laravel (HTTP, Inertia, páginas Vue, `User`). Ejecuta
  Composer con `--working-dir=apps/platform` y Artisan desde ese directorio.
- `packages/php/*`: módulos de dominio (`Starter\Administration`, `Starter\Identity`,
  `Starter\Ai`, `Starter\Knowledge`, `Starter\Console`). Sin lock propio; providers
  registrados en `apps/platform/bootstrap/providers.php`.
- `packages/js/*`: `@starter/ui` (shadcn-vue), `@starter/chat`, `@starter/api-client`.
- Casos de uso compartidos por panel, API y herramientas del chat: `app/Actions`
  (`Users`, `Roles`, `Invitations`, `Ai`, `Knowledge`). Cada Action autoriza (Policies), valida (sus
  `rules()` las reutilizan los Form Requests), aplica la delegación
  (`Starter\Identity\Authorization\Delegation`) y audita con `audit()`.
  Nunca dupliques reglas en un controlador o en una herramienta IA.

## Reglas

- **Autorización**: Policies/Gates + `spatie/laravel-permission` con el catálogo de
  `Starter\Identity\Permissions` (agrega permisos ahí o con `Permissions::register()`
  y ejecuta `php artisan starter:permissions`). Sin `Gate::before`: el rol `owner`
  tiene todos los permisos explícitamente. El backend siempre valida; las props de
  permisos solo adaptan la interfaz. Nunca otorgues privilegios que el actor no tiene.
- **Autenticación**: OTP por correo (`App\Auth\PasswordlessAuthentication`); las
  contraseñas solo existen con `STARTER_AUTH_METHOD=email-otp+password`. Ver
  `docs/identity.md`.
- **Secretos**: nunca en props Inertia, bundles Vite, logs ni JSON exportable. Las
  credenciales se guardan cifradas (`encrypted` cast) o como referencias `env:NAME`.
- **Correo**: Resend por defecto (`RESEND_API_KEY`); también SMTP (servidor propio), Postmark,
  Mailgun, log o el `.env`. Se cambia solo con `App\Actions\Settings\UpdateMailSettings` (panel y
  `config-apply`); las claves pasan por `Starter\Administration\Settings\MailCredentials`
  (cifradas, `env:` permitidas o la variable del `.env`). Nunca actives un proveedor sin clave:
  sin correo nadie puede entrar. Todo correo sale de una plantilla de
  `Starter\Administration\Mail\MailTemplates` (`{{ variable }}` escapadas, nunca Blade): para uno
  nuevo define una `MailTemplateDefinition` y envíalo con `TemplateMail`; los envíos automáticos van
  por `App\Mail\Automation\MailAutomations` (una fila en `mail_deliveries` por envío, cola `mail`).
  Ver `docs/mail.md`.
- **Agentes**: el chat siempre usa la versión publicada y autorizada de un agente
  (`Starter\Ai\Agents\AgentManager::resolve()`); nunca aceptes system prompt,
  herramientas ni colecciones desde el cliente. Las herramientas se registran en
  `Starter\Ai\Agents\ToolRegistry` y llaman a las mismas Actions que el panel.
  Sin claves, usa el proveedor simulado (`STARTER_AI_SIMULATED=true`).
- **Costos IA**: toda llamada se registra en el libro de consumo. Una tarifa
  desconocida produce costo desconocido (`null`), nunca cero. En código propio usa
  `Starter\Ai\AiGateway::run()`/`::stream()` (valida el modelo, reserva
  presupuesto y cierra la solicitud); las llamadas directas al SDK quedan
  registradas por `Listeners\RecordSdkUsage`. Montos con `brick/math` como
  cadenas decimales. Ver `docs/ai.md`.
- **Conocimiento (RAG)**: opcional (`STARTER_FEATURE_RAG`). Nunca filtres por acceso en
  la interfaz: las listas de acceso (`Starter\Knowledge\Support\Principals`) se aplican
  dentro de la consulta. Las URLs se leen solo con `SafeFetcher` (anti-SSRF). Todo trabajo
  pesado va por `RagJobs` (registro `rag_jobs` + cola) y es idempotente. Embeddings con
  `Starter\Knowledge\Embeddings\Embedder` (pasa por `AiGateway`). Ver `docs/knowledge.md`.
- **Migraciones**: idempotentes, con claves foráneas e índices; fechas en UTC;
  dinero en `decimal` (nunca floats para acumular).
- **Textos**: escribe las cadenas en inglés y tradúcelas en `apps/platform/lang/es.json`
  (backend `__()`, frontend `t()` de `@/lib/i18n`).
- **UI**: arma las pantallas con el kit (`@/components/kit`: `DataTable` con modo tarjetas,
  `FormField`, `WizardForm`, gráficos, `Modal`, `ActionsMenu`, `StatusAlert`, `notify`, `Icon`)
  y las primitivas de `@starter/ui/components/*`; catálogo en vivo en `/ui-kit` y guía en
  `docs/ui-kit.md`. Agrega primitivas del registro de shadcn-vue `new-york-v4` con
  `node packages/js/ui/scripts/add.mjs <componente>`. Las tablas del servidor ordenan solo
  con `App\Support\TableSort`; los gráficos usan `--chart-1..8` en orden fijo.

## Comandos

```bash
./setup.sh --owner=tu@correo.com                 # instalación completa (INSTALL.md)
./setup.sh --new-project="Mi App" --owner=...    # proyecto propio desde un clon de la plantilla
pnpm install && pnpm build                       # dependencias y assets
cd apps/platform
composer install
php artisan migrate --seed
php artisan test                                 # PHPUnit sobre PostgreSQL (starter_testing)
composer lint && composer types:check            # Pint + PHPStan (incluye packages/php y tooling/create-starter)
cd ../.. && pnpm check && pnpm types:check       # lint/format + vue-tsc
pnpm test:e2e                                    # Playwright (docs/e2e.md)
php apps/platform/artisan starter:doctor         # diagnóstico
php apps/platform/artisan starter:verify         # doctor, config, contratos, migraciones, permisos
php apps/platform/artisan starter:make-module    # módulo completo (docs/modules.md)
```

Skills para agentes de desarrollo en `skills/`: Claude Code las carga desde `.claude/skills/`
(enlaces); para otros agentes, ver `docs/skills.md`.

## Verificación antes de terminar

1. `php artisan test` en verde (PostgreSQL real, no SQLite).
2. `composer lint:check`, `composer types:check`, `pnpm check`, `pnpm types:check`.
3. `pnpm build` y, si cambiaste la API, `php artisan starter:api-docs` (OpenAPI estable y
   `docs/api-reference.md`) seguido de `pnpm api:client:generate`; `php artisan
   starter:contracts-check` confirma que todo coincide.
4. Si tocaste flujos de usuario, `pnpm test:e2e`.
5. Distingue siempre en tu reporte qué está implementado, qué es configuración
   pendiente, qué es simulado y qué verificaste de verdad.
