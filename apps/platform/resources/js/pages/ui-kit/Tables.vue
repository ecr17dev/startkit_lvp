<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Archive, Copy, Download, Eye, Pencil, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ActionsMenu from '@/components/kit/ActionsMenu.vue';
import type { DataTableColumn } from '@/components/kit/data-table';
import DataTable from '@/components/kit/DataTable.vue';
import Example from '@/components/ui-kit/Example.vue';
import { Avatar, AvatarFallback } from '@starter/ui/components/avatar';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Progress } from '@starter/ui/components/progress';
import { useInitials } from '@/composables/useInitials';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import { notify } from '@/lib/notify';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Tables', href: show('tables') },
        ],
    },
});

type Customer = {
    id: number;
    name: string;
    email: string;
    plan: 'starter' | 'team' | 'enterprise';
    status: 'active' | 'trial' | 'paused';
    mrr: number;
    seats: number;
    usage: number;
    created_at: string;
};

const names = [
    'Acme Ltda.',
    'Andes Foods',
    'Bío Bío Labs',
    'Cordillera Tech',
    'Delta Logística',
    'Estudio Norte',
    'Fundación Sur',
    'Grupo Pacífico',
    'Hidro Patagonia',
    'Inmobiliaria Sol',
    'Juntos Retail',
    'Kawésqar Tours',
    'Lago Verde',
    'Minera Atacama',
    'Nativa Cosmética',
    'Oasis Clínica',
    'Puerto Café',
    'Quillay Seguros',
    'Río Claro Energía',
    'Salar Analytics',
    'Tierra Fértil',
    'Urbano Diseño',
    'Viña del Valle',
    'Zona Franca IT',
];
const plans = ['starter', 'team', 'enterprise'] as const;
const statuses = ['active', 'active', 'trial', 'paused'] as const;

const customers = ref<Customer[]>(
    names.map((name, position) => ({
        id: position + 1,
        name,
        email: `contacto@${name
            .toLowerCase()
            .normalize('NFD')
            .replace(/[^a-z]/g, '')
            .slice(0, 12)}.cl`,
        plan: plans[position % 3],
        status: statuses[(position * 7) % 4],
        mrr: [49, 290, 1490][position % 3] * (1 + (position % 4)),
        seats: [3, 18, 120][position % 3] + position,
        usage: (position * 37) % 100,
        created_at: new Date(
            Date.UTC(2026, position % 10, 1 + position),
        ).toISOString(),
    })),
);

const planLabels = {
    starter: 'Starter',
    team: 'Team',
    enterprise: 'Enterprise',
};
const statusLabels = {
    active: t('Active'),
    trial: t('Trial'),
    paused: t('Paused'),
};

const columns: DataTableColumn<Customer>[] = [
    { key: 'name', label: t('Customer'), sortable: true, card: 'title' },
    {
        key: 'email',
        label: t('Email'),
        hideBelow: 'lg',
        card: 'subtitle',
    },
    {
        key: 'plan',
        label: t('Plan'),
        sortable: true,
        format: (value) => planLabels[value as Customer['plan']],
    },
    {
        key: 'status',
        label: t('Status'),
        sortable: true,
        card: 'badge',
        format: (value) => statusLabels[value as Customer['status']],
    },
    {
        key: 'mrr',
        label: t('MRR'),
        sortable: true,
        align: 'right',
        format: (value) => formatMoney(Number(value)),
    },
    {
        key: 'seats',
        label: t('Seats'),
        sortable: true,
        align: 'right',
        hideBelow: 'md',
        format: (value) => formatNumber(Number(value)),
    },
    { key: 'usage', label: t('Usage'), sortable: true, hideBelow: 'xl' },
    {
        key: 'created_at',
        label: t('Customer since'),
        sortable: true,
        hidden: true,
        format: (value) =>
            formatDate(String(value), { dateStyle: 'medium', timeZone: 'UTC' }),
    },
];

const selected = ref<(string | number)[]>([]);
const { getInitials } = useInitials();

const statusVariant = (status: Customer['status']) =>
    status === 'active'
        ? 'default'
        : status === 'trial'
          ? 'secondary'
          : 'outline';

const actions = (customer: Customer) => [
    { label: t('View'), icon: Eye, onSelect: () => notify.info(customer.name) },
    { label: t('Edit'), icon: Pencil, onSelect: () => notify.info(t('Edit')) },
    {
        label: t('Duplicate'),
        icon: Copy,
        onSelect: () => notify.success(t('Duplicated (demo).')),
    },
    {
        label: t('Delete'),
        icon: Trash2,
        destructive: true,
        separated: true,
        confirm: {
            title: t('Delete customer'),
            description: t(
                'The customer will be deleted. This cannot be undone.',
            ),
        },
        onSelect: () => {
            customers.value = customers.value.filter(
                (item) => item.id !== customer.id,
            );
            notify.success(t('Customer deleted (demo).'));
        },
    },
];

