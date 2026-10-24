# Docker

`docker/app.Dockerfile` construye una sola imagen PHP (target `app`) para la web
(PHP-FPM), la cola y el scheduler, y una imagen Nginx (target `web`) con los assets
compilados. Composer instala dependencias sin `dev`; los assets se compilan con
pnpm en una etapa que también tiene PHP (Wayfinder genera rutas TypeScript).

## Servicios y perfiles

| Servicio | Función | Activación |
| --- | --- | --- |
| web | Nginx, assets y proxy a PHP-FPM (puerto 8080) | Siempre |
| app | Laravel 13 sobre PHP-FPM | Siempre |
| queue | `queue:work database` con colas mail, default, ingest, sync, maintenance | Siempre |
| scheduler | `schedule:work` | Siempre |
| migrate | `migrate --force --isolated` (una ejecución) | `docker compose run --rm migrate` |
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

## Salud

- `/up` y `/health/live`: el proceso responde.
- `/health/ready`: base de datos, caché y storage (obligatorios); cola y scheduler
  (advertencias). pgvector y PostgREST solo se exigen si su feature flag está activo.
- `php artisan starter:health --live` es el healthcheck del contenedor.
