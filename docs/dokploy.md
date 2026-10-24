# Despliegue en Dokploy

Dokploy es el destino estándar de los proyectos creados con este starter: un **servicio Compose**
por proyecto y entorno, construido desde GitHub con
[`docker/compose.dokploy.yaml`](../docker/compose.dokploy.yaml). Esta guía sirve para el panel
y para agentes de IA con el MCP de Dokploy (skill
[`deploy-dokploy`](../skills/deploy-dokploy/SKILL.md)).

## Qué se despliega

| Servicio | Qué hace |
| --- | --- |
| `web` | Nginx con los assets compilados; recibe el dominio (puerto `8080`) |
| `app` | PHP-FPM (Laravel) |
| `queue` | `queue:work` sobre PostgreSQL (correo, ingesta, sincronización, mantenimiento) |
| `scheduler` | `schedule:work` (tareas programadas de Laravel) |
| `migrate` | en cada despliegue: migraciones, permisos, seeders idempotentes y propietario |
| `postgres` | PostgreSQL 17 con pgvector y volumen persistente |

Nada publica puertos: Traefik (de Dokploy) enruta el dominio a `web:8080`. Las imágenes se
construyen en el servidor (Composer, pnpm y Vite dentro del build); el primer despliegue tarda
5–15 minutos y los siguientes usan la caché.

## Convenciones

- **Un proyecto de Dokploy por entorno**: pilotos y desarrollo en el proyecto de desarrollo
  (p. ej. «Develop», servicio `<proyecto>-dev`), producción en el de producción.
- **Mismo servidor, GitHub App y patrón de dominio que los servicios vecinos** del proyecto.
- **Despliegue aislado** (`Isolated Deployment`): los nombres `app` y `postgres` no chocan con
  otros proyectos de la red de Dokploy.
- **Auto Deploy** desde la rama del entorno: cada push redespliega.
- **Secretos** solo en el Environment del servicio; `APP_KEY` se genera una vez y no se rota.
- **Correo** con Resend y un dominio verificado; `MAIL_MAILER=log` solo mientras tanto.

## Con el panel

1. **Create Service → Compose** en el proyecto y entorno elegidos, en el servidor de los
   servicios vecinos.
2. **General**: proveedor GitHub, repositorio, rama, *Compose Path*
   `./docker/compose.dokploy.yaml`; activa **Isolated Deployment** y **Auto Deploy**.
3. **Environment**:

   ```dotenv
   APP_NAME="Mi App"
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=base64:...                 # head -c 32 /dev/urandom | base64 (con el prefijo base64:)
   APP_URL=https://app.midominio.cl
   APP_LOCALE=es
   DB_DATABASE=starter
   DB_USERNAME=starter
   DB_PASSWORD=...                    # aleatoria; PostgreSQL la fija al crear el volumen
   STARTER_IMAGE=mi-app-dev           # nombre de imagen único por proyecto en el servidor
   STARTER_OWNER_EMAIL=tu@correo.com  # propietario creado o mantenido en cada despliegue
   MAIL_MAILER=log                    # luego resend + RESEND_API_KEY
   MAIL_FROM_ADDRESS=no-reply@midominio.cl
   MAIL_FROM_NAME="Mi App"
   STARTER_AI_SIMULATED=true          # IA simulada hasta cargar claves reales
   # Solo pilotos (APP_ENV=staging): estas cuentas también entran con el PIN fijo
   # STARTER_OTP_FIXED_CODE=111111
   # STARTER_OTP_FIXED_EMAILS=tu@correo.com
   ```

   El PIN fijo se ignora con `APP_ENV=production`, cada uso queda en la auditoría
   (`auth.fixed_code_used`) y `starter:doctor` avisa mientras esté definido.

4. **DNS**: registro `A` del subdominio a la IP del servidor del servicio (en Cloudflare, con o
   sin proxy, como los subdominios vecinos). Sin DNS todavía, **Domains → Generate** crea un
   `*.sslip.io` que responde de inmediato por HTTP.
5. **Domains**: servicio `web`, puerto `8080`, ruta `/`, HTTPS con Let's Encrypt.
6. **Deploy** y revisa **Deployments**. Comprueba `https://app.midominio.cl/up` (200).
7. **Entra**: pide el código en `/login` con el correo del propietario. Con `MAIL_MAILER=log`
   aparece en **Logs → app**; con Resend, en el correo.

## Con el MCP de Dokploy (agentes)

| Paso | Herramientas |
| --- | --- |
| Elegir proyecto, entorno, servidor y GitHub App | `project-all`, `compose-one` de un servicio vecino, `github-getGithubBranches` |
| Crear y configurar | `compose-create` (`environmentId`, `serverId`, `sourceType: github`) y `compose-update` (`githubId`, `owner`, `repository`, `branch`, `composePath`, `isolatedDeployment`, `autoDeploy`, `env`) |
| DNS | `dnsProvider-all`, `dnsProvider-listZones`, `dnsProvider-listRecords`; si no hay herramienta para crear el registro, se le pide al usuario |
| Dominios | `domain-generateDomain` (sslip.io), `domain-create` (`serviceName: web`, `port: 8080`) |
| Desplegar y seguir | `compose-deploy`, `deployment-allByCompose`, `deployment-readLogs` (con `tail` corto: el build es muy verboso). No empujes cambios mientras corre un despliegue: Dokploy ejecuta los dos en paralelo y uno falla por nombres de contenedor repetidos |
| Diagnosticar y leer el código del propietario | `docker-getContainersByAppNameMatch` (IDs de contenedor) y `compose-readLogs` de `app`, `migrate` o `web` |

Los secretos (`APP_KEY`, `DB_PASSWORD`) se generan en un archivo temporal privado, se pasan al
`env` y el archivo se borra; nunca aparecen en la respuesta ni en el repositorio.

## Correo con Resend

Si la zona del dominio ya tiene los registros de Resend (`resend._domainkey` y `send`), el
remitente `no-reply@<dominio>` está listo: agrega `RESEND_API_KEY` y `MAIL_MAILER=resend` al
Environment y redespliega, o configúralo en el panel de la aplicación (Configuración del
sistema → Servidor de correo), que guarda la clave cifrada. Detalles en [mail.md](mail.md).

## Operación

- **Actualizar**: push a la rama (Auto Deploy) o **Deploy**. `app`, `queue` y `scheduler`
  esperan a que `migrate` termine bien; si las migraciones fallan, el despliegue queda en error
  y esos servicios no se recrean.
- **Respaldos**: en **Backups**, tipo Compose, servicio `postgres`, base `starter`, hacia un
  destino S3, diario. Respalda también el Environment (contiene `APP_KEY`).
- **Diagnóstico**: `php artisan starter:doctor` en la terminal del contenedor `app` (Dokploy →
  Docker → Terminal), o los logs de `app` y `migrate`.
- **Producción**: despliega desde `main` o etiquetas, fija `STARTER_TAG` y usa
  `STARTER_AI_SIMULATED=false` con claves reales cargadas en el panel.
