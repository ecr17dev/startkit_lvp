# Conocimiento (RAG)

Módulo `Starter\Knowledge` (`packages/php/knowledge`) más la interfaz y las Actions de
`apps/platform` (`app/Actions/Knowledge`, `app/Http/Controllers/Admin/Knowledge`,
`resources/js/pages/admin/knowledge`). RAG es opcional: con `STARTER_FEATURE_RAG=false`
no aparece en el panel, no requiere pgvector y no genera embeddings.

## Activación y diagnóstico

Admin → Conocimiento → *Diagnosticar y activar* (permiso `settings.update`) ejecuta
`Starter\Knowledge\Store\Provisioner::diagnose()` sobre la conexión elegida:

1. Conectividad y versión de PostgreSQL (13 o superior).
2. TLS: aviso en redes locales; error para conexiones externas en producción.
3. pgvector disponible y habilitado. `CREATE EXTENSION vector` solo se intenta si el
   administrador marca la opción; si el rol no puede, se muestra el SQL para el DBA.
4. Privilegios: el rol de ejecución no necesita superusuario (solo `CREATE` en el esquema
   para crear las tablas, o tablas ya creadas por un rol de migración).
5. Esquema: crea (idempotente) la tabla `rag_chunks` y sus índices.
6. Prueba de inserción y búsqueda vectorial y de texto en un conjunto diagnóstico aislado,
   dentro de una transacción que se revierte.

El modo (`rag.mode` en la tabla `settings`) solo cambia si todas las verificaciones pasan.
Desactivar detiene trabajos nuevos y recuperación, cancela de forma cooperativa los
abiertos y **no** borra documentos ni tablas. `php artisan starter:rag-diagnose` repite el
diagnóstico desde la consola.

Modos: `disabled`, `internal` (este PostgreSQL con pgvector), `external_pgsql` y
`external_api` (etapa 7).

## Modelo de datos

**Plano de control** (siempre en la base de la aplicación, migración
`2026_01_04_000000_create_rag_tables`): `rag_connections`, `rag_embedding_profiles`,
`rag_collections`, `rag_sources`, `rag_documents`, `rag_document_versions`,
`rag_index_versions` y `rag_jobs`.

**Plano de datos** (en la conexión RAG, creado por el diagnóstico): `rag_chunks` (texto,
metadatos de ubicación, `acl text[]` con índice GIN, `ts_config regconfig` y la columna
generada `search tsvector`) y una tabla `rag_embeddings_p{id}` por perfil con
`vector(dimensiones)` e índice HNSW cuando corresponde. No hay claves foráneas hacia el
plano de control porque puede vivir en otra base; los ids necesarios para filtrar están
desnormalizados.

### Perfiles de embeddings

Un perfil es un espacio vectorial: conexión, modelo (función `embeddings` del catálogo de
IA), dimensiones, métrica (`cosine`, `l2`, `inner_product`), normalización y tamaño de
lote. Modelo, dimensiones y métrica son inmutables. El índice se decide por dimensiones y
versión de pgvector (`Provisioner::indexStrategy()`):

| Dimensiones | pgvector | Índice |
| --- | --- | --- |
| ≤ 2000 | ≥ 0.5 | HNSW sobre `vector` |
| ≤ 4000 | ≥ 0.7 | HNSW sobre la expresión `embedding::halfvec(n)` |
| mayores o pgvector < 0.5 | — | sin índice: búsqueda exacta (nunca se truncan vectores) |

La decisión queda registrada en `rag_embedding_profiles.storage`.

### Colecciones

Agrupan conocimiento con: perfil (para la próxima versión del índice), idioma de la
búsqueda de texto (`simple`, `es`, `en`, …), fragmentación (`target_tokens`,
`overlap_tokens`, `max_tokens`), búsqueda (método, top-k, umbral, presupuesto de contexto)
y **lista de acceso**. Los principales son `everyone` (todo usuario autenticado),
`role:{nombre}`, `user:{id}` y `permission:{nombre}`; se validan contra roles, usuarios y
permisos existentes. Cambiar la lista actualiza al instante el `acl` de los fragmentos que
la heredan.

