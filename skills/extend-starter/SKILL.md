---
name: extend-starter
description: Agrega un módulo de negocio completo al Laravel Starter (migración, modelo, Policy, permisos, Actions, Form Request, páginas Vue/Inertia, API, herramientas IA, documentación, pruebas y E2E) con starter:make-module y lo adapta respetando AGENTS.md. Úsala para nuevas entidades o pantallas CRUD.
---

# extend-starter

Genera el módulo con `php artisan starter:make-module` y luego ajusta reglas de negocio en
las Actions. El módulo **Proyectos** (`docs/modules/projects.md`) es el patrón de referencia.

## Entradas

| Entrada | Ejemplo |
| --- | --- |
| Nombre singular | `Task` |
| Campos y tipos | `title:string:max=150`, `status:enum=todo|doing|done`, `due_on:date:nullable` |
| Etiquetas en español | `--label-es=tarea/tareas --gender-es=f`, `label_es=` y `options_es=` por campo |
| ¿API JSON? ¿herramientas IA? | por defecto API sí; `--ai` para herramientas del chat |

## Prerrequisitos

- Proyecto instalado y verde (`php artisan starter:verify`).
- Leer `AGENTS.md`: casos de uso en `app/Actions`, autorización con Policies y permisos del
  catálogo, textos en inglés traducidos en `lang/es.json`, componentes de `@starter/ui`.

## Pasos

1. Escribir la especificación JSON en `docs/modules/<slug>.json` (ver `docs/modules/projects.json`)
   o usar opciones `--field`.
2. Revisar el plan: `php artisan starter:make-module --spec=../../docs/modules/<slug>.json --dry-run --json`.
3. Generar: el mismo comando sin `--dry-run` (código 3 = archivos existentes; usa `--force` solo
   para regenerar a propósito).
4. `php artisan migrate`, `php artisan starter:permissions`, `php artisan wayfinder:generate --with-form`.
5. Ajustar reglas de negocio **solo** en `app/Actions/<Modelos>/*` (validación en `rules()`,
   autorización con `Gate::forUser($actor)`, `audit()`); columnas o textos en las páginas.
6. Si tiene API: `php artisan starter:api-docs` y `pnpm api:client:generate`.
7. Agregar un recorrido E2E en `tests/e2e/<slug>.spec.ts` siguiendo `projects.spec.ts`.
8. Para el chat: habilitar las herramientas en la versión del agente y publicarla.

## Archivos

`database/migrations/*_create_<tabla>_table.php`, `app/Models`, `database/factories`,
`app/Policies`, `app/Providers/Modules/<Modelos>ServiceProvider.php` (permisos y herramientas),
`app/Actions/<Modelos>`, `app/Http/Requests`, `app/Http/Resources`, `app/Http/Controllers`
(+ `Api/V1`), `routes/modules/<slug>.{web,api}.php`, `resources/js/pages/<slug>`,
`resources/js/modules/<slug>` (formulario, tipos, menú), `app/Ai/Tools`, `tests/Feature/Modules`,
`docs/modules/<slug>.{md,json}`, `lang/es.json`.

## Comprobaciones

```bash
php artisan test --filter=<Modelos>Test
composer lint:check && composer types:check
cd ../.. && pnpm check && pnpm types:check && pnpm build
php apps/platform/artisan starter:contracts-check
pnpm test:e2e -- tests/e2e/<slug>.spec.ts
```

## Errores frecuentes

| Síntoma | Acción |
| --- | --- |
| Código 2 (`Invalid or reserved field name`) | usa snake_case y evita `id`, `created_by` y marcas de tiempo |
| 403 en el panel | faltó `php artisan starter:permissions` o el rol no tiene el permiso |
| `Cannot find module '@/routes/<slug>'` | ejecuta `php artisan wayfinder:generate --with-form` |
| `openapi.json is outdated` en CI | `php artisan starter:api-docs` y `pnpm api:client:generate` |
| Reglas duplicadas en controlador o herramienta | muévelas a la Action: es la única fuente |
