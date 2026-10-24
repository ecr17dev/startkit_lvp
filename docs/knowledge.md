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

Modos: `disabled`, `internal` (este PostgreSQL con pgvector), `external_pgsql` (otra base
PostgreSQL con pgvector, administrable o de solo lectura) y `external_api` (servicio de
recuperación). Las conexiones externas, su matriz de capacidades y la activación explícita
se describen en [rag-external.md](rag-external.md).

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

## Recuperación

`Starter\Knowledge\Retrieval\Retriever` busca sobre la **versión activa** de cada colección:

- **Vectorial**: la consulta se vectoriza con el perfil exacto de esa versión (función de
  consumo `rag` en el libro de IA) y se ordena por distancia (`<=>`, `<->` o `<#>`). Con
  menos de `STARTER_RAG_EXACT_SEARCH_BELOW` fragmentos la búsqueda es exacta (sin índice);
  si no, HNSW con una lista de candidatos amplia (`hnsw.ef_search`) y, desde pgvector 0.8,
  escaneo iterativo, para que los filtros no dejen la respuesta vacía.
- **Texto completo**: `websearch_to_tsquery` en el idioma de la colección, con OR entre
  términos y `ts_rank_cd` normalizado.
- **Híbrida**: fusión de rankings con Reciprocal Rank Fusion (`Σ 1/(60 + posición)`).
  Nunca se comparan directamente similitudes de métodos o perfiles distintos.

Las listas de acceso se aplican **dentro de cada consulta SQL** (`acl && principales del
usuario`) junto con colección y versión activa; además, solo se consideran colecciones cuya
lista incluye al usuario. Los umbrales (`min_score` para similitud vectorial y
`min_text_rank`) se aplican antes de fusionar; luego se toma el top-k dentro del
presupuesto de contexto en tokens. Los puntajes ordenan dentro de un método: no son
probabilidades.

Cada búsqueda queda trazada en `rag_retrieval_runs` (consulta, método, ajustes, latencias,
costo de la vectorización, resultado) y `rag_retrieval_hits` (ranking, puntajes, ubicación
y si fue citado), sin copiar el contenido restringido. Las trazas se purgan tras
`STARTER_RAG_RETRIEVAL_RETENTION_DAYS` (por defecto 90) con `model:prune`.

## Respuestas con evidencia

Cada agente define su bloque `rag` (Admin → Agentes → Contexto):

| Ajuste | Valores |
| --- | --- |
| `mode` | `off`, `always` (busca antes de cada mensaje) o `tool` (el modelo decide con `knowledge_search`) |
| `collections` | colecciones autorizadas; la publicación falla si no existen |
| `method`, `top_k`, `max_context_tokens`, `min_score` | ajustes de recuperación (por defecto los de la colección) |
| `evidence` | `required` (abstenerse sin evidencia) o `general` (responder en términos generales, identificado) |
| `min_hits` | pasajes mínimos para considerar que hay evidencia |

Flujo (`App\Ai\Knowledge\KnowledgeGrounding`): validar acceso → resolver agente y
colecciones → recuperar pasajes autorizados → construir un contexto acotado → responder →
validar referencias.

- Los pasajes viajan como un **mensaje de datos** previo al prompt, marcado como no
  confiable, nunca en las instrucciones de sistema; las reglas de citación sí van en las
  instrucciones (texto del servidor). Una instrucción dentro de un documento no concede
  permisos ni habilita herramientas: las herramientas siguen saliendo del registro y de los
  permisos del usuario.
- Las citas `[n]` de la respuesta se validan contra los pasajes recuperados en ese turno;
  las que no corresponden se descartan y se cuentan (`invalid`). El resultado del turno es
  `answered`, `general`, `unsupported` (exigía evidencia y no citó) o `abstained`.
- Con `evidence = required` y sin pasajes suficientes, el agente **se abstiene sin llamar al
  modelo**: el intercambio se guarda en la conversación y la traza queda `abstained`.
