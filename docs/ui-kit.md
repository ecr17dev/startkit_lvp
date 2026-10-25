# Kit de UI

Piezas listas para construir pantallas rápido y de forma consistente: formularios, formulario
por pasos (wizard), tablas con modo tarjetas, gráficos, carruseles, botones, menús, barra
lateral, alertas, notificaciones (toasts), modales e íconos. Todo está escrito sobre
shadcn-vue (`new-york-v4`), Tailwind CSS 4, Inertia y las traducciones de la aplicación.

El **catálogo en vivo** está en `/ui-kit` (menú lateral → Desarrollo → Kit de UI): cada
sección muestra ejemplos funcionando y el código para copiar.

## Tres capas

| Capa | Dónde | Qué contiene | Import |
| --- | --- | --- | --- |
| Primitivas | `packages/js/ui/src/components/*` | componentes de shadcn-vue sin lógica de negocio | `@starter/ui/components/<nombre>` |
| Compuestos del kit | `apps/platform/resources/js/components/kit/*` | piezas con Inertia, `t()` y accesibilidad resueltas | `@/components/kit` |
| Catálogo | `apps/platform/resources/js/pages/ui-kit/*` | ejemplos en vivo de las dos capas anteriores | ruta `/ui-kit` |

Regla práctica: busca primero un **compuesto** del kit; si no existe, arma la pantalla con
**primitivas**; si falta una primitiva, agrégala del registro (ver más abajo). No copies
estilos a mano ni introduzcas otra librería de componentes o de gráficos.

## Catálogo y activación

`STARTER_FEATURE_UI_KIT` (por defecto activo solo con `APP_ENV=local`) habilita `/ui-kit`
para usuarios con sesión. Muestra solo datos de ejemplo y no guarda nada: el único endpoint,
`POST /ui-kit/wizard`, valida en el servidor para demostrar los errores del wizard. Con la
opción apagada todas las rutas responden 404 y la entrada del menú desaparece.

## Compuestos del kit

| Componente | Para qué | Puntos clave |
| --- | --- | --- |
| `FormField` | etiqueta + control + ayuda + error | el slot recibe `id`, `aria-invalid`, `aria-describedby`: `<FormField :label :error="form.errors.x" v-slot="field"><Input v-bind="field" /></FormField>`; `horizontal` para casillas e interruptores; `required` / `optional` |
| `DatePicker` | fecha con calendario | `v-model` es la fecha ISO (`2026-10-24`) o `null`; `min`, `max`, `clearable` |
| `SubmitButton` | enviar formularios | `:processing="form.processing"` muestra spinner y evita envíos dobles |
| `WizardForm` | formulario por pasos | `steps` con `key`, `title`, `fields`; un slot por paso (`#<key>`); `validate(step)` antes de avanzar; con `:errors="form.errors"` vuelve al primer paso con error del servidor; emite `submit` en el último paso |
| `DataTable` | tablas | búsqueda, orden, columnas visibles, selección con acciones masivas (`#bulk`), paginación; `mode` `auto` (tarjetas en teléfonos), `table` o `cards`; `server` + `meta` para datos paginados por Laravel |
| `KpiCard` | cifra principal | `delta` relativo con flecha y texto, `invert` cuando bajar es bueno, `points` para un minigráfico |
| `BarChart`, `LineChart`, `DonutChart`, `Sparkline` | gráficos | SVG/CSS sin dependencias, tooltip con puntero y teclado, leyenda desde dos series y botón **Tabla** con los datos |
| `ContentCarousel` | carruseles | `items` + slot; `item-class` decide cuántos caben por pantalla; `loop`, `autoplay` (se pausa con puntero/foco), puntos y flechas |
| `ActionsMenu` | menú "⋯" de una fila o tarjeta | lista de acciones con `href` (Inertia) u `onSelect`; `confirm` abre un diálogo antes de ejecutar |
| `CommandMenu` | paleta de comandos ⌘K / Ctrl+K | secciones con páginas (`href`) y acciones (`onSelect`) |
| `IconButton`, `CopyButton` | botones de ícono | `IconButton` exige `label` (nombre accesible y tooltip); `CopyButton` copia y confirma con un toast |
| `Modal` | ventana modal | diálogo centrado en pantallas anchas y panel inferior (drawer) en teléfonos; slots `trigger`, contenido y `footer` |
| `StatusAlert` | mensaje en la página | `tone` `info`, `success`, `warning`, `error` con ícono y título; `dismissible` |
| `EmptyState` | listas vacías | ícono, título, descripción y acción |
| `Icon` | ícono por nombre | `<Icon name="users" />` para nombres que vienen de datos (`lib/icons.ts`); en código importa el componente de `@lucide/vue` |

Utilidades relacionadas:

| Archivo | Uso |
| --- | --- |
| `lib/notify.ts` | toasts: `notify.success()`, `.error()`, `.warning()`, `.info()`, `.message()` con `action`, `.promise()` |
| `composables/useTableQuery.ts` | estado de una tabla del servidor en la URL (búsqueda, orden y filtros, con debounce) |
| `app/Support/TableSort.php` | orden seguro en el servidor: `TableSort::rules($columnas)` y `TableSort::apply($query, $filters, $columnas)` |
| `lib/charts.ts` | paleta de series, escalas y marcas de los ejes |
| `lib/icons.ts` | mapa curado de íconos Lucide por nombre |

Componentes existentes que siguen vigentes: `PageHeader`, `StatCard`, `ConfirmDialog`,
`Pagination` (enlaces de Laravel) y los gráficos de `components/charts` de los paneles de IA
y conocimiento.

## Primitivas disponibles (`@starter/ui`)

accordion, alert, alert-dialog, aspect-ratio, avatar, badge, breadcrumb, button,
button-group, calendar, card, carousel, checkbox, collapsible, combobox, command,
context-menu, dialog, drawer, dropdown-menu, empty, field, hover-card, input, input-group,
input-otp, item, kbd, label, menubar, native-select, navigation-menu, number-field,
pagination, popover, progress, radio-group, scroll-area, select, separator, sheet, sidebar,
skeleton, slider, sonner, spinner, stepper, switch, table, tabs, tags-input, textarea,
toggle, toggle-group, tooltip.

### Agregar más

```bash
node packages/js/ui/scripts/add.mjs <componente> [--force]
# o: pnpm --filter @starter/ui add:component <componente>
```

El script descarga el ítem del registro de shadcn-vue (`new-york-v4`), agrega también sus
dependencias de registro que falten, reescribe los imports al estilo relativo del paquete
(`../../lib/utils`, `../button`) e informa las dependencias npm faltantes, que se agregan con
`pnpm --filter @starter/ui add <paquete>` para que cambie el lock. `--from=<dir>` instala desde
JSON descargados (sin red). Los componentes de `@starter/ui` son código propio: puedes
ajustarlos, pero `--force` los sobrescribe.

## Recetas

### Listado con datos del servidor

```php
// Controlador: valida, filtra, ordena (solo columnas permitidas) y pagina
protected const SORTABLE = ['name', 'status', 'updated_at'];

$filters = $request->validate([
    'search' => ['nullable', 'string', 'max:100'],
    ...TableSort::rules(self::SORTABLE),
]);
$query = Invoice::query()->when($filters['search'] ?? null, fn ($q, $s) => $q->whereLike('name', "%{$s}%"));

return Inertia::render('invoices/Index', [
    'invoices' => InvoiceResource::collection(TableSort::apply($query, $filters, self::SORTABLE)->paginate(15)->withQueryString()),
    'filters' => (object) $filters,
]);
```

```vue
<script setup lang="ts">
import { ActionsMenu, DataTable, type DataTableColumn } from '@/components/kit';
import { useTableQuery } from '@/composables/useTableQuery';

const props = defineProps<{ invoices: Paginated<Invoice>; filters: Record<string, string> }>();
const query = useTableQuery(index().url, props.filters);
const columns: DataTableColumn<Invoice>[] = [
    { key: 'name', label: t('Name'), sortable: true, card: 'title' },
    { key: 'status', label: t('Status'), sortable: true, card: 'badge' },
    { key: 'total', label: t('Total'), align: 'right', format: (value) => formatMoney(Number(value)) },
];
</script>

<template>
    <DataTable server :rows="invoices.data" :meta="invoices.meta" :columns="columns"
        v-model:search="query.search" v-model:sort="query.sort">
        <template #actions="{ row }"><ActionsMenu :items="actions(row)" /></template>
    </DataTable>
</template>
```

Los módulos generados con `starter:make-module` (y el módulo **Proyectos**) ya usan este
patrón. Para listas pequeñas que ya están en el navegador, omite `server` y `meta`: la tabla
busca, ordena y pagina sola.

### Formulario

```vue
<form class="grid gap-6" @submit.prevent="form.post(store().url)">
    <FormField :label="t('Name')" :error="form.errors.name" required v-slot="field">
        <Input v-model="form.name" v-bind="field" />
    </FormField>
    <FormField :label="t('Due date')" :error="form.errors.due_on" optional v-slot="field">
        <DatePicker v-model="form.due_on" v-bind="field" />
    </FormField>
    <FormField :label="t('Billable')" horizontal v-slot="field">
        <Switch v-model="form.is_billable" v-bind="field" />
    </FormField>
    <SubmitButton :processing="form.processing">{{ t('Save') }}</SubmitButton>
</form>
```

