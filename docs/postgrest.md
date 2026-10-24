# PostgREST opcional y protegido

PostgREST **no** es PostgreSQL ni pgvector: es un servicio adicional que publica una API sobre
los objetos de PostgreSQL que se le permiten. En este starter queda **apagado y privado** por
defecto; el dominio, la autenticación, las herramientas administrativas y la auditoría siguen
en Laravel.

## Activación

1. `STARTER_FEATURE_POSTGREST=true` y `POSTGREST_JWT_SECRET` (32+ caracteres; el mismo valor
   va a `PGRST_JWT_SECRET` del servicio).
2. `php artisan starter:postgrest-install` crea el esquema `api` (vistas y RPC) con el rol de
   la aplicación. Si ese rol tiene `CREATEROLE` y se indica `--password=` (o
   `POSTGREST_DB_PASSWORD`), crea también los roles y permisos; si no, `--sql` imprime el SQL
   completo para el administrador de la base.
3. `docker compose --profile postgrest up -d`: el servicio solo se expone en la red interna
   (`expose: 3000`), nunca en un puerto público.
4. Admin → PostgREST (`settings.update`) muestra el estado y permite **probar como yo**.

Tras cada migración vuelve a ejecutar `starter:postgrest-install`: reemplaza las vistas y
envía `NOTIFY pgrst, 'reload schema'` para refrescar la caché de esquema.

## Qué se publica

Solo el esquema dedicado `api` (los auxiliares viven en `api_private`, no expuesto):

| Objeto | Tipo | Regla |
| --- | --- | --- |
| `api.knowledge_collections` | vista (`security_barrier`) | colecciones activas visibles: `permission:rag.collections.manage`, o `permission:rag.collections.read` y lista de acceso de la colección |
| `api.knowledge_documents` | vista (`security_barrier`) | documentos indexados con `permission:rag.documents.read`, lista de la colección y lista efectiva del documento (documento → fuente → colección) |
| `api.whoami()` | RPC | identidad y principales que recibió PostgREST |

No se publican usuarios, sesiones, códigos OTP, claves, presupuestos, trabajos ni tablas
internas, y **no hay escrituras**: los cambios pasan por Laravel, sus Policies, eventos y
auditoría.

## Roles

| Rol | Uso |
| --- | --- |
| `starter_postgrest_authenticator` | inicia sesión (LOGIN NOINHERIT) y solo puede cambiar a los dos siguientes |
| `starter_postgrest_anon` | peticiones sin JWT: **sin** permisos (ni siquiera `USAGE` del esquema) |
| `starter_postgrest_user` | rol del JWT: `USAGE` de `api`, `SELECT` de las vistas y `EXECUTE` de la RPC; nada sobre las tablas |

Las vistas se ejecutan con los privilegios de su dueño (el rol de la aplicación), por eso el
rol de PostgREST nunca necesita permisos sobre las tablas base.

## Identidad: JWT corto firmado por Laravel

La integración es **servidor a servidor**: `App\PostgRest\PostgRest::as($user)` firma en cada
llamada un JWT HS256 de 60 s con:

```json
{ "role": "starter_postgrest_user", "sub": "42", "principals": ["everyone", "user:42", "role:reader", "permission:rag.documents.read"], "aud": "postgrest", "iat": 1761300000, "exp": 1761300060 }
```

PostgREST verifica la firma, cambia al rol y deja los claims en `request.jwt.claims`; las
vistas filtran con esos principales. Una cookie de Laravel nunca autentica contra PostgREST.

Para acceso directo desde un cliente existe un intercambio opcional
`POST /api/v1/postgrest/token` (sesión o Sanctum `read`), apagado salvo
`POSTGREST_DIRECT_TOKENS=true`: entrega un JWT de `POSTGREST_JWT_TTL` segundos (300 por
defecto), queda auditado y responde 404 si la opción está apagada.

## Contrato

`php artisan starter:postgrest-contract` guarda el OpenAPI que genera PostgREST para el esquema
`api` en `tooling/contracts/postgrest.json`, **separado** del OpenAPI de Laravel
(`tooling/contracts/openapi.json`); no se mezclan. El archivo solo existe tras ejecutarlo con
el servicio en marcha.

## Salud

La verificación `postgrest` de `/health/ready` solo es obligatoria con la bandera activa
(firma un JWT del sistema y llama `rpc/whoami`). Apagado, nunca afecta la disponibilidad.

## Verificación

`tests/Feature/PostgRestTest.php` prueba el JWT (firma, alteración, secreto), las vistas en
PostgreSQL real fijando `request.jwt.claims` como lo hace PostgREST, los roles (`SET ROLE`: el
rol de usuario no lee `users`, `login_challenges` ni `rag_documents`; el anónimo no usa el
esquema), el cliente servidor a servidor y la bandera. Requiere un rol de pruebas con
`CREATEROLE` o miembro de los roles de PostgREST; si no, esa prueba se omite.
