# Módulo Proyectos

Generado con `php artisan starter:make-module` (especificación en
`docs/modules/projects.json`). Sigue el patrón del starter: las **Actions** autorizan
(Policies), validan (sus `rules()` las reutiliza el Form Request) y auditan; el panel,
la API y las herramientas IA llaman a las mismas Actions.

## Campos

| Campo | Tipo | Requerido | Notas |
| --- | --- | --- | --- |
| `name` | string | sí | máx. 150, Nombre |
| `code` | string | sí | único, máx. 20, Código |
| `description` | text | no | Descripción |
| `status` | enum (planned, active, on_hold, closed) | sí | Estado |
| `starts_on` | date | no | Fecha de inicio |
| `due_on` | date | no | Fecha de término |
| `budget` | decimal | no | Presupuesto |
| `is_billable` | boolean | no | Facturable |

Además: `created_by` (usuario que lo creó, `nullOnDelete`) y `created_at`/`updated_at` (UTC).

## Permisos

| Permiso | Uso | Roles por defecto |
| --- | --- | --- |
| `projects.read` | ver la lista y el detalle | owner, admin, editor, reader |
| `projects.create` | crear | owner, admin, editor |
| `projects.update` | editar | owner, admin, editor |
| `projects.delete` | eliminar | owner, admin, editor |

Se registran en `App\Providers\Modules\ProjectsServiceProvider`; aplica el catálogo con
`php artisan starter:permissions`.

## Archivos

- `apps/platform/database/migrations/*_create_projects_table.php`
- `apps/platform/app/Models/Project.php`
- `apps/platform/database/factories/ProjectFactory.php`
- `apps/platform/app/Policies/ProjectPolicy.php`
- `apps/platform/app/Providers/Modules/ProjectsServiceProvider.php`
- `apps/platform/app/Actions/Projects/CreateProject.php`
- `apps/platform/app/Actions/Projects/UpdateProject.php`
- `apps/platform/app/Actions/Projects/DeleteProject.php`
- `apps/platform/app/Http/Requests/ProjectRequest.php`
- `apps/platform/app/Http/Resources/ProjectResource.php`
- `apps/platform/app/Http/Controllers/ProjectController.php`
- `apps/platform/routes/modules/projects.web.php`
- `apps/platform/resources/js/pages/projects/Index.vue`
- `apps/platform/resources/js/pages/projects/Create.vue`
- `apps/platform/resources/js/pages/projects/Edit.vue`
- `apps/platform/resources/js/modules/projects/ProjectForm.vue`
- `apps/platform/resources/js/modules/projects/types.ts`
- `apps/platform/resources/js/modules/projects/navigation.ts`
- `apps/platform/tests/Feature/Modules/ProjectsTest.php`
- `docs/modules/projects.md`
- `apps/platform/app/Http/Controllers/Api/V1/ProjectController.php`
- `apps/platform/routes/modules/projects.api.php`
- `apps/platform/app/Ai/Tools/ProjectsList.php`
- `apps/platform/app/Ai/Tools/ProjectsCreate.php`
- `apps/platform/app/Ai/Tools/ProjectsUpdate.php`
- `apps/platform/app/Ai/Tools/ProjectsDelete.php`

## Rutas

- Panel: `/projects` (`projects.*`), cargadas por `routes/web.php` desde `routes/modules/projects.web.php`.
- API: `GET|POST /api/v1/projects`, `GET|PATCH|DELETE /api/v1/projects/{id}` (`api.v1.projects.*`; habilidades `read`/`write`). Documentadas en OpenAPI (`php artisan starter:api-docs`).

## Herramientas IA

- `projects_list` (Read, requiere `projects.read`)
- `projects_create` (Write, requiere `projects.create`)
- `projects_update` (Write, requiere `projects.update`)
- `projects_delete` (Sensitive, requiere `projects.delete`)

Se ofrecen solo si el agente las habilita y el usuario tiene el permiso; las escrituras pueden pedir confirmación.

## Auditoría

`projects.created`, `projects.updated` (campos cambiados) y `projects.deleted`.
