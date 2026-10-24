---
name: manage-rag
description: Activa y opera el conocimiento (RAG) del Laravel Starter con PostgreSQL/pgvector - diagnóstico, activación interna o externa, perfiles de embeddings, colecciones, fuentes, ingesta durable, reindexación, evaluación y mantenimiento - respetando listas de acceso y costos. Úsala para preparar o mantener la búsqueda con citas.
---

# manage-rag

El modo indica dónde vive el conocimiento (interno, PostgreSQL externo o API de
recuperación); las listas de acceso y la trazabilidad se aplican igual en todos.

## Entradas

| Entrada | Ejemplo |
| --- | --- |
| Modo | `internal`, `external-postgres`, `external-api` |
| Modelo de embeddings | uno habilitado con capacidad de embeddings y tarifa |
| Colecciones y acceso | `documentacion` con ACL `role:editor` |
| Fuentes | archivos, URLs/sitemaps (sin SSRF), discos, adaptadores |
| Presupuesto de ingesta | `max_cost` confirmado para trabajos grandes |

## Prerrequisitos

- `STARTER_FEATURE_RAG=true`, pgvector instalado (`CREATE EXTENSION vector` por un administrador
  si el rol no puede) y un worker de colas en marcha (`php artisan queue:work`).
- Permisos `rag.*` según la tarea (`rag.collections.manage`, `rag.sources.manage`,
  `rag.jobs.manage`, `rag.playground.use`, `settings.update` para conexiones).

## Pasos

1. Diagnosticar: `php artisan starter:rag-doctor` (todas: `--all`). No cambia nada.
2. Activar: Admin → Conocimiento (interno) o Conexiones (externo: diagnosticar y activar), o
   `rag.mode` en `starter.config.json` + `php artisan starter:config-apply`.
3. Crear el perfil de embeddings y las colecciones con su lista de acceso.
4. Agregar fuentes y sincronizar; seguir los trabajos en la página de la colección y confirmar el
   presupuesto si se pausan.
5. Probar en el laboratorio de recuperación y guardar preguntas en un conjunto de evaluación;
   ejecutar la evaluación (recall@k, MRR).
6. Conectar la colección al agente (RAG `required`/`optional`) y publicar la versión.
7. Mantenimiento: `php artisan starter:rag-reindex <slug> --as=<email>` tras cambiar perfil o
   fragmentación (versión nueva; la activa sigue respondiendo), `starter:rag-maintain` y
   `starter:rag-retention` programados.

## Archivos

`packages/php/knowledge`, `app/Actions/Knowledge`, `docs/knowledge.md`, `docs/rag-external.md`,
`tooling/contracts/retrieval-api.openapi.json`.

## Comprobaciones

- `php artisan starter:rag-doctor --json` con `"passed": true` y `"available": true`.
- Documentos `indexed`, trabajos sin fallas recientes, búsqueda del laboratorio con pasajes y
  citas solo de colecciones permitidas para el usuario.
- Evaluación sin regresiones respecto de la anterior.

## Errores frecuentes

| Síntoma | Acción |
| --- | --- |
| `permission denied to create extension "vector"` | pide al DBA `CREATE EXTENSION vector;` y reactiva |
| Trabajo pausado | confirma `max_cost` o reduce la fuente; no lo fuerces sin autorización |
| `The knowledge connection does not support this operation` | conexión de solo lectura o API: ingesta/bajas se hacen en el origen |
| Sin resultados para un usuario | revisa ACL de colección, fuente y documento: es el comportamiento esperado si no tiene acceso |
| Reindexación rechazada | ya hay una en curso: espera o cancela el trabajo en la página de la colección |
