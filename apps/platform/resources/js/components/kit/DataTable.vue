<script setup lang="ts" generic="T extends object">
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    ChevronLeft,
    ChevronRight,
    Columns3,
    LayoutGrid,
    Rows3,
    Search,
    X,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Card } from '@starter/ui/components/card';
import { Checkbox } from '@starter/ui/components/checkbox';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
import { Input } from '@starter/ui/components/input';
import { Skeleton } from '@starter/ui/components/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import {
    ToggleGroup,
    ToggleGroupItem,
} from '@starter/ui/components/toggle-group';
import { currentLocale, t } from '@/lib/i18n';
import type { PaginationMeta } from '@/types';
import type {
    DataTableColumn,
    DataTableMode,
    DataTableSort,
} from './data-table';
import { compareValues, readPath } from './data-table';

/**
 * Table that turns into cards on phones (mode "auto"), or on demand with
 * the table/cards switch. Search, sorting, column visibility, selection
 * and pagination run in the browser; with `server` the rows arrive
 * filtered, sorted and paginated by Laravel (pass `meta` and listen to
 * v-model:search / v-model:sort, see useTableQuery).
 *
 * <DataTable :rows="users" :columns="columns" selectable v-model:selected="ids">
 *     <template #cell-status="{ row }"><Badge>{{ row.status }}</Badge></template>
 *     <template #actions="{ row }"><ActionsMenu :items="actions(row)" /></template>
 * </DataTable>
 */
const props = withDefaults(
    defineProps<{
        rows: T[];
        columns: DataTableColumn<T>[];
        /** Property (or function) with a unique id of each row. */
        rowKey?: string | ((row: T) => string | number);
        /** Rows already filtered, sorted and paginated by the server. */
        server?: boolean;
        /** Laravel pagination (resource collections) in server mode. */
        meta?: Pick<
            PaginationMeta,
            'links' | 'from' | 'to' | 'total' | 'last_page'
        >;
        searchable?: boolean;
        searchPlaceholder?: string;
        /** Rows per page in browser mode (0 = all). */
        pageSize?: number;
        selectable?: boolean;
        columnToggle?: boolean;
        modeToggle?: boolean;
        loading?: boolean;
        emptyText?: string;
        /** Accessible name of the table. */
        label?: string;
    }>(),
    {
        rowKey: 'id',
        server: false,
        meta: undefined,
        searchable: true,
        searchPlaceholder: '',
        pageSize: 10,
        selectable: false,
        columnToggle: true,
        modeToggle: true,
        loading: false,
        emptyText: '',
        label: '',
    },
);

const mode = defineModel<DataTableMode>('mode', { default: 'auto' });
const search = defineModel<string>('search', { default: '' });
const sort = defineModel<DataTableSort | null>('sort', { default: null });
const selected = defineModel<(string | number)[]>('selected', {
    default: () => [],
});

const slots = defineSlots<
    {
        [key: `cell-${string}`]: (props: { row: T; value: unknown }) => unknown;
    } & {
        actions?: (props: { row: T }) => unknown;
        toolbar?: () => unknown;
        bulk?: (props: {
            selected: (string | number)[];
            clear: () => void;
        }) => unknown;
        empty?: () => unknown;
        card?: (props: { row: T }) => unknown;
    }
>();

const wide = useMediaQuery('(min-width: 768px)');
const view = computed(() =>
    mode.value === 'auto' ? (wide.value ? 'table' : 'cards') : mode.value,
);

const hidden = ref(
    new Set(
        props.columns
            .filter((column) => column.hidden)
            .map((column) => column.key),
    ),
);
const visible = computed(() =>
    props.columns.filter((column) => !hidden.value.has(column.key)),
);

function toggleColumn(key: string, show: boolean) {
    const next = new Set(hidden.value);

    if (show) {
        next.delete(key);
    } else {
        next.add(key);
    }

    hidden.value = next;
}

const keyOf = (row: T): string | number =>
    typeof props.rowKey === 'function'
        ? props.rowKey(row)
        : (readPath(row, props.rowKey) as string | number);

const raw = (column: DataTableColumn<T>, row: T) =>
    column.value ? column.value(row) : readPath(row, column.key);

