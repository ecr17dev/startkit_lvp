# Conocimiento externo: PostgreSQL y API de recuperación

El modo RAG indica **dónde vive el conocimiento**, nunca si los documentos son públicos. Las
listas de acceso, las Policies y la trazabilidad se aplican igual en los tres modos.

| Modo | Almacenamiento y búsqueda | Administración en el starter |
| --- | --- | --- |
| Interno | PostgreSQL de la aplicación con pgvector | Pipeline y control completos |
| PostgreSQL externo | Otra base PostgreSQL con pgvector (otro servidor, otra base o un esquema dedicado) | Pipeline y control completos; **solo búsqueda** si la conexión es de solo lectura |
| API de recuperación | Servicio HTTP que cumple el contrato de búsqueda | Búsqueda y trazas locales; el resto según las capacidades que declare |

Admin → Conocimiento → **Conexiones** (`settings.update`) lista la conexión interna y las
externas con su estado, capacidades y último diagnóstico. El modo activo decide dónde se
crean las colecciones y perfiles **nuevos**; cada colección conserva su conexión, y la
recuperación consulta cada plano de datos por separado (sin joins entre bases).

## PostgreSQL externo

### Configuración

| Campo | Uso |
| --- | --- |
| Host, puerto, base | Destino de la conexión (`rag_{id}`, registrada en tiempo de ejecución) |
| Esquema | Esquema de las tablas de conocimiento (por defecto `rag`); la ruta de búsqueda es `esquema, public` para que el tipo `vector` instalado en `public` sea visible |
| TLS (`sslmode`) | `disable` … `verify-full`; en producción el diagnóstico exige una conexión cifrada |
| Certificado CA | Ruta en el servidor para `verify-ca`/`verify-full` |
| Usuario y contraseña | Cifrados (`encrypted:array`) o referencias `env:STARTER_RAG_SECRET_*` |
| Tiempos | Conexión (s) y `statement_timeout` (ms) por sesión |
| Solo lectura | `default_transaction_read_only = on` en la sesión y capacidades sin ingesta |

Los secretos nunca vuelven al navegador ni a la API (`••••••••`); dejarlos vacíos al editar
conserva el valor guardado. Solo se aceptan referencias a variables que empiezan por
`STARTER_RAG_SECRET_`, nunca `APP_KEY`, `DB_*` u otras de la aplicación. Cambiar dirección,
esquema o credenciales devuelve la conexión a `pending` hasta un nuevo diagnóstico.

### Diagnóstico y activación

- **Diagnosticar** nunca crea ni escribe nada en la base externa: conectividad, TLS,
  versión (≥ 13), pgvector, privilegios, existencia del esquema y de las tablas, y una prueba
  de búsqueda dentro de una transacción que se revierte.
- **Activar** es el paso administrativo explícito: crea el esquema (`CREATE SCHEMA IF NOT
  EXISTS`), las tablas e índices y, solo si se marca, `CREATE EXTENSION vector`. Si el rol no
  tiene privilegios, el diagnóstico muestra el SQL para el administrador y el modo no cambia.
- Una conexión de **solo lectura** se activa sin crear nada: exige que `rag_chunks` exista
  en el esquema y sea legible, y prueba ambas búsquedas.

### Capacidades

| Capacidad | Escritura | Solo lectura | API |
| --- | --- | --- | --- |
| `query` (búsqueda) | ✓ | ✓ | según el servicio |
| `list_collections`, `list_documents`, `chunks`, `metrics` | ✓ | ✓ | según el servicio |
| `ingest` (fuentes, sincronizar, reindexar) | ✓ | — | — |
| `delete` (bajas) | ✓ | — | — |
| `jobs` | ✓ | — | — |

La interfaz solo muestra las acciones disponibles y el backend las vuelve a comprobar
(`Knowledge::ensureFor()`): una colección de una conexión de solo lectura no ofrece nuevas
fuentes, sincronización, reindexación ni bajas, y la API responde 503. Las bajas no exigen
que RAG esté activo (para atender solicitudes de eliminación con el módulo apagado).

### API

| Método y ruta | Uso |
| --- | --- |
| `GET /api/v1/rag/connections` | conexiones con capacidades y diagnóstico (sin secretos) |
| `POST /api/v1/rag/connections/{id}/diagnose` | diagnóstico (no modifica la base) |
| `POST /api/v1/rag/connections/{id}/activate` | activación explícita (422 con el informe si falla) |

Requieren `settings.update`; el diagnóstico y la activación, además, la habilidad `write`.
