# Instalación — para personas y agentes de IA

> **TL;DR (English)** — Laravel 13 + AI SDK + Vue/Inertia + PostgreSQL starter kit.
> `git clone https://github.com/bluesmilecl/startkit_laravel.git && cd startkit_laravel && ./setup.sh --owner=you@example.com`
> uses Docker when it is available (nothing else to install) or your local PHP 8.3+, Composer,
> Node 22 and PostgreSQL. It is non-interactive and safe to re-run; `--json` prints a summary
> for agents. Sign in with the owner email: the one-time code arrives at Mailpit
> (<http://localhost:8025>) or in `apps/platform/storage/logs/laravel.log`.

## Pídeselo a tu agente de IA

Copia este mensaje en Claude Code, Codex, Cursor u otro agente con terminal:

```text
Instala y configura https://github.com/bluesmilecl/startkit_laravel siguiendo su INSTALL.md.
Propietario: tu@correo.com. Usa Docker si está disponible.
Al terminar dime la URL, dónde llegan los códigos de acceso y qué quedó pendiente.
```

El agente solo necesita esta página: los pasos son deterministas, no hay preguntas
interactivas y cada fallo devuelve un código de salida con la acción a seguir.

## 1. Elige el modo

| | **Docker** (recomendado para probar) | **Local** (para desarrollar) |
| --- | --- | --- |
| Necesitas | Docker Desktop o Docker Engine con Compose v2 | PHP 8.3+ (`pdo_pgsql`, `intl`, `mbstring`, `zip`), Composer 2, Node 22.12+, pnpm 10 (`corepack enable`), PostgreSQL 16+ |
| Incluye | PostgreSQL con pgvector, Mailpit, cola y scheduler | usa tu PostgreSQL; correo a Mailpit si está en `:1025`, si no al log |
| URL | <http://localhost:8080> | <http://localhost:8000> (con `composer dev`) |
| Primera vez | 5–10 min (construye las imágenes) | 2–4 min |

Windows: usa Docker Desktop o WSL2. Sin forzar el modo, `./setup.sh` elige **local** si PHP,
Composer, Node y un PostgreSQL alcanzable están listos; si no, **Docker**.

## 2. Instala con un comando

```bash
git clone https://github.com/bluesmilecl/startkit_laravel.git
cd startkit_laravel
./setup.sh --owner=tu@correo.com            # o --docker / --local para forzar el modo
```

| Opción | Para qué |
| --- | --- |
| `--owner=EMAIL` `--name=NOMBRE` | cuenta propietaria (entra con un código de un solo uso) |
| `--docker` / `--local` | forzar el modo |
| `--db-url=postgresql://usuario:clave@host:5432/starter` | modo local con tu PostgreSQL (la base se crea si el usuario puede) |
| `--config=starter.config.json` | aplica conexiones IA, agente, RAG, idioma y propietario ([ejemplo](starter.config.example.json)) |
| `--rag` | activa el módulo de conocimiento (requiere pgvector) |
| `--url=URL` | URL pública (`APP_URL`) |
| `--skip-build` | modo local sin compilar el front-end |
| `--json` | resumen JSON en stdout (los registros van a stderr) |

Qué hace, en orden: verifica requisitos → crea `apps/platform/.env` (si no existe; nunca lo
sobrescribe) con `APP_KEY` → instala dependencias desde los locks → migra y siembra (roles,
permisos, catálogo IA y proveedor simulado) → aplica `--config` → crea el propietario →
compila el front-end → ejecuta `starter:doctor`. Volver a ejecutarlo es seguro.

### Códigos de salida

| Código | Significa | Qué hacer |
| --- | --- | --- |
| `0` | listo | entrar a la URL |
| `2` | argumento inválido | revisar `./setup.sh --help` |
| `3` | faltan requisitos | instalar lo indicado o cambiar de modo (`--docker` / `--local`) |
| `4` | PostgreSQL no responde | iniciar PostgreSQL, pasar `--db-url=` o usar `--docker` |
| `5` | falló la instalación de dependencias o la imagen | revisar red (Packagist, npm, Docker Hub) y repetir |
| `6` | falló la base de datos | permisos del usuario de la base; `apps/platform/storage/logs/laravel.log` |
| `7` | la verificación falló | leer la tabla de `starter:doctor`, corregir y repetir |

Con `--json` la última línea es, por ejemplo:

```json
{"status":"ready","mode":"docker","url":"http://localhost:8080","owner":"tu@correo.com","login_codes":"http://localhost:8025 (Mailpit)","start":"docker compose ... up -d","stop":"docker compose ... down"}
```

## 3. Entra

1. Abre la URL y escribe el correo del propietario.
2. Copia el código de 6 dígitos desde **Mailpit** (<http://localhost:8025>) o, si el correo va
   al log, desde el último mensaje de `apps/platform/storage/logs/laravel.log`.
3. Completa el nombre del espacio de trabajo en la incorporación.

Iniciar y detener después: modo local `cd apps/platform && composer dev` (servidor, cola y Vite);
modo Docker, los comandos `start`/`stop` que imprime `setup.sh`.

## 4. Configura (opcional)

| Qué | Cómo |
| --- | --- |
| IA real (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Bedrock, Azure, Groq, Mistral, xAI, Ollama, compatibles) | Panel → IA → Conexiones; o la clave en `apps/platform/.env` (`OPENAI_API_KEY=...`) y una conexión en `starter.config.json` con `"credentialRef": "env:OPENAI_API_KEY"`, luego `./setup.sh --config=starter.config.json`. Sin claves, el proveedor simulado responde (marcado como simulado). |
| Correo real | Panel → Administración → Configuración del sistema → Correo (SMTP o Resend), o `MAIL_*` en `.env` |
| Marca, colores, tipografías e idioma | Panel → Configuración del sistema → Branding / General |
| Registro abierto o por invitación | Panel → Configuración del sistema → Autenticación (`STARTER_REGISTRATION` al inicio) |
| Conocimiento (RAG) | `./setup.sh --rag` y Panel → Conocimiento (ver [docs/knowledge.md](docs/knowledge.md)) |
| Catálogo del kit de UI | `/ui-kit`, activo en local (`STARTER_FEATURE_UI_KIT`) |

Las claves nunca van en `starter.config.json`: solo referencias `env:NOMBRE`. El archivo
`apps/platform/.env` y `docker/.env` están en `.gitignore`.

## 5. Verifica

```bash
php apps/platform/artisan starter:verify     # doctor + config + contratos + migraciones + permisos
# Docker:
docker compose -f docker/compose.yaml -f docker/compose.local.yaml --profile local-db --profile dev-mail exec app php artisan starter:verify
```

Para desarrollar: `cd apps/platform && php artisan test` (PostgreSQL real), `composer lint:check`,
`composer types:check`, y desde la raíz `pnpm check`, `pnpm types:check`, `pnpm test:e2e`.

## Reglas para agentes de IA

- Ejecuta `./setup.sh` sin interacción; si falla, actúa según el **código de salida** y repite.
- No sobrescribas `apps/platform/.env` existente ni imprimas claves o `APP_KEY` en tu respuesta.
- No cambies el stack ni la arquitectura para "hacerlo funcionar": lee [AGENTS.md](AGENTS.md)
  antes de modificar código.
- Para agregar funciones usa el generador (`php artisan starter:make-module`, ver
  [docs/modules.md](docs/modules.md)) y el [kit de UI](docs/ui-kit.md); skills en `skills/`
  ([docs/skills.md](docs/skills.md)).
- Reporta al usuario: URL, dónde llegan los códigos, qué quedó con proveedor simulado y qué
  configuración falta (claves IA, correo real, RAG).

## Problemas frecuentes

| Síntoma | Solución |
| --- | --- |
| `port is already allocated` (8080 u 8025) | libera el puerto o usa `STARTER_HTTP_PORT=8081 MAILPIT_UI_PORT=8026 ./setup.sh --docker` |
| El código no llega | Mailpit en <http://localhost:8025>; en local sin Mailpit, `MAIL_MAILER=log` y el código está en el log |
| `could not find driver` (local) | falta `pdo_pgsql` en PHP |
| `permission denied to create database` | crea la base y el usuario: `CREATE USER starter WITH PASSWORD '...'; CREATE DATABASE starter OWNER starter;` |
| `extension "vector" is not available` | solo con RAG: usa la imagen `pgvector/pgvector` (Docker) o instala pgvector |
| 419 / sesión expirada tras cambiar la URL | ajusta `APP_URL` al host real y vuelve a entrar |
| Cambié `.env` en Docker y no aplica | `docker compose ... up -d --force-recreate app queue scheduler` (la configuración se cachea al iniciar) |

## Proyecto nuevo desde la plantilla

Para empezar **tu** proyecto (historial limpio y configuración aplicada) en lugar de trabajar
sobre este repositorio, usa el instalador desde el clon:

```bash
composer install --working-dir=apps/platform
php tooling/create-starter/bin/laravel-starter new ../mi-proyecto --template=. --config=starter.config.json --no-interaction
```

Detalles, releases verificadas con SHA-256 y códigos de salida en [docs/installer.md](docs/installer.md).
Más detalle de cada modo en [docs/installation.md](docs/installation.md) y [docs/docker.md](docs/docker.md).
