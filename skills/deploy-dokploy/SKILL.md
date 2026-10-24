---
name: deploy-dokploy
description: Despliega el Laravel Starter (o un proyecto creado desde él) en un servidor Dokploy con el MCP de Dokploy o su panel - servicio Compose desde GitHub con docker/compose.dokploy.yaml, entorno con secretos generados, dominio con HTTPS (DNS en Cloudflare), despliegue, verificación, acceso del propietario y correo con Resend. Úsala para publicar un piloto (dev) o producción, redesplegar o diagnosticar un despliegue.
---

# deploy-dokploy

Despliegue estándar: **un servicio Compose de Dokploy** que construye la imagen desde GitHub con
`./docker/compose.dokploy.yaml` (web, app, cola, scheduler, migraciones y PostgreSQL con
pgvector). Cada despliegue migra, sincroniza permisos, siembra (idempotente) y asegura el
propietario. Guía completa: [docs/dokploy.md](../../docs/dokploy.md).

## Entradas

| Entrada | Obligatoria | Ejemplo |
| --- | --- | --- |
| Entorno | sí | piloto/dev o producción |
| Dominio | sí | `app.midominio.cl` (o el `sslip.io` que genera Dokploy, solo HTTP) |
| Correo del propietario | sí | el que te indique el usuario; pregúntalo si no lo dio |
| Repositorio y rama | sí | `owner/repo`, rama del piloto o `main` |
| `RESEND_API_KEY` | recomendada | la entrega el usuario; sin ella, `MAIL_MAILER=log` |

## Pasos con el MCP de Dokploy

1. **Descubre y sigue el patrón existente** (no inventes IDs):
   - `project-all`: elige el proyecto del entorno (por ejemplo «Develop» para pilotos y
     «Producción» para producción) y su `environmentId`.
   - `compose-one` de un servicio vecino del mismo proyecto: copia su `serverId`, su `githubId`
     (la GitHub App con acceso al dueño del repo) y su patrón de dominio.
   - `github-getGithubBranches` con ese `githubId`: confirma que ve el repositorio y la rama.
2. **Crea el servicio**: `compose-create` (`environmentId`, `serverId`, `sourceType: github`,
   `composeType: docker-compose`, nombre `<proyecto>-dev` o `<proyecto>`). Guarda el `appName`
   que devuelve (lleva un sufijo).
3. **Configúralo** con `compose-update`: `githubId`, `owner`, `repository`, `branch`,
   `composePath: ./docker/compose.dokploy.yaml`, `isolatedDeployment: true` (evita que `app`
   y `postgres` choquen con otros proyectos), `createEnvFile: true`, `autoDeploy: true` y `env`:

   ```dotenv
   APP_NAME="Mi App"
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:<32 bytes aleatorios en base64>
   APP_URL=https://app.midominio.cl
   APP_LOCALE=es
   DB_DATABASE=starter
   DB_USERNAME=starter
   DB_PASSWORD=<hex aleatorio de 48 caracteres>
   STARTER_IMAGE=<appName>
   STARTER_OWNER_EMAIL=<correo del propietario>
   MAIL_MAILER=log
   MAIL_FROM_ADDRESS=no-reply@midominio.cl
   MAIL_FROM_NAME="Mi App"
   STARTER_AI_SIMULATED=true
   ```

   Genera `APP_KEY` y `DB_PASSWORD` en un archivo temporal con `umask 077` (`head -c 32
   /dev/urandom | base64`), pásalos al `env` y borra el archivo. Nunca los muestres en tu
   respuesta. `APP_KEY` no se rota: cifra settings, claves IA y sesiones.
4. **DNS**: `dnsProvider-all` → `dnsProvider-listZones` → `dnsProvider-listRecords` de la zona
   para ver cómo apuntan los otros subdominios al servidor. El registro es `A <sub> → IP del
   servidor` (con o sin proxy de Cloudflare, igual que sus vecinos). Si no puedes crearlo
   (`dnsProvider-createRecord` no disponible), pídeselo al usuario con el nombre, tipo e IP
   exactos. Mientras tanto, `domain-generateDomain` (`appName`, `serverId`) da un
   `*.sslip.io` que funciona al instante por HTTP.
