---
name: install-starter
description: Instala y configura el Laravel Starter (Laravel 13 + Laravel AI SDK + Vue/Inertia + PostgreSQL/pgvector) con ./setup.sh (Docker o local, un comando) o crea un proyecto nuevo con laravel-starter new desde una release verificada o una copia local, con starter.config.json y diagnóstico. Úsala para instalar, reinstalar o diagnosticar.
---

# install-starter

**Camino corto (clon del repositorio):** `./setup.sh --owner=correo` instala todo y verifica
(Docker si está disponible; si no, local). Ver [INSTALL.md](../../INSTALL.md): opciones,
códigos de salida 0/2–7 y problemas frecuentes. Usa `--json` y actúa según el código.

**Proyecto propio desde la plantilla:** `laravel-starter new` (resto de esta skill). No inventa
credenciales: solo usa referencias `env:NOMBRE` o `file:/ruta`.

## Entradas

| Entrada | Obligatoria | Ejemplo |
| --- | --- | --- |
| Directorio del proyecto | sí | `mi-proyecto` |
| `starter.config.json` | recomendada | copia de `starter.config.example.json` |
| Origen de la plantilla | sí | `--release=0.1.0`, `--archive=… --sha256=…` o `--template=/ruta/al/monorepo` |
| Variables con secretos | según config | `DB_URL`, `OPENAI_API_KEY`, `STARTER_AI_SECRET_*` en el entorno |
| Permiso para Docker | opcional | solo si el usuario lo autoriza: `--start-services` |

## Prerrequisitos

- PHP ≥ 8.3 con `pdo_pgsql`, `intl`, `mbstring`, `zip`, `phar`; Composer 2.
- Node ≥ 22.12 y pnpm 10 (`corepack enable`) salvo `--skip-install`.
- PostgreSQL 16/17 (pgvector si se activa RAG) o Docker con permiso explícito.
- El CLI (`docs/installer.md`): no está en Packagist; se instala desde el artefacto de la
  release verificado con `sha256sum -c`, o se usa `php tooling/create-starter/bin/laravel-starter`.

## Pasos

1. Validar la configuración sin crear nada:
   `laravel-starter validate starter.config.json --json` (código 3 = inválida; corrige los
   punteros JSON que informa). Nunca pongas claves en el archivo.
2. Ver el plan: `laravel-starter new mi-proyecto --config=starter.config.json --dry-run --json`.
3. Exportar en el entorno las variables que referencia la configuración (sin imprimirlas).
4. Crear: `laravel-starter new mi-proyecto --config=starter.config.json --no-interaction --json`
   (+ `--start-services` solo con autorización del usuario).
5. Si la base no estaba disponible (migración omitida): levantarla y ejecutar en
   `mi-proyecto/apps/platform`: `php artisan migrate --seed`, `php artisan starter:config-apply`.
6. Verificar: `php artisan starter:doctor` y `php artisan starter:verify`.
7. Arrancar: `composer dev` (servidor, cola y logs). Acceso con código enviado al propietario
   (Mailpit en `http://localhost:8025` o el log con `MAIL_MAILER=log`).

## Archivos

- `apps/platform/.env` (permisos 0600, secretos locales), `docker/.env` (interpolación de Compose).
- `starter.config.json` (sin secretos), `starter.manifest.json` (versiones validadas).
- `docs/installer.md`, `docs/installation.md`, `docs/docker.md`.

## Comprobaciones

- Salida JSON del instalador con `"ok": true` y cada paso `ok` o `skipped` con motivo.
- `php artisan starter:verify` sin pasos `failed` (avisos esperables: proveedor simulado en local,
  propietario pendiente).
- `curl -s -o /dev/null -w "%{http_code}" localhost:8000/health/ready` responde `200`.

## Errores frecuentes

| Código / síntoma | Causa y acción |
| --- | --- |
| 3 | configuración inválida: corrige los errores por puntero (p. ej. `/rag/embeddingProfileRef`) |
| 4 | falta una herramienta o una referencia obligatoria (base externa): instala o exporta la variable |
| 5 | el directorio existe: elige otro o usa `--force` solo si el usuario lo pide |
| 6 | checksum distinto o plantilla no disponible: vuelve a descargar; nunca omitas la verificación |
| 7 | falló un paso (`failed_step`): revisa `detail`, corrige y ejecuta los pasos restantes a mano |
| `permission denied to create extension "vector"` | un administrador debe ejecutar `CREATE EXTENSION vector;` y luego `starter:config-apply` |