function archiveSelected(clear: () => void) {
    notify.success(
        t(':count customers archived (demo).', {
            count: selected.value.length,
        }),
    );
    clear();
}

const code = `
<script setup lang="ts">
import { ActionsMenu, DataTable, type DataTableColumn } from '@/components/kit';

const columns: DataTableColumn<Customer>[] = [
    { key: 'name', label: t('Customer'), sortable: true, card: 'title' },
    { key: 'email', label: t('Email'), hideBelow: 'lg', card: 'subtitle' },
    { key: 'status', label: t('Status'), card: 'badge' },
    { key: 'mrr', label: t('MRR'), align: 'right', sortable: true,
      format: (value) => formatMoney(Number(value)) },
    { key: 'created_at', label: t('Customer since'), hidden: true },
];
const selected = ref([]);
<\u002Fscript>

<template>
    <DataTable :rows="customers" :columns="columns" selectable v-model:selected="selected">
        <template #cell-status="{ row }"><Badge>{{ statusLabels[row.status] }}</Badge></template>
        <template #actions="{ row }"><ActionsMenu :items="actions(row)" /></template>
        <template #bulk="{ clear }"><Button size="sm" @click="archive(clear)">{{ t('Archive') }}</Button></template>
    </DataTable>
</template>
`;

const serverCode = `
// Controller: the database filters, sorts and paginates
$filters = $request->validate([
    'search' => ['nullable', 'string', 'max:100'],
    ...TableSort::rules(['name', 'created_at']),
]);
$query = Project::query()->when($filters['search'] ?? null, fn ($q, $s) => $q->whereLike('name', "%{$s}%"));
TableSort::apply($query, $filters, ['name', 'created_at'], default: 'created_at');

return Inertia::render('projects/Index', [
    'projects' => ProjectResource::collection($query->paginate(15)->withQueryString()),
    'filters' => (object) $filters,
]);

// Page
const query = useTableQuery(index().url, props.filters);

<DataTable server :rows="projects.data" :meta="projects.meta" :columns="columns"
    v-model:search="query.search" v-model:sort="query.sort" />
`;
</script>

<template>
    <Head :title="t('Tables')" />

    <Example
        :title="t('Data table')"
        :description="
            t(
                'Search, sort by column, choose columns, select rows for bulk actions. On phones it becomes cards; the switch forces either view.',
            )
        "
        :code="code"
    >
        <DataTable
            v-model:selected="selected"
            :rows="customers"
            :columns="columns"
            :page-size="8"
            :label="t('Customers')"
            :search-placeholder="t('Search customers')"
            selectable
        >
            <template #toolbar>
                <Button variant="outline" size="sm">
                    <Download class="size-4" /> {{ t('Export') }}
                </Button>
            </template>
            <template #cell-name="{ row }">
                <div class="flex items-center gap-2">
                    <Avatar class="size-7">
                        <AvatarFallback class="text-[11px]">{{
                            getInitials(row.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <span class="font-medium">{{ row.name }}</span>
                </div>
            </template>
            <template #cell-status="{ row }">
                <Badge :variant="statusVariant(row.status)">{{
                    statusLabels[row.status]
                }}</Badge>
            </template>
            <template #cell-usage="{ row }">
                <div class="flex items-center gap-2">
                    <Progress :model-value="row.usage" class="h-1.5 w-20" />
                    <span class="text-xs text-muted-foreground tabular-nums"
                        >{{ row.usage }} %</span
                    >
                </div>
            </template>
            <template #actions="{ row }">
                <ActionsMenu :items="actions(row)" />
            </template>
            <template #bulk="{ clear }">
                <Button
                    size="sm"
                    variant="outline"
                    @click="archiveSelected(clear)"
                >
                    <Archive class="size-4" /> {{ t('Archive') }}
                </Button>
            </template>
        </DataTable>
    </Example>

    <Example
        :title="t('Card mode')"
        :description="
            t(
                'With mode set to cards, the same columns become cards: title, subtitle, badge and labelled fields.',
            )
        "
    >
        <DataTable
            :rows="customers.slice(0, 6)"
            :columns="columns"
            mode="cards"
            :mode-toggle="false"
            :searchable="false"
            :page-size="0"
        >
            <template #cell-status="{ row }">
                <Badge :variant="statusVariant(row.status)">{{
                    statusLabels[row.status]
                }}</Badge>
            </template>
            <template #cell-usage="{ row }">
                <Progress :model-value="row.usage" class="ml-auto h-1.5 w-20" />
            </template>
            <template #actions="{ row }">
                <Button size="sm" variant="ghost">{{ t('Details') }}</Button>
                <ActionsMenu :items="actions(row)" />
            </template>
        </DataTable>
    </Example>

    <Example
        :title="t('Server data')"
        :description="
            t(
                'For large lists, the database filters, sorts and paginates: useTableQuery keeps the state in the URL and TableSort only allows the listed columns.',
            )
        "
        :code="serverCode"
    >
        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'The Projects module and every module created with starter:make-module use this mode.',
                )
            }}
        </p>
    </Example>
</template>
