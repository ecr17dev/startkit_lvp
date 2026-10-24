# Instalación — para personas y agentes de IA

> **TL;DR (English)** — Laravel 13 + AI SDK + Vue/Inertia + PostgreSQL starter kit.
> `git clone https://github.com/bluesmilecl/startkit_laravel.git && cd startkit_laravel && ./setup.sh --owner=you@example.com`
> uses Docker when it is available (nothing else to install) or your local PHP 8.3+, Composer,
> Node 22 and PostgreSQL. It is non-interactive and safe to re-run; `--json` prints a summary
> for agents. Sign in with the owner email: the one-time code arrives at Mailpit
> (<http://localhost:8025>) or in `apps/platform/storage/logs/laravel.log`. Your own project:
> `git clone --depth 1 <repo> my-app && cd my-app && ./setup.sh --new-project="My App" --owner=you@example.com`.
> Real email with Resend: `RESEND_API_KEY=re_... ./setup.sh --mail-from=hello@your-domain.com`.

## Pídeselo a tu agente de IA

Copia uno de estos mensajes en Claude Code, Codex, Cursor u otro agente con terminal.

**Crear tu proyecto** a partir de la plantilla (copia completa, historial propio):

```text
Crea mi proyecto "Mi App" a partir de https://github.com/bluesmilecl/startkit_laravel
siguiendo su INSTALL.md (sección "Tu proyecto desde la plantilla"). Propietario: tu@correo.com.
Usa Docker si está disponible. Si te doy RESEND_API_KEY, deja el correo con Resend.
Al terminar dime la URL, dónde llegan los códigos de acceso y qué quedó pendiente.
```

**Solo probarlo**:

```text
Instala y configura https://github.com/bluesmilecl/startkit_laravel siguiendo su INSTALL.md.
Propietario: tu@correo.com. Usa Docker si está disponible.
Al terminar dime la URL, dónde llegan los códigos de acceso y qué quedó pendiente.
```

El agente solo necesita esta página: los pasos son deterministas, no hay preguntas
interactivas y cada fallo devuelve un código de salida con la acción a seguir. Al abrir el
proyecto, el agente recibe el contexto solo: `AGENTS.md` (Codex, Cursor, Copilot y otros),
`CLAUDE.md` y las skills de `.claude/skills/` (Claude Code) y `GEMINI.md` (Gemini CLI).

## Tu proyecto desde la plantilla

```bash
git clone --depth 1 https://github.com/bluesmilecl/startkit_laravel.git mi-app
cd mi-app
./setup.sh --new-project="Mi App" --owner=tu@correo.com
# opcional: publicarlo en tu repositorio
git remote add origin git@github.com:tu-usuario/mi-app.git && git push -u origin main
```

`--new-project` hace lo mismo que la instalación normal y además:

- crea un historial nuevo con un solo commit inicial; la plantilla queda como el remoto
  `starter` (para comparar o traer mejoras con `git fetch starter`). Se niega si hay cambios
  sin guardar o commits sin publicar;
- deja `APP_NAME="Mi App"` y, en Docker, un proyecto de Compose propio (`COMPOSE_PROJECT_NAME`),
  así varios proyectos conviven en la misma máquina sin compartir la base de datos (cambia
  también el puerto: `STARTER_HTTP_PORT=8081 MAILPIT_UI_PORT=8026`).

Después: módulos con `php artisan starter:make-module` ([docs/modules.md](docs/modules.md)),
pantallas con el [kit de UI](docs/ui-kit.md) y las reglas de [AGENTS.md](AGENTS.md).

## Publícalo en Dokploy

El despliegue estándar es un servicio Compose de Dokploy desde GitHub con
`docker/compose.dokploy.yaml` (web, app, cola, scheduler, migraciones y PostgreSQL), aislado,
con Auto Deploy y dominio HTTPS. Pídeselo a tu agente con el MCP de Dokploy conectado:

```text
Despliega este proyecto en mi Dokploy siguiendo docs/dokploy.md (skill deploy-dokploy):
entorno de desarrollo, dominio app.midominio.cl, propietario tu@correo.com.
Al terminar dime la URL, qué registro DNS falta y cómo entro.
```

Pasos con el panel, variables de entorno, DNS, correo con Resend y respaldos en
[docs/dokploy.md](docs/dokploy.md).

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
| `--new-project=NOMBRE` | convierte el clon en tu proyecto: `--app-name` + `--fresh-git` (ver arriba) |
| `--app-name=NOMBRE` `--fresh-git` | por separado: nombre de la app (y proyecto de Docker propio) e historial git nuevo |
| `--docker` / `--local` | forzar el modo |
| `--db-url=postgresql://usuario:clave@host:5432/starter` | modo local con tu PostgreSQL (la base se crea si el usuario puede) |
| `--config=starter.config.json` | aplica conexiones IA, agente, RAG, idioma y propietario ([ejemplo](starter.config.example.json)) |
| `--rag` | activa el módulo de conocimiento (requiere pgvector) |
| `RESEND_API_KEY=re_…` (variable) `--mail-from=EMAIL` | correo real con Resend (por defecto cuando hay clave); sin dominio verificado envía desde `onboarding@resend.dev` |
| `--mail=resend\|mailpit\|log` | forzar el correo (sin clave: Mailpit, o el log si no hay Mailpit) |
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
{"status":"ready","mode":"docker","url":"http://localhost:8080","owner":"tu@correo.com","mail":"mailpit","login_codes":"http://localhost:8025 (Mailpit)","start":"docker compose ... up -d","stop":"docker compose ... down"}
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
| Correo real | **Resend** por defecto: `RESEND_API_KEY=re_… ./setup.sh --mail-from=hola@tudominio.com`. También SMTP (servidor propio, Gmail, SES, Brevo, SendGrid, Zoho), Postmark o Mailgun desde Panel → Administración → Configuración del sistema → Servidor de correo, en `.env` o en `starter.config.json` ([docs/mail.md](docs/mail.md)) |
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
- Si te piden un proyecto propio ("crea mi proyecto a partir de…"), clona en una carpeta nueva y
  usa `--new-project="Nombre"`; no publiques ni hagas push sin que te lo pidan.
- Si el usuario te da `RESEND_API_KEY`, pásala como variable de entorno (`RESEND_API_KEY=re_…
  ./setup.sh …`), nunca en `starter.config.json` ni en tu respuesta.
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
| Con Resend el código no llega | sin dominio verificado, `onboarding@resend.dev` solo entrega al correo de tu cuenta de Resend: usa ese correo como propietario o verifica el dominio y pasa `--mail-from`; `starter:doctor` muestra el proveedor y si falta la clave |
| `could not find driver` (local) | falta `pdo_pgsql` en PHP |
| `permission denied to create database` | crea la base y el usuario: `CREATE USER starter WITH PASSWORD '...'; CREATE DATABASE starter OWNER starter;` |
| `extension "vector" is not available` | solo con RAG: usa la imagen `pgvector/pgvector` (Docker) o instala pgvector |
| 419 / sesión expirada tras cambiar la URL | ajusta `APP_URL` al host real y vuelve a entrar |
| Cambié `.env` en Docker y no aplica | `docker compose ... up -d --force-recreate app queue scheduler` (la configuración se cachea al iniciar) |

## Alternativa: instalador `laravel-starter`

`./setup.sh --new-project` es el camino corto. El instalador PHP hace lo mismo hacia otra
carpeta, con plantilla verificada y `starter.config.json` aplicado:

```bash
composer install --working-dir=apps/platform
php tooling/create-starter/bin/laravel-starter new ../mi-proyecto --template=. --config=starter.config.json --no-interaction
```

Detalles, releases verificadas con SHA-256 y códigos de salida en [docs/installer.md](docs/installer.md).
Más detalle de cada modo en [docs/installation.md](docs/installation.md) y [docs/docker.md](docs/docker.md).
