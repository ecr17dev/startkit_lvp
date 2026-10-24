# Módulos: generador `starter:make-module`

El generador crea un módulo completo que sigue las reglas del starter: las **Actions**
autorizan con Policies, validan (sus `rules()` las reutiliza el Form Request) y auditan; el
panel Inertia, la API JSON y las herramientas IA llaman a las mismas Actions. El módulo
**Proyectos** del repositorio se generó con este comando y es el patrón de referencia
([`docs/modules/projects.md`](modules/projects.md), especificación en
[`docs/modules/projects.json`](modules/projects.json)).

## Uso

```bash
cd apps/platform

# Con opciones...
php artisan starter:make-module Task \
  --field="title:string:max=150:label_es=Título" \
  --field="status:enum=todo|doing|done:options_es=Pendiente|En curso|Hecha" \
  --field="due_on:date:nullable:label=Due date:label_es=Vencimiento" \
  --field="estimate:decimal=8,2:nullable" \
  --label-es=tarea/tareas --gender-es=f --icon=ListTodo --ai

# ...o con una especificación JSON (recomendado para agentes)
php artisan starter:make-module --spec=../../docs/modules/projects.json --force
```

| Opción | Uso |
| --- | --- |
| `--field=*` | `nombre:tipo[:modificador...]` (ver tabla) |
| `--spec=` | JSON con `name`, `fields`, `api`, `ai`, `labels.en`, `labels.es` (`singular`, `plural`, `gender`), `icon` |
| `--no-api` | sin API JSON (`/api/v1/...`) |
| `--ai` | herramientas IA `{tabla}_list`, `_create`, `_update`, `_delete` |
| `--label-es=` / `--gender-es=` | etiquetas en español (`proyecto/proyectos`, `m`/`f`) para `lang/es.json` |
| `--icon=` | ícono lucide del menú lateral |
| `--dry-run` / `--json` | solo listar / salida JSON (para agentes) |
| `--force` | sobrescribir (reutiliza la migración existente de la tabla) |
| `--no-format` | no ejecutar Pint ni el formateador del frontend |

Códigos de salida: `0` correcto, `2` entrada inválida, `3` archivos existentes sin `--force`.

### Tipos y modificadores

| Tipo | Columna | Validación | Control |
| --- | --- | --- | --- |
| `string` | `string(max)` | `string`, `max` (255) | `Input` |
| `text` | `text` | `string`, `max:10000` | `Textarea` |
| `integer` | `integer` | `integer`, `min`/`max` | `Input type=number` |
| `decimal=P,S` | `decimal(P,S)` | `numeric`, `decimal:0,S` | `Input type=number` (cast `decimal:S`, cadena) |
| `boolean` | `boolean default false` | `boolean` | `Switch` |
| `date` / `datetime` | `date` / `timestampTz` | `date` | `Input type=date/datetime-local` |
| `enum=a\|b\|c` | `string(50)` indexada | `Rule::in(Modelo::CAMPO_OPTIONS)` | `Select` y filtro del listado |
| `email` / `url` | `string` | `email:rfc` / `url:http,https` | `Input` |

Modificadores: `nullable`, `unique` (regla `Rule::unique` que ignora el registro actual),
`index`, `max=N`, `min=N`, `label=...`, `label_es=...`, `options_es=a|b|c`. Los nombres
reservados (`id`, `created_by`, marcas de tiempo) se rechazan.

## Qué genera

| Archivo (bajo `apps/platform`) | Contenido |
| --- | --- |
| `database/migrations/*_create_{tabla}_table.php` | tabla idempotente, `created_by` (FK `nullOnDelete`), `timestampsTz` |
| `app/Models`, `database/factories` | modelo con casts y relación `creator`, factory |
| `app/Policies/{Modelo}Policy.php` | `viewAny/view` → `{tabla}.read`, `create`, `update`, `delete` |
| `app/Providers/Modules/{Modelos}ServiceProvider.php` | `Permissions::register()` (editor: todos; reader: lectura) y herramientas IA; se agrega a `bootstrap/providers.php` |
| `app/Actions/{Modelos}/{Create,Update,Delete}{Modelo}.php` | autorización, validación y `audit()` |
| `app/Http/Requests`, `app/Http/Resources` | Form Request (mismas reglas) y API Resource |
| `app/Http/Controllers/{Modelo}Controller.php`, `routes/modules/{slug}.web.php` | panel Inertia con búsqueda, filtro, orden (`TableSort`, columnas en `SORTABLE`) y paginación |
| `app/Http/Controllers/Api/V1/...`, `routes/modules/{slug}.api.php` | API con habilidades `read`/`write` (documentada en OpenAPI) |
| `app/Ai/Tools/{Modelos}{List,Create,Update,Delete}.php` | herramientas del operador (las escrituras pueden pedir confirmación; borrar es sensible) |
| `resources/js/pages/{slug}/{Index,Create,Edit}.vue` | índice con el `DataTable` del kit en modo servidor (búsqueda y orden en la URL, tarjetas en teléfonos, acciones en `ActionsMenu`) y formularios con `@starter/ui` ([kit de UI](ui-kit.md)) |
| `resources/js/modules/{slug}/` | formulario compartido, tipos TypeScript y entrada del menú (`navigation.ts`) |
| `tests/Feature/Modules/{Modelos}Test.php` | permisos, validación, auditoría, API y herramientas |
| `docs/modules/{slug}.md` y `.json` | documentación y especificación (permite regenerar) |

Las rutas se cargan solas: `routes/web.php` incluye `routes/modules/*.web.php` dentro de
`auth`, `verified` y `onboarded`, y `routes/api.php` incluye `routes/modules/*.api.php` en
`/api/v1` con `auth:sanctum`. El menú lateral reúne `resources/js/modules/*/navigation.ts`
y muestra cada entrada solo con su permiso (la interfaz solo se adapta; el backend
siempre autoriza).

## Después de generar

```bash
php artisan migrate
php artisan starter:permissions                 # crea los permisos del módulo
php artisan wayfinder:generate --with-form      # o pnpm build
php artisan starter:api-docs && pnpm api:client:generate   # si tiene API
php artisan test --filter=TasksTest
```

Luego ajusta reglas de negocio en las Actions (nunca en controladores ni herramientas) y
textos o columnas en las páginas. Los textos se escriben en inglés y sus traducciones
viven en `lang/es.json`; el generador agrega las que conoce sin sobrescribir las existentes.
Para que el chat use las herramientas, habilítalas en la versión del agente
(Admin → Agentes → Herramientas) y publica la versión.