- El chat recibe un evento `knowledge` con el resultado y las citas (título, sección o
  página, enlace al documento si el usuario puede verlo y al origen), y las citas se
  conservan al reabrir la conversación. La API `POST /api/v1/ai/chat` devuelve el mismo
  bloque `knowledge`.

El proveedor simulado responde de forma extractiva citando `[1]` y `[2]` cuando recibe
contexto, lo que permite probar el flujo completo sin claves.

## Laboratorio de recuperación

Admin → Conocimiento → Laboratorio (`rag.playground.use`): consulta, colecciones, método,
top-k, umbral y presupuesto de contexto; muestra los pasajes autorizados con ranking,
puntajes (RRF, coseno, ts_rank), sección, latencias (vectorización y búsqueda) y costo, y
permite comparar vectorial, texto e híbrida lado a lado. Cada búsqueda queda trazada.

Cada pasaje puede marcarse como relevante o no (👍/👎): el juicio se guarda en el hit de la
traza (`judgement`, `judged_by`) y solo puede emitirlo quien hizo la búsqueda. «Guardar como
pregunta de evaluación» lleva la consulta y los documentos marcados a un conjunto.

## Operación y calidad

Los indicadores operativos y los de calidad semántica se muestran por separado y todos se
derivan de eventos guardados (documentos y versiones, trabajos con sus tiempos por etapa,
el libro de consumo de IA y las trazas de recuperación); no hay contadores paralelos.

### Panel de conocimiento

Admin → Conocimiento → Panel (`rag.analytics.read`), con período (7, 30 o 90 días) y
colección; solo considera colecciones visibles para quien consulta.

| Visualización | Origen |
| --- | --- |
| Documentos por estado y fuente | `rag_documents` (total, procesados y consultables por separado) |
| Ingesta por día | `indexed_at` de documentos y versiones nuevas |
| Fragmentos y volumen por colección | versión activa del índice y tamaño de originales |
| Cola y duración por etapa | `rag_jobs.status` y `timings` (promedio por etapa) |
| Errores por parser y proveedor | documentos `failed`/`empty`, trabajos fallidos por `error_type`, llamadas fallidas del libro |
| Costo de embeddings e indexación | libro de consumo (`embedding` y `rag`); el costo desconocido se cuenta aparte |
| Consultas y latencias | `rag_retrieval_runs` (promedio y p95 por día) |
| Consultas sin resultados y abstenciones | trazas sin hits o con `outcome = abstained` (top 10 normalizado) |
| Citas y feedback por agente | trazas con versión de agente: citas, descartadas, sin citas, 👍/👎 |
| Antigüedad y cambios por fuente | última sincronización y unidades del último trabajo |
| Calidad de recuperación | última ejecución completada de cada conjunto de evaluación |

La proyección 2D de embeddings que la especificación menciona como ampliación opcional **no
está implementada**.

### Conjuntos de evaluación

Admin → Conocimiento → Evaluaciones (`rag.playground.use`). Un conjunto agrupa colecciones
visibles y preguntas con los documentos que una buena búsqueda debe devolver (solo
documentos legibles de esas colecciones). Cada ejecución guarda su configuración (método,
top-k, similitud mínima, presupuesto de contexto; vacío = configuración de la colección),
busca cada pregunta **como quien la inició** (rigen sus listas de acceso) en un trabajo en
cola y calcula:

- **recall@k**: proporción de documentos esperados entre los recuperados;
- **MRR**: promedio de 1 / posición del primer documento esperado;
- **tasa de acierto**: preguntas con al menos un documento esperado;
- tasa sin resultados, latencia promedio y p95, y costo (desconocido si alguna llamada lo es).

Son métricas de recuperación sobre preguntas etiquetadas, no un juicio de la respuesta
generada. Las búsquedas de evaluación quedan trazadas con `surface = evaluation`.

### Feedback de respuestas