5. **Dominios**: `domain-create` con `composeId`, `domainType: compose`, `serviceName: web`,
   `port: 8080`, `path: /`; el definitivo con `https: true` y `certificateType: letsencrypt`, el
   de sslip.io con `https: false` y `certificateType: none`.
6. **Despliega**: `compose-deploy`. Sigue `deployment-allByCompose` (estado) y
   `deployment-readLogs` con `tail` corto (el build es muy verboso). El primer build compila
   extensiones de PHP, instala Composer y pnpm y compila Vite (5–15 min); los siguientes usan la
   caché (1–2 min). Con `autoDeploy`, cada push a la rama redespliega: **no hagas push ni
   redespliegues mientras otro despliegue del mismo servicio está en curso**, porque Dokploy los
   ejecuta en paralelo.
7. **Verifica**: `curl -fsS https://<dominio>/up` (o el de sslip.io) responde 200 y
   `/login` carga.
8. **Acceso**: con `MAIL_MAILER=log`, pide un código en `/login` con el correo del propietario y
   léelo en los logs del servicio `app`: `docker-getContainersByAppNameMatch` (`appName`,
   `appType: docker-compose`, `serverId`) da el `containerId` y `compose-readLogs` con
   `since: 5m` muestra el correo (el código va en la línea `# 123456`; el filtro `search` no
   acepta `@`). Con Resend, llega al correo. Deja la incorporación al usuario.
9. **Correo real (Resend)**: si el dominio del remitente ya está verificado en Resend (registros
   `resend._domainkey` y `send` en la zona), agrega `RESEND_API_KEY` y `MAIL_MAILER=resend` al
   `env` y redespliega, o configúralo en el panel (Configuración del sistema → Servidor de
   correo), que lo guarda cifrado.
10. **Producción**: respaldo diario de la base con un backup de Dokploy tipo compose (servicio
    `postgres`, base `starter`) hacia un destino S3, y fija `STARTER_TAG` o despliega desde
    etiquetas.

## Archivos

- `docker/compose.dokploy.yaml` (servicios, `env_file: .env`, `TRUSTED_PROXIES=*`, sin puertos).
- `docker/app.Dockerfile` (targets `app` y `web`), `docker/nginx.conf`, `docker/entrypoint.sh`.
- `docs/dokploy.md` (guía con el panel y el MCP), `docs/docker.md`, `docs/mail.md`.

## Comprobaciones

- El despliegue termina `done` y `curl https://<dominio>/up` responde 200.
- La pantalla de login carga por HTTPS y los redirects mantienen `https://` (`TRUSTED_PROXIES`).
- El propietario entra con el código; `Configuración del sistema → Servidor de correo` muestra
  el proveedor esperado.
- No quedan secretos en el repositorio, en la respuesta ni en archivos temporales.

## Errores frecuentes

| Síntoma | Causa y acción |
| --- | --- |
| `Set DB_PASSWORD in the environment` | falta `DB_PASSWORD` en el `env` del servicio |
| `APP_KEY is not set` en `app`/`migrate` | falta `APP_KEY` o no tiene el prefijo `base64:` |
| 404 de Traefik en el dominio | el dominio no apunta a `web:8080`, o el DNS no apunta al servidor del servicio |
| Certificado inválido | el DNS aún no existe o no apunta al servidor; Traefik reintenta Let's Encrypt solo |
| 502 tras un redespliegue | `web` apuntaba a la IP vieja de `app`: `nginx.conf` ahora resuelve `app` en cada solicitud (DNS de Docker); si ves `connect() failed ... upstream` en los logs de `web` con una imagen antigua, vuelve a desplegar |
| 502 con `upstream sent too big header` | los buffers FastCGI ya están ampliados en `nginx.conf`; revisa que la imagen `web` esté al día |
| Redirecciones a `http://` | `TRUSTED_PROXIES` vacío: el compose de Dokploy lo deja en `*` |
| Otro proyecto responde en `app` | falta `isolatedDeployment: true` |
| `password authentication failed` tras cambiar `DB_PASSWORD` | PostgreSQL fija la clave al crear el volumen: vuelve a la original o cámbiala dentro de la base |
| `The container name ... is already in use` | dos despliegues en paralelo (un push durante el primero): el que terminó bien dejó el stack arriba; revisa `docker-getContainersByAppNameMatch` y vuelve a desplegar si hace falta |