## Fuentes

| Tipo | Lectura | Bajas detectadas |
| --- | --- | --- |
| Archivos (`upload`) | TXT, Markdown y PDF validados por contenido (un `.pdf` que no es PDF se rechaza). El mismo nombre crea una nueva versión del documento. | Solo eliminación manual |
| Páginas web (`url`) | URLs listadas y, hasta la profundidad indicada, enlaces del mismo host (y prefijo de ruta), con máximo de páginas. | Sí, si el recorrido no fue truncado por el límite; 404/410 eliminan |
| Sitemap (`sitemap`) | `<loc>` de un sitemap (y un índice de sitemaps, un nivel). `<lastmod>` y ETag evitan descargas. | Sí |
| Contenido interno (`adapter`) | Contrato `KnowledgeAdapter`: ids estables, texto, metadatos y ACL por documento. Ejemplo: `App\Knowledge\ProjectDocsAdapter` (docs/*.md). | Sí |
| Disco (`disk`) | Archivos bajo un prefijo de un disco de `config/filesystems.php` permitido en `STARTER_RAG_DISKS` (por ejemplo un bucket S3 compatible). | Sí |

DOCX, hojas de cálculo y OCR se agregan registrando un `Parser` en `ParserRegistry` con
sus pruebas. Un PDF escaneado sin texto queda en estado `empty` («Sin texto»), nunca como
indexado.

### Lectura de URLs sin SSRF

`Starter\Knowledge\Ingestion\Fetch\SafeFetcher`:

- solo `http`/`https`, puertos permitidos (80/443), sin credenciales en la URL;
- todas las direcciones que resuelve el host deben ser públicas: se bloquean loopback,
  redes privadas, link-local (incluye `169.254.169.254`), CGNAT, IPv6 locales y mapeadas,
  rangos reservados y hosts de metadatos;
- la conexión se fija a la IP validada (`CURLOPT_RESOLVE`), lo que impide DNS rebinding;
- las redirecciones se siguen manualmente y se validan en cada salto (máximo 3);
- tiempo de conexión y total, tamaño máximo (cabecera y descarga) y tipos de contenido
  permitidos; listas `STARTER_RAG_ALLOWED_HOSTS` y de hosts bloqueados.

Las fuentes privadas usan conectores específicos (adaptador o disco), nunca este lector.

## Pipeline durable

Cada operación es un registro `rag_jobs` (estado, etapa, progreso conocido, intentos,
error saneado, tiempos por etapa, unidades y costo) que viaja por la cola de Laravel como
`ProcessRagJob` con solo su id. `JobRunner::claim()` toma el registro con un `UPDATE`
condicional (también recupera trabajos cuyo worker murió), así que un mensaje duplicado o
recuperado nunca procesa dos veces lo mismo a la vez.

Estados: `queued`, `running`, `retrying`, `paused`, `succeeded`, `failed`, `cancelled`.

Etapas: descubrir → leer → validar → extraer → normalizar → deduplicar (`sync_source`,
`DocumentIngestor`) → fragmentar → embeddings → persistir → verificar → publicar
(`index_document`, `DocumentIndexer`).

- **Idempotencia**: el documento lógico es único por fuente e id externo; solo un contenido
  nuevo (hash SHA-256 del texto normalizado) crea versión; indexar reemplaza en una
  transacción los fragmentos del documento en cada versión del índice, así que los
  reintentos no duplican nada.
- **Incremental**: marcas del origen (lastmod, versión del adaptador, hash del archivo),
  `ETag`/`Last-Modified` con solicitudes condicionales y comparación de hash.
- **Deduplicación**: un documento con el mismo contenido que otro de la misma fuente queda
  como `duplicate`.
- **Concurrencia**: cupos por conexión de IA (`STARTER_RAG_CONCURRENCY`) con locks de
  caché; sin cupo, el mensaje se libera y se reintenta sin contar como fallo.
- **Proveedor**: el SDK reintenta respuestas con `Retry-After` corto; límites, sobrecarga
  y errores de conexión pasan a `retrying` con backoff (máximo 5 intentos).
- **Presupuesto de ingesta**: antes de generar embeddings se estima volumen y costo
  (≈4 caracteres por token, más títulos y solapamiento). Por encima de
  `STARTER_RAG_CONFIRM_ABOVE_TOKENS` o `STARTER_RAG_CONFIRM_ABOVE_COST` el trabajo queda
  `paused` hasta que un administrador confirma un costo máximo; los hijos reservan el costo
  de cada lote sobre el padre con un `UPDATE` condicional y liquidan el real.
- **Cancelación cooperativa** en cada punto de control; el costo ya incurrido se conserva.
- **Costos**: cada lote pasa por `AiGateway::run()` con la función `embedding`, queda en el
  libro de consumo (con `rag_job_id`, colección y versión del índice en los metadatos) y
  respeta los presupuestos de IA. Un precio desconocido deja el costo como desconocido.
- **Recuperación**: `starter:rag-maintain` (scheduler, cada minuto, un servidor, sin
  solapamiento) reencola trabajos sin latido y despacha las sincronizaciones programadas
  vencidas (`hourly`, `daily`, `weekly`) solo con `STARTER_RAG_AUTOMATIC=true`.

### Fragmentación

`Chunker` respeta títulos Markdown (las páginas HTML se convierten a Markdown ligero) y
párrafos, agrupa hasta el tamaño objetivo con solapamiento en límites de palabra, divide
bloques largos por oraciones y luego por palabras, y guarda la ruta de secciones, los
offsets en bytes del contenido extraído y las páginas (PDF). El tamaño en tokens es una
estimación identificada (`chars/4`), no un tokenizador del modelo. El texto vectorizado
incluye título y sección; el fragmento almacenado conserva el texto original.

### Versiones del índice y reindexación

La primera versión se publica vacía y se llena de forma incremental. Reindexar (cambio de
perfil o de fragmentación) crea una versión `building` desde las versiones de documento
almacenadas, sin volver a leer el origen; las sincronizaciones durante la reindexación
escriben en ambas versiones. Solo cuando todos los documentos se indexaron, la nueva pasa a
`active`, la anterior a `superseded` y un trabajo purga sus fragmentos. Si falla o se
cancela, la nueva se descarta y la activa sigue respondiendo.

### Bajas

Eliminar un documento (o detectarlo como borrado en el origen) borra sus fragmentos y
vectores en la misma solicitud, de modo que deja de aparecer en las consultas al instante,
y un trabajo `purge_document` elimina originales y versiones. Eliminar una fuente o una
colección funciona igual (la colección exige escribir su identificador). La recuperación
no guarda caché de resultados, así que ninguna baja o cambio de ACL deja resultados
obsoletos.

## Permisos

| Permiso | Uso |
| --- | --- |
| `rag.collections.read` | Ver colecciones visibles por su lista de acceso |
| `rag.collections.manage` | Crear y editar colecciones y perfiles |
| `rag.sources.manage` | Fuentes, archivos y sincronizaciones |
| `rag.documents.read` | Ver documentos, fragmentos y originales (además de cumplir las listas de acceso) |
| `rag.documents.delete` | Eliminar documentos |
| `rag.jobs.manage` | Cancelar, reintentar, confirmar presupuesto y reindexar |
| `settings.update` | Activar, diagnosticar y desactivar RAG |

## Configuración

Variables `STARTER_RAG_*` en `.env.example` y bloque `rag` de `config/starter.php`.

## Demo local

Con `STARTER_FEATURE_RAG=true` y el proveedor simulado, `php artisan db:seed` activa el
modo interno (si pasa el diagnóstico), crea el perfil «Simulado · 256d» y la colección
«Documentación del proyecto» con el adaptador de docs. El proveedor simulado genera
embeddings léxicos deterministas (hashing de palabras): permiten probar el flujo completo
sin red pero **no** son un modelo semántico.