Bajo cada respuesta con conocimiento el chat ofrece «¿Te fue útil?» (👍/👎). Se guarda en
las trazas de esa respuesta (`feedback`, `feedback_note`, `feedback_at`) y solo puede
darlo el usuario que la recibió (`POST /ai/chat/messages/{id}/feedback`).

### Tareas programadas

| Comando | Frecuencia | Uso |
| --- | --- | --- |
| `starter:rag-maintain` | cada minuto | recupera trabajos sin latido y despacha sincronizaciones programadas |
| `starter:rag-retention` | cada hora | aplica `retention_days` de cada colección: borra versiones de documento que no son la consultable ni la última, con sus originales si no se comparten |
| `model:prune` (`RetrievalRun`) | diario | elimina trazas más antiguas que `STARTER_RAG_RETRIEVAL_RETENTION_DAYS` |

## API

| Método y ruta | Uso |
| --- | --- |
| `GET /api/v1/rag/status` | modo, conexión, capacidades y diagnóstico |
| `GET /api/v1/rag/collections` · `/{id}` | colecciones visibles, fuentes y versiones |
| `GET /api/v1/rag/collections/{id}/documents` | documentos (paginado, filtros de estado y fuente) |
| `GET /api/v1/rag/documents/{id}` · `DELETE` | documento con fragmentos de la versión activa · baja (202 con `job_id`) |
| `POST /api/v1/rag/collections/{id}/sources` | crear fuente y sincronizar (202 con `job_id`; archivos con multipart) |
| `POST /api/v1/rag/sources/{id}/sync` | sincronizar (devuelve el trabajo abierto si existe) |
| `GET /api/v1/rag/jobs` · `/{id}` · `POST …/cancel` · `…/retry` · `…/resume` | trabajos |
| `POST /api/v1/rag/search` | búsqueda autorizada con trazas |
| `GET /api/v1/rag/analytics` | mismo informe del panel (`days`, `collection`) |
| `GET /api/v1/rag/evaluation-sets` | conjuntos con su última ejecución |
| `POST /api/v1/rag/evaluation-sets/{id}/evaluate` | ejecutar con una configuración (202 con la ejecución) |
| `GET /api/v1/rag/evaluations/{uuid}` | métricas y resultados por pregunta |

Lectura con la habilidad `read`, cambios con `write`; además rigen los permisos `rag.*` y
las listas de acceso. La configuración de cada tipo de fuente (`config.urls`, `config.url`,
`config.adapter`, `config.disk`…) depende del tipo y no la infiere Scramble: está descrita en
la tabla de fuentes de este documento.

## Permisos

| Permiso | Uso |
| --- | --- |
| `rag.collections.read` | Ver colecciones visibles por su lista de acceso |
| `rag.collections.manage` | Crear y editar colecciones y perfiles |
| `rag.sources.manage` | Fuentes, archivos y sincronizaciones |
| `rag.documents.read` | Ver documentos, fragmentos y originales (además de cumplir las listas de acceso) |
| `rag.documents.delete` | Eliminar documentos |
| `rag.jobs.manage` | Cancelar, reintentar, confirmar presupuesto y reindexar |
| `rag.playground.use` | Laboratorio, juicios, evaluaciones y `POST /api/v1/rag/search` |
| `rag.analytics.read` | Panel de conocimiento y `GET /api/v1/rag/analytics` |
| `settings.update` | Activar, diagnosticar y desactivar RAG |

## Configuración

Variables `STARTER_RAG_*` en `.env.example` y bloque `rag` de `config/starter.php`.

## Demo local

Con `STARTER_FEATURE_RAG=true` y el proveedor simulado, `php artisan db:seed` activa el
modo interno (si pasa el diagnóstico), crea el perfil «Simulado · 1024d», la colección
«Documentación del proyecto» con el adaptador de docs y el agente «Experto en
documentación» (RAG `always`, evidencia obligatoria, similitud mínima 0,15). El proveedor simulado genera
embeddings léxicos deterministas (hashing de palabras): permiten probar el flujo completo
sin red pero **no** son un modelo semántico.