Las reglas viven en el servidor (Form Request / `rules()` de la Action); la validación del
navegador es solo una ayuda. Para agrupar campos usa `FieldSet`, `FieldLegend` y `FieldGroup`
de `@starter/ui/components/field`; para opciones con descripción, tarjetas de opción
(`FieldLabel` + `Field` + `RadioGroupItem`, ver la sección Formularios del catálogo).

### Formulario por pasos

```vue
<WizardForm :steps="steps" :errors="form.errors" :processing="form.processing"
    :validate="validate" :submit-text="t('Create account')" @submit="form.post(store().url)">
    <template #account>...</template>
    <template #plan>...</template>
    <template #review>...</template>
</WizardForm>
```

Declara en cada paso los `fields` que contiene: si el servidor devuelve un error en uno de
ellos, el wizard vuelve a ese paso y lo marca.

### Panel con indicadores y gráficos

```vue
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <KpiCard :title="t('Revenue')" :value="formatMoney(total)" :delta="0.12"
        :delta-label="t('vs. previous month')" :icon="Coins" :points="history" />
</div>
<BarChart :data="rows" index="month" :series="[{ key: 'online', label: t('Online') }]" />
<LineChart :data="days" index="day" :series="[{ key: 'visits', label: t('Visits') }]" area />
<DonutChart :data="channels" :center-label="t('Tickets')" />
```

### Modal con formulario y acciones de fila

```vue
<Modal v-model:open="open" :title="t('Invite user')">
    <template #trigger><Button>{{ t('Invite user') }}</Button></template>
    <form id="invite" @submit.prevent="form.post(url, { onSuccess: () => (open = false) })">...</form>
    <template #footer><SubmitButton form="invite" :processing="form.processing">{{ t('Send') }}</SubmitButton></template>
</Modal>

<ActionsMenu :items="[
    { label: t('Edit'), icon: Pencil, href: edit(item.id) },
    { label: t('Delete'), icon: Trash2, destructive: true, separated: true,
      confirm: { title: t('Delete item') }, onSelect: () => remove(item) },
]" />
```

### Notificaciones

Tras un redirect, el servidor envía el toast con
`Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')])`; en el navegador,
`notify.success(t('Saved.'))`. Usa `StatusAlert` para mensajes que deben quedarse en la
página.

### Barra lateral

Cada módulo agrega su entrada con `resources/js/modules/<slug>/navigation.ts`
(`title`, `href`, `icon` y `can` con el permiso); `AppSidebar.vue` la recoge sola en
**Plataforma**. La administración se agrupa en menús desplegables (`NavGroup`: Usuarios y acceso,
Correo, Configuración, Inteligencia artificial, Conocimiento), definidos en `AppSidebar.vue` con
`group(id, título, ícono, páginas)`:

- un grupo muestra solo las páginas que el usuario puede abrir y desaparece si no puede ninguna;
- se abre solo cuando visitas una de sus páginas y recuerda en el navegador si lo dejaste abierto;
- con la barra contraída a íconos (`Ctrl/⌘+B`), cada grupo es un menú flotante a la derecha;
- en teléfonos se despliega dentro del panel lateral.

Para una página nueva de administración, agrégala al grupo que corresponda en vez de crear una
entrada suelta.

## Reglas

- **Textos**: en inglés con `t()` y traducidos en `apps/platform/lang/es.json`.
- **Permisos**: las props `can` y `usePermissions()` solo esconden lo que el usuario no puede
  hacer; el backend vuelve a autorizar siempre. Lista en `ActionsMenu` y `CommandMenu` solo lo
  permitido.
- **Accesibilidad**: todo control tiene etiqueta (FormField, `label` de `IconButton`,
  `aria-label` en buscadores); el estado nunca depende solo del color (ícono + texto en
  `StatusAlert`, flecha + texto en `KpiCard`); los gráficos tienen tooltip con teclado, tabla
  equivalente y leyenda; las tablas usan `aria-sort`.
- **Colores de gráficos**: series con `--chart-1` … `--chart-8` en ese orden fijo (paleta
  validada para daltonismo en claro y oscuro, `resources/css/app.css`); nunca los recicles: una
  novena serie se agrupa en "Otro". `--success`, `--warning` e `--info` son colores de estado,
  no de series. Un solo eje Y por gráfico.
- **Responsive**: diseña desde 360 px; `DataTable` cambia a tarjetas, `Modal` a drawer y los
  gráficos ocupan el ancho disponible. Sin desplazamiento horizontal de la página.
- **Secretos**: nunca en props, ejemplos ni datos de demostración.

## Verificación

```bash
cd apps/platform && php artisan test --filter=UiKitTest
cd ../.. && pnpm check && pnpm types:check && pnpm build
pnpm test:e2e -- tests/e2e/ui-kit.spec.ts
```

`tests/e2e/ui-kit.spec.ts` recorre el wizard (incluido el error que solo detecta el servidor),
la tabla (búsqueda, orden, selección y modo tarjetas), el modal y la paleta de comandos.