function display(column: DataTableColumn<T>, row: T): string {
    const value = raw(column, row);

    if (column.format) {
        return column.format(value, row);
    }

    if (typeof value === 'boolean') {
        return value ? t('Yes') : t('No');
    }

    return value === null || value === undefined || value === ''
        ? '—'
        : String(value);
}

// Browser mode: search, sort and paginate the rows here...
const filtered = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();

    if (props.server || !query) {
        return props.rows;
    }

    return props.rows.filter((row) =>
        props.columns.some((column) =>
            display(column, row).toLocaleLowerCase().includes(query),
        ),
    );
});

const sorted = computed(() => {
    const current = sort.value;
    const column = props.columns.find((item) => item.key === current?.key);

    if (props.server || !current || !column) {
        return filtered.value;
    }

    const factor = current.direction === 'asc' ? 1 : -1;
    const locale = currentLocale();

    return [...filtered.value].sort(
        (a, b) =>
            factor * compareValues(raw(column, a), raw(column, b), locale),
    );
});

const page = ref(1);
const pages = computed(() =>
    props.server || props.pageSize <= 0
        ? 1
        : Math.max(1, Math.ceil(sorted.value.length / props.pageSize)),
);
const shown = computed(() =>
    props.server || props.pageSize <= 0
        ? sorted.value
        : sorted.value.slice(
              (page.value - 1) * props.pageSize,
              page.value * props.pageSize,
          ),
);

watch([search, sort], () => (page.value = 1));
watch(pages, (count) => (page.value = Math.min(page.value, count)));

function toggleSort(column: DataTableColumn<T>) {
    if (!column.sortable) {
        return;
    }

    if (sort.value?.key !== column.key) {
        sort.value = { key: column.key, direction: 'asc' };
    } else if (sort.value.direction === 'asc') {
        sort.value = { key: column.key, direction: 'desc' };
    } else {
        sort.value = null;
    }
}

const ariaSort = (column: DataTableColumn<T>) =>
    sort.value?.key === column.key
        ? sort.value.direction === 'asc'
            ? 'ascending'
            : 'descending'
        : column.sortable
          ? 'none'
          : undefined;

// Selection (keys of the rows)...
const isSelected = (row: T) => selected.value.includes(keyOf(row));

function select(row: T, checked: boolean) {
    const key = keyOf(row);
    selected.value = checked
        ? [...new Set([...selected.value, key])]
        : selected.value.filter((item) => item !== key);
}

const pageState = computed<boolean | 'indeterminate'>(() => {
    const count = shown.value.filter(isSelected).length;

    return count === 0
        ? false
        : count === shown.value.length
          ? true
          : 'indeterminate';
});

function selectPage(checked: boolean | 'indeterminate') {
    const keys = shown.value.map(keyOf);
    selected.value =
        checked === true
            ? [...new Set([...selected.value, ...keys])]
            : selected.value.filter((key) => !keys.includes(key));
}

const clear = () => (selected.value = []);

const hideClasses = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
} as const;

const cellClass = (column: DataTableColumn<T>) => [
    column.hideBelow && hideClasses[column.hideBelow],
    column.align === 'right' && 'text-right',
    column.align === 'center' && 'text-center',
    column.class,
];

// Roles of the columns in card mode...
const titleColumn = computed(
    () =>
        visible.value.find((column) => column.card === 'title') ??
        visible.value.find((column) => !column.card || column.card === 'field'),
);
const cardColumns = (role: 'subtitle' | 'badge' | 'field') =>
    visible.value.filter(
        (column) =>
            column !== titleColumn.value && (column.card ?? 'field') === role,
    );

const colspan = computed(
    () =>
        visible.value.length +
        (props.selectable ? 1 : 0) +
        (hasActions.value ? 1 : 0),
);
const hasActions = computed(() => Boolean(slots.actions));

const from = computed(() =>
    sorted.value.length === 0 ? 0 : (page.value - 1) * props.pageSize + 1,
);
const to = computed(() =>
    Math.min(page.value * props.pageSize, sorted.value.length),
);
</script>

