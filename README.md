# Laravel Starter — Laravel 13 + IA

Base para comenzar proyectos con autenticación por código, roles y permisos,
panel de administración, inteligencia artificial con **Laravel AI SDK** y RAG
opcional, listos para usar.

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 13 (PHP 8.3+), Fortify, Sanctum, spatie/laravel-permission |
| IA | `laravel/ai` (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Amazon Bedrock, Azure, Groq, Mistral, xAI, Ollama y APIs compatibles con OpenAI) |
| Frontend | Vue 3 + Inertia v3 + TypeScript estricto, shadcn-vue (reka-ui), Tailwind CSS 4, Vite |
| Datos | PostgreSQL (datos, sesiones, caché y cola) + pgvector para RAG |
| API | REST `/api/v1` con Sanctum, OpenAPI autogenerado con Scramble y cliente TypeScript |
| Operación | Docker Compose con perfiles, cola y scheduler sobre PostgreSQL |
| Repositorio | Monorepo Composer (path repositories) + pnpm workspaces |

La especificación completa (visión, alcance y criterios de aceptación) está en
[`docs/especificacion.md`](docs/especificacion.md).

## Inicio rápido

```bash
corepack enable && pnpm install
cd apps/platform
cp .env.example .env              # PostgreSQL en DB_*; Mailpit en :1025
composer install
php artisan key:generate
php artisan migrate --seed
cd ../.. && pnpm build
cd apps/platform && composer dev  # http://localhost:8000
```

Con Docker: ver [`docs/installation.md`](docs/installation.md) y
[`docs/docker.md`](docs/docker.md).

## Estructura

```text
apps/platform/         Aplicación Laravel 13 + Inertia + Vue
packages/php/          administration · identity · ai · knowledge · starter-console
packages/js/           @starter/ui · @starter/chat · @starter/api-client
tooling/contracts/     OpenAPI y esquemas JSON
docker/                Imagen, Nginx y Compose
docs/                  Documentación
```

## Estado de la implementación

El plan sigue las etapas de la especificación. Esta tabla distingue lo
implementado y verificado de lo pendiente.

| Etapa | Estado |
| --- | --- |
| 1. Fundamentos: monorepo, PostgreSQL, Docker, salud, manifiesto, CI | Implementada |
| 2. Identidad: OTP, registro/invitaciones, roles, permisos, auditoría, sesiones | Implementada |
| 3. IA: conexiones, catálogo, tarifas, libro de consumo, presupuestos | Implementada (recomendador y laboratorio pasan a la etapa 4) |
| 4. Agentes versionados, chat flotante con herramientas, multimedia, recomendador y laboratorio | Implementada (sin búsqueda web de catálogo ni voz de salida, fuera de alcance) |
| 5–6. RAG interno y operación | Etapa 5 implementada (activación, fuentes, pipeline durable, recuperación híbrida con ACL, citas validadas, laboratorio y API); operación (etapa 6) en curso |
| 7. PostgreSQL externo, retrieval API y PostgREST | Pendiente |
| 8. Instalador, generador, OpenAPI/cliente, skills y E2E | Pendiente (OpenAPI y cliente tipado ya generados) |

## Comandos útiles

```bash
php artisan starter:doctor     # diagnóstico del entorno y servicios
php artisan starter:health     # chequeos de /health/ready
php artisan starter:manifest   # registra versiones en starter.manifest.json
php artisan starter:owner tu@correo.com --name="Tu Nombre"  # propietario
php artisan starter:permissions                              # catálogo y roles de sistema
php artisan starter:ai-sync-prices                           # tarifas de OpenRouter
php artisan starter:ai-maintain                              # interrumpe colgadas y libera reservas
php artisan test               # tests sobre PostgreSQL
pnpm check && pnpm types:check # lint/format y vue-tsc
```

## Documentación

- [Arquitectura](docs/architecture.md)
- [Instalación](docs/installation.md)
- [Identidad, acceso y auditoría](docs/identity.md)
- [IA: conexiones, catálogo, tarifas, consumo y presupuestos](docs/ai.md)
- [Docker](docs/docker.md)
- [Actualizaciones](docs/upgrades.md)
- [AGENTS.md](AGENTS.md) y [llms.txt](llms.txt) para agentes de desarrollo

## Licencia

MIT.
