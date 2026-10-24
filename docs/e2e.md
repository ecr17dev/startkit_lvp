# Pruebas de punta a punta (Playwright)

Las pruebas E2E recorren la aplicación real: Laravel + PostgreSQL (pgvector) + worker de
colas, con el proveedor IA **simulado** (sin red ni costos). Viven en `tests/e2e` y se
configuran en `playwright.config.ts`.

```bash
pnpm test:e2e          # Chromium y el proyecto móvil (Pixel 7)
pnpm test:e2e:ui       # modo interactivo
E2E_ALL_BROWSERS=1 pnpm test:e2e   # además Firefox y WebKit (recorridos críticos)
```

Sin `E2E_BASE_URL`, Playwright levanta `php artisan serve` en `127.0.0.1:8000` (o reutiliza
uno en marcha fuera de CI). La aplicación necesita la base migrada y sembrada, assets
compilados y, para la ingesta de conocimiento, un worker (`php artisan queue:work`).

## Datos y sesiones

- `global-setup.ts` crea un **propietario nuevo por ejecución** (`starter:owner`), inicia
  sesión con código y guarda la sesión en `tests/e2e/.auth/owner.json` (ignorado por git).
- Cada prueba usa nombres y correos únicos (`unique()`, `account()`): no se reinicia la base
  compartida ni dependen unas de otras.
- Los códigos de un solo uso se leen de **Mailpit** (`E2E_MAILPIT_URL=http://127.0.0.1:8025`)
  o del log (`MAIL_MAILER=log`), aceptando solo correos posteriores a la solicitud.
- No hay esperas arbitrarias: se espera la navegación, la respuesta de red o el estado de la
  interfaz.
- Para varias corridas seguidas, sube los límites de códigos del entorno de prueba
  (`STARTER_OTP_REQUESTS_PER_MINUTE`, `STARTER_OTP_REQUESTS_PER_HOUR`,
  `STARTER_OTP_RESEND_COOLDOWN=0`); en producción conserva los valores por defecto.

## Recorridos

| Archivo | Verifica |
| --- | --- |
| `auth.spec.ts` | acceso con código, código incorrecto y luego correcto (envío con Enter), redirección de páginas protegidas |
| `admin.spec.ts` | crear usuario con rol y encontrarlo, crear rol con permisos, auditoría |
| `chat.spec.ts` | chat flotante: herramienta sensible → propuesta → confirmación → cambio aplicado (usuario desactivado) |
| `ai.spec.ts` | conexiones, catálogo, agentes y costos |
| `rag.spec.ts` | laboratorio de recuperación sobre la colección sembrada (se omite si RAG está apagado) |
| `postgrest.spec.ts` | PostgREST apagado por defecto y sin prueba disponible |
| `contracts.spec.ts` | el OpenAPI servido coincide con `tooling/contracts/openapi.json`; el visor lo incluye |
| `projects.spec.ts` | módulo generado Proyectos: crear, buscar, editar y eliminar |
| `mobile.spec.ts` | menú lateral en teléfono (se cierra al navegar), tema oscuro persistente, formulario de acceso solo con teclado |

## CI

`.github/workflows/e2e.yml` corre Chromium (y el proyecto móvil) en cada push y pull request,
y Firefox/WebKit cada semana, a demanda y en etiquetas de release. Prepara `.env` (IA
simulada, RAG interno, códigos en el log, límites altos), migra y siembra, procesa la
ingesta inicial, compila, instala navegadores, arranca un worker y ejecuta las pruebas con un
reintento acotado. Si algo falla, guarda el reporte HTML, trazas, capturas, videos y el log
del worker durante 7 días (solo valores de prueba, sin secretos).

`ci.yml` además ejecuta `php artisan starter:verify` y el job **installer**, que crea un
proyecto real con `laravel-starter new` (instalación con locks, migraciones,
`starter:config-apply` y diagnóstico) sobre PostgreSQL.

El micrófono real no se prueba con audio de archivo: la transcripción se cubre en las pruebas
PHP con un proveedor simulado.

## Formato

Las pruebas siguen el estilo del frontend (comillas simples, 4 espacios):

```bash
cd apps/platform && node_modules/.bin/vp fmt "$(realpath ../../tests/e2e)" "$(realpath ../../playwright.config.ts)"
```
