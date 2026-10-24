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

## Opción A: Docker

```bash
cp apps/platform/.env.example apps/platform/.env
# Genera APP_KEY sin instalar PHP en el host:
docker compose -f docker/compose.yaml run --rm --no-deps app php artisan key:generate --show
# Copia la clave en APP_KEY y levanta el entorno local con PostgreSQL + Mailpit:
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --profile local-db --profile dev-mail up -d
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml run --rm migrate
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec app php artisan db:seed --force
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec app php artisan starter:owner tu@correo.com
```

- Aplicación: <http://localhost:8080>
- Mailpit (correos capturados, incluidos los códigos OTP): <http://localhost:8025>

## Opción B: local

```bash
corepack enable
pnpm install
cd apps/platform
cp .env.example .env            # ajusta DB_* a tu PostgreSQL
composer install
php artisan key:generate
php artisan migrate --seed
php artisan starter:owner tu@correo.com --name="Tu Nombre"
cd ../.. && pnpm build
pnpm dev                        # Vite
cd apps/platform && composer dev  # servidor, cola y logs
```

Sin un servidor SMTP, usa Mailpit (`docker compose ... --profile dev-mail up -d mailpit`)
o `MAIL_MAILER=log` (los códigos quedan en `storage/logs/laravel.log`).

## Verificación

```bash
cd apps/platform
php artisan starter:doctor   # entorno, extensiones, base de datos, pgvector, flags y salud
php artisan starter:health   # chequeos de /health/ready
php artisan test
```
