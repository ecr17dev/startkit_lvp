# Instalador `laravel-starter`

El instalador crea un proyecto desde una **plantilla versionada y verificable** (o desde una
copia local del repositorio), aplica `starter.config.json` y deja el entorno listo. El código
vive en [`tooling/create-starter`](../tooling/create-starter) (paquete
`ecortes/laravel-starter-installer`, `Starter\Installer\`).

> **No está publicado en Packagist.** Se instala desde el artefacto de una release de GitHub
> (verificado con su SHA-256) o directamente desde una copia del monorepo.

## Instalar el CLI

```bash
VERSION=0.1.0
BASE=https://github.com/bluesmilecl/startkit_laravel/releases/download/v$VERSION
mkdir -p ~/.laravel-starter && cd ~/.laravel-starter
curl -fLO $BASE/laravel-starter-installer-$VERSION.zip
curl -fLO $BASE/laravel-starter-installer-$VERSION.zip.sha256
sha256sum -c laravel-starter-installer-$VERSION.zip.sha256      # debe decir OK

composer global config repositories.laravel-starter artifact ~/.laravel-starter
composer global require ecortes/laravel-starter-installer:$VERSION
laravel-starter --version
```

Desde el monorepo (desarrollo y CI) basta con `php tooling/create-starter/bin/laravel-starter`
después de `composer install` en `apps/platform`.

## Crear un proyecto

```bash
laravel-starter new mi-proyecto --config=starter.config.json --no-interaction
```

| Opción | Uso |
| --- | --- |
| `--config=` | configuración validada contra `tooling/contracts/starter.config.schema.json` |
| `--release=` | versión de la plantilla (por defecto la del instalador); se descarga con su `.sha256` |
| `--archive=` / `--sha256=` | plantilla local `.tar.gz` y su checksum (o `<archivo>.sha256` al lado) |
| `--template=` | copia local del repositorio (solo archivos versionados; sin `vendor`, `node_modules`, `.env` ni datos) |
| `--url=` | `APP_URL` (por defecto `http://localhost:8000`) |
| `--skip-install` | sin `composer install`/`pnpm install`/`pnpm build` (tampoco migra) |
| `--skip-migrate` | sin migraciones, `starter:config-apply` ni diagnóstico |
| `--start-services` | permite levantar PostgreSQL y Mailpit con Docker Compose (nunca por omisión) |
| `--force` | permite un directorio de destino no vacío |
| `--dry-run` | valida y muestra el plan sin crear nada |
| `--json` | salida JSON (para agentes y scripts) |

Sin `--config`, el modo interactivo pregunta nombre, idioma, propietario, base de datos y RAG;
en modo no interactivo usa valores seguros (IA con proveedor simulado, registro por
invitación, sin RAG ni PostgREST).

`laravel-starter validate starter.config.json` valida un archivo sin crear nada (mismo
validador que `php artisan starter:config-validate`).

### Pasos

1. **Configuración**: esquema JSON, secretos en claro (claves de API, llaves privadas, URLs con
   contraseña) y reglas cruzadas (RAG sin conexión o perfil, referencias a conexiones IA no
   declaradas, base externa sin referencia…).
2. **Secretos**: las referencias `env:NOMBRE` y `file:/ruta` se resuelven en la máquina que
   instala. La base externa es obligatoria; el resto queda como configuración pendiente.
3. **Prerrequisitos**: PHP ≥ 8.3 y extensiones, Composer, Node ≥ 22.12 y pnpm (si se instala),
   Docker solo si la base es Docker y se autoriza `--start-services`.
4. **Destino**: debe no existir o estar vacío.
5. **Plantilla**: copia local o archivo verificado con SHA-256 (las descargas solo aceptan
   `https://`; `file://` para espejos). Un checksum distinto detiene todo.
6. **Entorno**: `apps/platform/.env` (permisos `0600`) con `APP_KEY` nueva, banderas
   `STARTER_FEATURE_*`, método de acceso, base de datos (contraseña generada para Docker o
   `DB_URL` de la referencia), correo (Mailpit, log, SMTP con `MAIL_URL`, Resend, Postmark o Mailgun; ver [mail.md](mail.md)), las
   claves IA referenciadas y secretos de PostgREST si se activa; `docker/.env` con los mismos
   valores que interpola Compose; `starter.config.json` sin secretos.
7. **Instalación**: `composer install` y `pnpm install --frozen-lockfile` respetan los locks;
   `pnpm build`.
8. **Servicios** (solo con `--start-services`): `docker compose ... up -d --wait postgres mailpit`.
9. **Base de datos** (solo si hay base disponible: externa o servicios iniciados):
   `migrate --seed`, `starter:config-apply` (ajustes, conexiones IA, agente, RAG interno,
   propietario) y `starter:doctor`.

### Códigos de salida

| Código | Significado |
| --- | --- |
| 0 | proyecto creado (o plan mostrado con `--dry-run`) |
| 1 | error inesperado |
| 2 | argumentos u opciones inválidos |
| 3 | `starter.config.json` inválido |
| 4 | faltan prerrequisitos o no se resuelven secretos obligatorios |
| 5 | el destino existe y no está vacío |
| 6 | plantilla no disponible o checksum distinto |
| 7 | falló un paso de la instalación (`failed_step` en la salida JSON) |

## `starter:config-apply`

Dentro de un proyecto, `php artisan starter:config-apply [archivo] [--dry-run] [--json]`
aplica la parte **no secreta** de la configuración y es idempotente:

- nombre, idioma, zona horaria, modo de registro y remitente (ajustes del panel);
- conexiones IA: crea o actualiza por `ref`; las claves quedan como `env:` (solo variables
  permitidas: `STARTER_AI_SECRET_*` o claves de proveedor) o cifradas desde `file:`; la
  conexión `connectionRef` queda por defecto; `simulated` instala el proveedor simulado;
- agente por defecto: guarda un borrador con prompt, tokens, multimedia y micrófono, y lo
  publica solo si `modelRef` existe y está habilitado (si no, queda pendiente);
- RAG `internal`: diagnóstico y activación (intenta `CREATE EXTENSION vector`; sin privilegios
  informa el SQL para el administrador); los modos externos se configuran en el panel;
- propietario: lo crea o asegura su rol (`starter:owner`);
- compara banderas de entorno y método de acceso con `.env` y avisa de las diferencias.

## Releases verificables

Al publicar una etiqueta `vX.Y.Z`, `.github/workflows/release.yml` comprueba que las versiones
coinciden (instalador, `Application::VERSION`, `starter.manifest.json`), genera
`laravel-starter-template-X.Y.Z.tar.gz` (`git archive` de la etiqueta) y
`laravel-starter-installer-X.Y.Z.zip`, publica sus `.sha256`, prueba el instalador contra el
archivo generado y crea la release.

## Qué se verificó

`tests/Feature/Installer/InstallerTest.php` cubre códigos de salida, plan en seco, copia local,
`.env` con secretos locales (y que no se impriman ni exporten), archivo con checksum correcto,
incorrecto o ausente, descarga `file://` con `.sha256` y el orden de los pasos con un ejecutor
simulado. `tests/Feature/Console/ConfigApplyTest.php` cubre la aplicación en PostgreSQL real.
En este entorno se instaló un proyecto real desde la copia local y desde un `git archive` con
base externa; `composer install` no se ejecutó aquí (las descargas de GitHub requieren token):
lo cubre el job `installer` de CI.