<template>
    <div class="grid gap-3" data-test="data-table">
        <div
            v-if="searchable || $slots.toolbar || columnToggle || modeToggle"
            class="flex flex-col gap-2 sm:flex-row sm:items-center"
        >
            <div v-if="searchable" class="relative flex-1 sm:max-w-xs">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    :placeholder="searchPlaceholder || t('Search')"
                    :aria-label="searchPlaceholder || t('Search')"
                />
            </div>
            <div class="flex flex-1 flex-wrap items-center gap-2">
                <slot name="toolbar" />
            </div>
            <div class="flex items-center gap-2">
                <DropdownMenu v-if="columnToggle && view === 'table'">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm">
                            <Columns3 class="size-4" />
                            {{ t('Columns') }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuLabel>{{
                            t('Visible columns')
                        }}</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuCheckboxItem
                            v-for="column in columns"
                            :key="column.key"
                            :model-value="!hidden.has(column.key)"
                            @update:model-value="
                                (value) => toggleColumn(column.key, !!value)
                            "
                            @select.prevent
                        >
                            {{ column.label }}
                        </DropdownMenuCheckboxItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <ToggleGroup
                    v-if="modeToggle"
                    type="single"
                    variant="outline"
                    size="sm"
                    :model-value="view"
                    :aria-label="t('View')"
                    @update:model-value="
                        (value) => value && (mode = value as DataTableMode)
                    "
                >
                    <ToggleGroupItem value="table" :aria-label="t('Table')">
                        <Rows3 class="size-4" />
                    </ToggleGroupItem>
                    <ToggleGroupItem value="cards" :aria-label="t('Cards')">
                        <LayoutGrid class="size-4" />
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>
        </div>

        <div
            v-if="selectable && selected.length"
            class="flex flex-wrap items-center gap-2 rounded-md border bg-muted/50 px-3 py-2 text-sm"
            role="status"
        >
            <span class="font-medium">{{
                t(':count selected', { count: selected.length })
            }}</span>
            <slot name="bulk" :selected="selected" :clear="clear" />
            <Button variant="ghost" size="sm" class="ml-auto" @click="clear">
                <X class="size-4" />
                {{ t('Clear selection') }}
            </Button>
        </div>

        <!-- Table mode -->
        <div v-if="view === 'table'" class="rounded-lg border">
            <Table :aria-label="label || undefined">
                <TableHeader>
                    <TableRow>
                        <TableHead v-if="selectable" class="w-10">
                            <Checkbox
                                :model-value="pageState"
                                :aria-label="t('Select page')"
                                @update:model-value="selectPage"
                            />
                        </TableHead>
                        <TableHead
                            v-for="column in visible"
                            :key="column.key"
                            :class="cellClass(column)"
                            :aria-sort="ariaSort(column)"
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="-mx-2 inline-flex items-center gap-1 rounded-md px-2 py-1 hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <ArrowUp
                                    v-if="
                                        sort?.key === column.key &&
                                        sort.direction === 'asc'
                                    "
                                    class="size-3.5"
                                />
                                <ArrowDown
                                    v-else-if="sort?.key === column.key"
                                    class="size-3.5"
                                />
                                <ChevronsUpDown
                                    v-else
                                    class="size-3.5 text-muted-foreground/60"
                                />
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </TableHead>
                        <TableHead v-if="hasActions" class="w-16 text-right">
                            <span class="sr-only">{{ t('Actions') }}</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-if="loading">
                        <TableRow v-for="index in 5" :key="index">
                            <TableCell :colspan="colspan">
                                <Skeleton class="h-5 w-full" />
                            </TableCell>
                        </TableRow>
                    </template>
                    <template v-else>
                        <TableRow
                            v-for="row in shown"
                            :key="keyOf(row)"
                            data-test="data-table-row"
                            :data-state="
                                isSelected(row) ? 'selected' : undefined
                            "
                        >
                            <TableCell v-if="selectable">
                                <Checkbox
                                    :model-value="isSelected(row)"
                                    :aria-label="t('Select row')"
                                    @update:model-value="
                                        (value) => select(row, value === true)
                                    "
                                />
                            </TableCell>
                            <TableCell
                                v-for="column in visible"
                                :key="column.key"
                                :class="cellClass(column)"
                            >
                                <slot
                                    v-if="$slots[`cell-${column.key}`]"
                                    :name="`cell-${column.key}`"
                                    :row="row"
                                    :value="raw(column, row)"
                                />
                                <template v-else>{{
                                    display(column, row)
                                }}</template>
                            </TableCell>
                            <TableCell v-if="hasActions" class="text-right">
                                <slot name="actions" :row="row" />
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="!shown.length" :colspan="colspan">
                            <slot name="empty">
                                {{ emptyText || t('No results.') }}
                            </slot>
                        </TableEmpty>
                    </template>
                </TableBody>
            </Table>
        </div>

        <!-- Card mode -->
        <template v-else>
            <div
                v-if="loading"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
                <Skeleton v-for="index in 4" :key="index" class="h-32" />
            </div>
            <div
                v-else-if="shown.length"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                :aria-label="label || undefined"
                role="list"
            >
                <div
                    v-for="row in shown"
                    :key="keyOf(row)"
                    role="listitem"
                    data-test="data-table-row"
                >
                    <slot name="card" :row="row">
                        <Card
                            class="h-full gap-3 py-4 transition-colors"
                            :class="
                                isSelected(row) && 'border-primary bg-primary/5'
                            "
                        >
                            <div class="flex items-start gap-3 px-4">
                                <Checkbox
                                    v-if="selectable"
                                    class="mt-0.5"
                                    :model-value="isSelected(row)"
                                    :aria-label="t('Select row')"
                                    @update:model-value="
                                        (value) => select(row, value === true)
                                    "
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        v-if="titleColumn"
                                        class="truncate font-medium"
                                    >
                                        <slot
                                            v-if="
                                                $slots[
                                                    `cell-${titleColumn.key}`
                                                ]
                                            "
                                            :name="`cell-${titleColumn.key}`"
                                            :row="row"
                                            :value="raw(titleColumn, row)"
                                        />
                                        <template v-else>{{
                                            display(titleColumn, row)
                                        }}</template>
                                    </div>
                                    <p
                                        v-for="column in cardColumns(
                                            'subtitle',
                                        )"
                                        :key="column.key"
                                        class="truncate text-sm text-muted-foreground"
                                    >
                                        <slot
                                            v-if="$slots[`cell-${column.key}`]"
                                            :name="`cell-${column.key}`"
                                            :row="row"
                                            :value="raw(column, row)"
                                        />
                                        <template v-else>{{
                                            display(column, row)
                                        }}</template>
                                    </p>
                                </div>
                                <div
                                    v-for="column in cardColumns('badge')"
                                    :key="column.key"
                                    class="shrink-0"
                                >
                                    <slot
                                        v-if="$slots[`cell-${column.key}`]"
                                        :name="`cell-${column.key}`"
                                        :row="row"
                                        :value="raw(column, row)"
                                    />
                                    <Badge v-else variant="secondary">{{
                                        display(column, row)
                                    }}</Badge>
                                </div>
                            </div>
                            <dl
                                v-if="cardColumns('field').length"
                                class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 px-4 text-sm"
                            >
                                <template
                                    v-for="column in cardColumns('field')"
                                    :key="column.key"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ column.label }}
                                    </dt>
                                    <dd class="min-w-0 truncate text-right">
                                        <slot
                                            v-if="$slots[`cell-${column.key}`]"
                                            :name="`cell-${column.key}`"
                                            :row="row"
                                            :value="raw(column, row)"
                                        />
                                        <template v-else>{{
                                            display(column, row)
                                        }}</template>
                                    </dd>
                                </template>
                            </dl>
                            <div
                                v-if="hasActions"
                                class="mt-auto flex justify-end gap-1 border-t px-4 pt-3"
                            >
                                <slot name="actions" :row="row" />
                            </div>
                        </Card>
                    </slot>
                </div>
            </div>
            <p
                v-else
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                <slot name="empty">
                    {{ emptyText || t('No results.') }}
                </slot>
            </p>
        </template>

        <!-- Pagination -->
        <Pagination v-if="server && meta" :meta="meta" />
        <div
            v-else-if="!server && pageSize > 0 && sorted.length > 0"
            class="flex flex-col items-center justify-between gap-3 text-sm text-muted-foreground sm:flex-row"
        >
            <p>
                {{
                    t('Showing :from to :to of :total results', {
                        from,
                        to,
                        total: sorted.length,
                    })
                }}
            </p>
            <div v-if="pages > 1" class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="page === 1"
                    :aria-label="t('Previous')"
                    @click="page--"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="tabular-nums">{{
                    t('Page :page of :pages', { page, pages })
                }}</span>
                <Button
                    variant="outline"
                    size="icon-sm"
                    :disabled="page === pages"
                    :aria-label="t('Next')"
                    @click="page++"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
