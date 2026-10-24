# Instalación

## Requisitos

| Herramienta | Versión |
| --- | --- |
| PHP | 8.3 o superior (producción: 8.4) con `pdo_pgsql`, `intl`, `mbstring`, `zip`, `fileinfo` |
| Composer | 2.x |
| Node.js | 22 LTS |
| pnpm | 10 (`corepack enable`) |
| PostgreSQL | 16 o 17 (pgvector solo si activas RAG) |
| Docker (opcional) | Docker Engine + Compose v2 |

Las versiones validadas de cada release están en `starter.manifest.json`.

## Un comando (recomendado)

```bash
./setup.sh --owner=tu@correo.com              # Docker si está disponible; si no, local
```

Opciones, códigos de salida y problemas frecuentes en [INSTALL.md](../INSTALL.md). Abajo, lo
que hace cada modo por si prefieres ejecutarlo a mano.

## Opción A: Docker

`./setup.sh --docker` equivale a:

```bash
cp apps/platform/.env.example apps/platform/.env
# APP_KEY sin PHP en el host (el contenedor no arranca sin ella):
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(head -c 32 /dev/urandom | base64)|" apps/platform/.env
C="docker compose -f docker/compose.yaml -f docker/compose.local.yaml --profile local-db --profile dev-mail"
$C build app web
$C up -d --wait postgres mailpit
$C run --rm migrate                                  # tablas de caché, migraciones y permisos
$C run --rm app php artisan db:seed --force
$C run --rm app php artisan starter:owner tu@correo.com --name="Tu Nombre"
$C up -d --wait web app queue scheduler
```

- Aplicación: <http://localhost:8080> (`STARTER_HTTP_PORT`)
- Mailpit (correos capturados, incluidos los códigos): <http://localhost:8025> (`MAILPIT_UI_PORT`)
- `compose.local.yaml` usa el código de la imagen (sin PHP ni Node en el host). Para editar con
  recarga en vivo usa el modo local o `compose.dev.yaml` (monta el código y necesita `vendor/` y
  los assets en el host).

## Opción B: local

`./setup.sh --local [--db-url=postgresql://usuario:clave@host:5432/starter]` equivale a:

```bash
cd apps/platform
cp .env.example .env            # DB_* de tu PostgreSQL; MAIL_MAILER=log si no hay Mailpit en :1025
composer install
cd ../.. && corepack enable && pnpm install --frozen-lockfile && cd apps/platform
php artisan key:generate
createdb starter                # o la crea setup.sh si el usuario tiene permiso
php artisan migrate --seed
php artisan starter:permissions
php artisan starter:owner tu@correo.com --name="Tu Nombre"
cd ../.. && pnpm build
cd apps/platform && composer dev  # servidor, cola, logs y Vite → http://localhost:8000
```

Sin un servidor SMTP, usa Mailpit (`docker compose -f docker/compose.yaml --profile dev-mail up -d mailpit`)
o `MAIL_MAILER=log` (los códigos quedan en `storage/logs/laravel.log`).

## Verificación

```bash
cd apps/platform
php artisan starter:doctor   # entorno, extensiones, base de datos, pgvector, flags y salud
php artisan starter:health   # chequeos de /health/ready
php artisan test
```
