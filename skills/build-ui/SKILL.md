---
name: build-ui
description: Construye pantallas del Laravel Starter (listados, formularios, wizards, paneles con gráficos, modales, menús) con el kit de UI (@/components/kit) y las primitivas shadcn-vue de @starter/ui, respetando permisos, traducciones y accesibilidad. Úsala para cualquier página o componente Vue/Inertia nuevo o para rediseñar uno existente.
---

# build-ui

Arma la interfaz con piezas que ya existen en vez de escribir HTML y estilos a mano. La guía
completa es `docs/ui-kit.md` y el catálogo en vivo está en `/ui-kit`
(`STARTER_FEATURE_UI_KIT`, activo en local).

## Entradas

| Entrada | Ejemplo |
| --- | --- |
| Tipo de pantalla | listado, formulario, wizard, panel de indicadores, detalle con pestañas |
| Datos y origen | props de Inertia del controlador (paginados o no), formulario `useForm` |
| Acciones y permisos | crear/editar/eliminar con `can.*` o `usePermissions()` |
| Textos | en inglés; traducción en `lang/es.json` |

## Prerrequisitos

- Leer `AGENTS.md` y `docs/ui-kit.md` (tabla de compuestos y recetas).
- Rutas tipadas con Wayfinder (`php artisan wayfinder:generate --with-form`).
- Para un CRUD completo, partir de `starter:make-module` (skill `extend-starter`): su índice
  ya es un `DataTable` en modo servidor.

## Pasos

1. Elegir la receta de `docs/ui-kit.md`: listado (`DataTable` + `useTableQuery` +
   `TableSort`), formulario (`FormField`, `DatePicker`, `SubmitButton`), wizard (`WizardForm`),
   panel (`KpiCard`, `BarChart`, `LineChart`, `DonutChart`), modal (`Modal`), acciones
   (`ActionsMenu`, `CommandMenu`), avisos (`StatusAlert`, `notify`, `EmptyState`).
2. Copiar el ejemplo más cercano del catálogo (`resources/js/pages/ui-kit/*.vue`, botón
   **Código**) y adaptarlo; importar desde `@/components/kit` y `@starter/ui/components/*`.
3. En el servidor: validar en Form Request/Action, autorizar con Policies y, para tablas,
   ordenar solo con `TableSort` (lista de columnas permitidas).
4. Página: `defineOptions({ layout: { breadcrumbs: [...] } })`, `<Head :title>`,
   `PageHeader` con la acción principal.
5. Textos con `t()`; agregar cada cadena nueva a `apps/platform/lang/es.json`.
6. Si falta una primitiva: `node packages/js/ui/scripts/add.mjs <componente>` y agregar con
   pnpm las dependencias npm que informe.
7. Formatear: `cd apps/platform && node_modules/.bin/vp fmt <archivos>`.

## Archivos

`apps/platform/resources/js/components/kit/*` (compuestos), `packages/js/ui/src/components/*`
(primitivas), `apps/platform/resources/js/pages/ui-kit/*` (catálogo),
`apps/platform/resources/js/lib/{notify,charts,icons}.ts`,
`apps/platform/resources/js/composables/useTableQuery.ts`, `apps/platform/app/Support/TableSort.php`,
`apps/platform/resources/css/app.css` (tokens `--chart-1..8`, `--success`, `--warning`, `--info`).

## Comprobaciones

```bash
cd apps/platform && php artisan test --filter=<Prueba>
cd ../.. && pnpm check && pnpm types:check && pnpm build
pnpm test:e2e -- tests/e2e/<pantalla>.spec.ts
```

Revisa la pantalla a 360 px y 1440 px, en tema claro y oscuro, y solo con teclado.

## Errores frecuentes

| Síntoma | Acción |
| --- | --- |
| Botón de ícono sin nombre accesible | usa `IconButton` (exige `label`) o agrega `aria-label` |
| Errores del servidor no aparecen en el campo | pasa `:error="form.errors.campo"` a `FormField` y `v-bind="field"` al control |
| El wizard no vuelve al paso con error | declara `fields` en cada paso y pasa `:errors="form.errors"` |
| La tabla del servidor no ordena | agrega la columna a `SORTABLE` y usa `TableSort::apply` en el controlador |
| Colores de series repetidos o de estado | usa `--chart-1..8` en orden; no uses `--success`/`--warning` para series |
| Texto en inglés en la interfaz en español | falta la clave en `lang/es.json` |
| Se muestra una acción que el usuario no puede ejecutar | condiciona con `can.*`; el backend igual la rechaza |
