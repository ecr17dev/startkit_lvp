# Arquitectura

Monolito modular Laravel 13 dentro de un monorepo Composer + pnpm. Inertia sirve
las páginas Vue; una API JSON versionada (`/api/v1`) cubre las integraciones.
Ambas capas usan las mismas *Actions* y *Policies*.

```text
apps/platform/            Aplicación Laravel 13 + Inertia + Vue (único proyecto Composer resoluble)
packages/php/
  administration/         Settings persistidos, branding, salud, auditoría (Starter\Administration)
  identity/               OTP por correo, registro, invitaciones, catálogo de permisos (Starter\Identity)
  ai/                     Laravel AI SDK: conexiones, catálogo, tarifas, consumo, presupuestos, agentes (Starter\Ai)
  knowledge/              RAG: colecciones, fuentes, indexación, pgvector, citas (Starter\Knowledge)
  starter-console/        Comandos Artisan starter:* (Starter\Console)
packages/js/
  ui/                     Componentes shadcn-vue (@starter/ui)
  chat/                   Composable de streaming del chat (@starter/chat)
  api-client/             Cliente TypeScript generado desde OpenAPI (@starter/api-client)
tooling/contracts/        OpenAPI exportado, esquemas JSON (configuración y manifiesto)
docker/                   Imagen PHP multi-stage, Nginx y Compose con perfiles
docs/                     Documentación Markdown
skills/                   Skills para agentes de desarrollo
```

## Reglas de dependencia

- `apps/platform` requiere los paquetes internos mediante *path repositories*
  (`../../packages/php/*`) y registra sus *service providers* explícitamente en
  `bootstrap/providers.php` (sin auto-discovery).
- Los paquetes declaran sus dependencias pero **no** tienen `composer.lock` propio:
  el único lock es `apps/platform/composer.lock`.
- El frontend comparte código con `workspace:*` de pnpm. El único `pnpm-lock.yaml`
  vive en la raíz.
- `administration` no depende de otros módulos. `identity`, `ai` y `starter-console`
  dependen de `administration`; `knowledge` depende de `ai`.
- La capa HTTP (controladores, Form Requests, API Resources), las páginas Vue y el
  modelo `User` viven en `apps/platform`. Los paquetes aportan modelos de dominio,
  servicios, migraciones, comandos y rutas técnicas (p. ej. `/health/*`).

## Procesos

La misma imagen PHP corre la web (PHP-FPM detrás de Nginx), la cola
(`queue:work database`) y el scheduler (`schedule:work`). Las tareas largas
(indexación, sincronización de precios, envío de correos) se despachan a la cola y
sobreviven al cierre de la petición web. El scheduler usa `onOneServer()` y
`withoutOverlapping()` con locks en la caché de base de datos.

## Datos

PostgreSQL guarda datos, sesiones, caché/locks y la cola. La extensión `vector`
(pgvector) solo es necesaria cuando se activa RAG. Las fechas se guardan en UTC y se
muestran en la zona horaria configurada en el panel.

## Configuración

- Variables de despliegue: `.env` (ver `apps/platform/.env.example`).
- Feature flags y valores por defecto: `apps/platform/config/starter.php`.
- Opciones editables desde el panel: tabla `settings` (secretos cifrados con `APP_KEY`).
- Configuración exportable sin secretos: `starter.config.example.json`
  (esquema en `tooling/contracts/starter.config.schema.json`).
