# Docker

`docker/app.Dockerfile` construye una sola imagen PHP (target `app`) para la web
(PHP-FPM), la cola y el scheduler, y una imagen Nginx (target `web`) con los assets
compilados. Composer instala dependencias sin `dev`; los assets se compilan con
pnpm en una etapa que también tiene PHP (Wayfinder genera rutas TypeScript).

## Probar en una máquina

`./setup.sh --docker` construye las imágenes y levanta todo con
`compose.yaml` + `compose.local.yaml` (código de la imagen, `APP_ENV=local`, correo a Mailpit,
PostgreSQL interno sin puerto publicado). Ver [INSTALL.md](../INSTALL.md).

| Archivo | Uso |
| --- | --- |
| `compose.yaml` | servicios base y perfiles |
| `compose.local.yaml` | probar o usar en una máquina sin PHP/Node en el host |
| `compose.dev.yaml` | desarrollo con el código montado (necesita `vendor/` y assets en el host) |
| `compose.production.yaml` | producción |

## Servicios y perfiles

| Servicio | Función | Activación |
| --- | --- | --- |
| web | Nginx, assets y proxy a PHP-FPM (puerto 8080) | Siempre |
| app | Laravel 13 sobre PHP-FPM | Siempre |
| queue | `queue:work database` con colas mail, default, ingest, sync, maintenance | Siempre |
| scheduler | `schedule:work` | Siempre |
| migrate | tablas de caché, `migrate --force --isolated` y `starter:permissions` (una ejecución) | `docker compose run --rm migrate` |
| postgres | PostgreSQL + pgvector (datos, sesiones, caché, cola) | `--profile local-db` |
| rag-postgres | Base vectorial separada | `--profile rag-db` |
| postgrest | API de esquema `api` permitido | `--profile postgrest` |
| mailpit | Correo capturado (UI :8025) | `--profile dev-mail` |
| ssr | Inertia SSR (Node) | `--profile inertia-ssr` |

Los perfiles seleccionan infraestructura; los flags `STARTER_FEATURE_*` activan
capacidades. Ningún servicio obligatorio depende de uno opcional: todas las
combinaciones se validan con `docker compose config` en CI.

## Producción

```bash
docker compose -f docker/compose.yaml -f docker/compose.production.yaml run --rm migrate
docker compose -f docker/compose.yaml -f docker/compose.production.yaml up -d web app queue scheduler
```

- Usa un PostgreSQL externo (`DB_HOST`, `DB_SSLMODE=require`) o añade `--profile local-db`.
- Fija versiones y digests de imágenes (`PGVECTOR_TAG`, `POSTGREST_TAG`, `MAILPIT_TAG`,
  `STARTER_TAG`); no uses `latest`.
- Los procesos corren como `www-data`. `APP_KEY` se entrega en runtime y debe
  persistir: rotarla requiere re-cifrar settings, credenciales IA y sesiones.
- `config:cache`/`route:cache` se ejecutan en el arranque del contenedor con las
  variables definitivas (nunca en el build).
- Nginx no hace buffering de FastCGI para que el chat pueda transmitir eventos SSE;
  los timeouts (300 s) están alineados con PHP-FPM.
- PostgreSQL, Mailpit y PostgREST no se publican en producción.
- Respalda la base de datos, el volumen `storage` y la configuración (incluida
  `APP_KEY`), y prueba la restauración periódicamente.
- Detrás de un proxy (Traefik, Nginx, Cloudflare) define `TRUSTED_PROXIES` (`*` si solo el
  proxy llega a los contenedores, o sus IPs/CIDRs) para que Laravel vea HTTPS y la IP real.

## Dokploy (o cualquier host con un solo archivo Compose)

`docker/compose.dokploy.yaml` es autocontenido: web, app, cola, scheduler, migraciones y
PostgreSQL con pgvector, sin puertos publicados y con `TRUSTED_PROXIES=*`.

1. Crea un servicio **Compose** desde el repositorio (proveedor GitHub), rama a desplegar y
   *Compose Path* `./docker/compose.dokploy.yaml`. Activa **Isolated Deployment** para que los
   nombres `app` y `postgres` no choquen con otros proyectos de la red de Dokploy.
2. En **Environment** (Dokploy lo escribe en `docker/.env`):

   ```dotenv
   APP_NAME="Mi App"
   APP_KEY=base64:...                 # openssl rand -base64 32 (con el prefijo base64:)
   APP_URL=https://mi-app.example.com
   DB_PASSWORD=...                    # aleatoria; crea la base en el primer arranque
   STARTER_IMAGE=mi-app               # nombre de imagen único por proyecto en el servidor
   STARTER_OWNER_EMAIL=tu@correo.com  # propietario creado o mantenido en cada despliegue
   MAIL_MAILER=log                    # o resend + RESEND_API_KEY y MAIL_FROM_ADDRESS
   ```

3. **Domains**: servicio `web`, puerto `8080`, HTTPS con Let's Encrypt (el registro DNS debe
   apuntar al servidor; con Cloudflare, proxy activado o no).
4. **Deploy**. Cada despliegue migra, sincroniza permisos, siembra (idempotente) y asegura el
   propietario. Con `MAIL_MAILER=log` el código de acceso aparece en los logs del servicio `app`;
   después configura Resend desde el panel (Configuración del sistema → Servidor de correo).

## Salud

- `/up` y `/health/live`: el proceso responde.
- `/health/ready`: base de datos, caché y storage (obligatorios); cola y scheduler
  (advertencias). pgvector y PostgREST solo se exigen si su feature flag está activo.
- `php artisan starter:health --live` es el healthcheck del contenedor.
