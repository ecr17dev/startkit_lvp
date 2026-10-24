# Laravel Starter — Laravel 13 + IA

Base para comenzar proyectos con autenticación por código, roles y permisos,
panel de administración, inteligencia artificial con **Laravel AI SDK** y RAG
opcional, listos para usar.

| Capa | Tecnología |
| --- | --- |
| Backend | Laravel 13 (PHP 8.3+), Fortify, Sanctum, spatie/laravel-permission |
| IA | `laravel/ai` (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Amazon Bedrock, Azure, Groq, Mistral, xAI, Ollama y APIs compatibles con OpenAI) |
| Frontend | Vue 3 + Inertia v3 + TypeScript estricto, shadcn-vue (reka-ui), Tailwind CSS 4, Vite y un kit de UI con catálogo en vivo (`/ui-kit`) |
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
[`docs/docker.md`](docs/docker.md). Para crear un proyecto nuevo con el instalador
(`laravel-starter new mi-proyecto --config=starter.config.json`), ver
[`docs/installer.md`](docs/installer.md).

## Estructura

```text
apps/platform/         Aplicación Laravel 13 + Inertia + Vue
packages/php/          administration · identity · ai · knowledge · starter-console
packages/js/           @starter/ui · @starter/chat · @starter/api-client
tooling/create-starter/ Instalador laravel-starter (Composer)
tooling/contracts/     OpenAPI y esquemas JSON
skills/                Skills para agentes de desarrollo
tests/e2e/             Pruebas Playwright
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
| 5–6. RAG interno y operación | Implementadas: activación, fuentes, pipeline durable, recuperación híbrida con ACL, citas validadas, laboratorio con juicios, API, panel de conocimiento, conjuntos de evaluación (recall@k, MRR), feedback de respuestas y retención de versiones |
| 7. PostgreSQL externo, retrieval API y PostgREST | Implementada: conexiones PostgreSQL externas (TLS, esquema dedicado, solo lectura), API de recuperación con contrato v1 y servidor de referencia, PostgREST opcional con esquema `api` filtrado por JWT. El proceso PostgREST real no se ejecutó en este entorno (ver docs/postgrest.md) |
| 8. Instalador, generador, OpenAPI/cliente, skills y E2E | Implementada: comandos `starter:*` (config, contratos, verify, modelos, tarifas, RAG), generador `starter:make-module` con el módulo Proyectos, instalador `laravel-starter` con plantilla verificada y releases, referencia de API generada, skills y Playwright E2E en CI. `composer install` del instalador y Firefox/WebKit se verifican en CI (no en este entorno) |
| Kit de UI | Implementado: primitivas shadcn-vue ampliadas (stepper, carrusel, calendario, combobox, comando, drawer, campos, etc.), compuestos reutilizables (formularios, wizard con errores del servidor, `DataTable` con modo tarjetas y modo servidor, gráficos accesibles, KPI, modales responsivos, menús, alertas, toasts, íconos por nombre), catálogo `/ui-kit` y generador de módulos usando la tabla del kit |

## Comandos útiles

```bash
php artisan starter:doctor            # diagnóstico del entorno y servicios
php artisan starter:verify            # doctor + config + contratos + migraciones + permisos
php artisan starter:config-validate   # valida starter.config.json (esquema, secretos, reglas)
php artisan starter:config-apply      # aplica la configuración (ajustes, IA, agente, RAG, propietario)
php artisan starter:owner tu@correo.com --name="Tu Nombre"  # propietario
php artisan starter:permissions       # catálogo y roles de sistema
php artisan starter:make-module Task --field="title:string" --ai   # módulo completo
php artisan starter:api-docs          # OpenAPI + docs/api-reference.md (luego pnpm api:client:generate)
php artisan starter:contracts-check   # contratos al día con el código
php artisan starter:ai-models-sync    # modelos de las conexiones IA
php artisan starter:ai-prices-sync    # tarifas de OpenRouter
php artisan starter:rag-doctor        # diagnóstico del conocimiento
php artisan starter:rag-reindex handbook --as=tu@correo.com  # reindexación versionada
php artisan test                      # tests sobre PostgreSQL
pnpm check && pnpm types:check        # lint/format y vue-tsc
pnpm test:e2e                         # Playwright
```

## Documentación

- [Arquitectura](docs/architecture.md)
- [Instalación](docs/installation.md)
- [Identidad, acceso y auditoría](docs/identity.md)
- [IA: conexiones, catálogo, tarifas, consumo y presupuestos](docs/ai.md)
- [Conocimiento (RAG)](docs/knowledge.md), [externo](docs/rag-external.md) y [PostgREST](docs/postgrest.md)
- [Módulos y generador](docs/modules.md) · [Instalador](docs/installer.md) · [Referencia de la API](docs/api-reference.md)
- [Kit de UI](docs/ui-kit.md): formularios, wizard, tablas con modo tarjetas, gráficos, carruseles, menús, alertas, toasts, modales e íconos
- [Pruebas E2E](docs/e2e.md) · [Skills](docs/skills.md)
- [Docker](docs/docker.md)
- [Actualizaciones](docs/upgrades.md)
- [AGENTS.md](AGENTS.md) y [llms.txt](llms.txt) para agentes de desarrollo

## Licencia

MIT.
