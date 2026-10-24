# AGENTS.md — guía para agentes de desarrollo

Este repositorio es un **starter kit**: Laravel 13 + Laravel AI SDK (`laravel/ai`) +
Vue 3 / Inertia / TypeScript + shadcn-vue + Tailwind CSS + PostgreSQL/pgvector +
Docker, organizado como monorepo Composer + pnpm. La especificación completa está en
`docs/especificacion.md`.

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
- **UI**: usa componentes de `@starter/ui/components/*`; agrega nuevos con el
  registro de shadcn-vue `new-york-v4` dentro de `packages/js/ui`.

## Comandos

```bash
pnpm install && pnpm build                       # dependencias y assets
cd apps/platform
composer install
php artisan migrate --seed
php artisan test                                 # PHPUnit sobre PostgreSQL (starter_testing)
composer lint && composer types:check            # Pint + PHPStan (incluye packages/php)
cd ../.. && pnpm check && pnpm types:check       # lint/format + vue-tsc
php apps/platform/artisan starter:doctor         # diagnóstico
```

## Verificación antes de terminar

1. `php artisan test` en verde (PostgreSQL real, no SQLite).
2. `composer lint:check`, `composer types:check`, `pnpm check`, `pnpm types:check`.
3. `pnpm build` y, si cambiaste la API, `php artisan scramble:export --path=../../tooling/contracts/openapi.json`
   seguido de `pnpm api:client:generate`.
4. Distingue siempre en tu reporte qué está implementado, qué es configuración
   pendiente, qué es simulado y qué verificaste de verdad.
