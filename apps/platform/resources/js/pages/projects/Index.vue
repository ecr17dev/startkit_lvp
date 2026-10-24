<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import type { MenuAction } from '@/components/kit/ActionsMenu.vue';
import ActionsMenu from '@/components/kit/ActionsMenu.vue';
import type { DataTableColumn } from '@/components/kit/data-table';
import DataTable from '@/components/kit/DataTable.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { useTableQuery } from '@/composables/useTableQuery';
import { formatDate, t } from '@/lib/i18n';
import type { Project, ProjectOptions } from '@/modules/projects/types';
import { create, destroy, edit, index } from '@/routes/projects';
import type { Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: index() }],
    },
});

const props = defineProps<{
    projects: Paginated<Project>;
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        status?: string;
    };
    options: ProjectOptions;
    can: { create: boolean; update: boolean; delete: boolean };
}>();

// Search, sort and filters travel in the query string; the server applies
// them and paginates (DataTable in server mode)...
const query = useTableQuery(index().url, {
    search: props.filters.search,
    sort: props.filters.sort,
    direction: props.filters.direction,
    status: props.filters.status ?? 'all',
});

const optionLabel = (field: keyof ProjectOptions, value: string) =>
    props.options[field]?.find((option) => option.value === value)?.label ??
    value;

const columns: DataTableColumn<Project>[] = [
    { key: 'name', label: t('Name'), sortable: true, card: 'title' },
    { key: 'code', label: t('Code'), sortable: true, hideBelow: 'md' },
    {
        key: 'status',
        label: t('Status'),
        sortable: true,
        hideBelow: 'md',
        card: 'badge',
    },
    {
        key: 'starts_on',
        label: t('Start date'),
        sortable: true,
        hideBelow: 'md',
        format: (value) =>
            formatDate(value as string | null, {
                dateStyle: 'medium',
                timeZone: 'UTC',
            }),
    },
    {
        key: 'due_on',
        label: t('Due date'),
        sortable: true,
        hideBelow: 'md',
        format: (value) =>
            formatDate(value as string | null, {
                dateStyle: 'medium',
                timeZone: 'UTC',
            }),
    },
    {
        key: 'updated_at',
        label: t('Last updated'),
        sortable: true,
        hideBelow: 'lg',
        format: (value) => formatDate(value as string | null),
    },
];

const actions = (project: Project): MenuAction[] => [
    ...(props.can.update
        ? [{ label: t('Edit'), icon: Pencil, href: edit(project.id) }]
        : []),
    ...(props.can.delete
        ? [
              {
                  label: t('Delete'),
                  icon: Trash2,
                  destructive: true,
                  separated: props.can.update,
                  confirm: {
                      title: t('Delete project'),
                      description: t(
                          'The project will be deleted. This cannot be undone.',
                      ),
                      action: t('Delete'),
                  },
                  onSelect: () =>
                      router.delete(destroy(project.id).url, {
                          preserveScroll: true,
                      }),
              },
          ]
        : []),
];
</script>

<template>
    <Head :title="t('Projects')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Projects')"
            :description="t('Create and manage the projects.')"
        >
            <Button v-if="can.create" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('New project') }}
                </Link>
            </Button>
        </PageHeader>

        <DataTable
            v-model:search="query.search"
            v-model:sort="query.sort"
            :rows="projects.data"
            :columns="columns"
            :meta="projects.meta"
            :label="t('Projects')"
            :search-placeholder="t('Search projects')"
            :empty-text="t('No projects found.')"
            server
        >
            <template #toolbar>
                <Select v-model="query.filters.status">
                    <SelectTrigger class="sm:w-48" :aria-label="t('Status')">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{ t('All') }}</SelectItem>
                        <SelectItem
                            v-for="option in options.status"
                            :key="option.value"
                            :value="option.value"
                            >{{ option.label }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </template>
            <template #cell-name="{ row }">
                <Link
                    v-if="can.update"
                    :href="edit(row.id)"
                    class="font-medium hover:underline"
                    >{{ row.name || '—' }}</Link
                >
                <span v-else class="font-medium">{{ row.name || '—' }}</span>
            </template>
            <template #cell-status="{ row }">
                <Badge v-if="row.status" variant="secondary">{{
                    optionLabel('status', row.status)
                }}</Badge>
            </template>
            <template v-if="can.update || can.delete" #actions="{ row }">
                <ActionsMenu :items="actions(row)" />
            </template>
        </DataTable>
    </div>
</template>
