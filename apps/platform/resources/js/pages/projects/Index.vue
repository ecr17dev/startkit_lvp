<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Pencil, Plus, Search, Trash2 } from '@lucide/vue';
import { reactive } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import { Input } from '@starter/ui/components/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
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
    filters: { search?: string; status?: string };
    options: ProjectOptions;
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
});

watchDebounced(
    filters,
    () => {
        router.get(
            index().url,
            {
                search: filters.search || undefined,
                status: filters.status === 'all' ? undefined : filters.status,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300, deep: true },
);

const optionLabel = (field: keyof ProjectOptions, value: string) =>
    props.options[field]?.find((option) => option.value === value)?.label ??
    value;

const remove = (project: Project) =>
    router.delete(destroy(project.id).url, { preserveScroll: true });
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

        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    class="pl-9"
                    :placeholder="t('Search projects')"
                    :aria-label="t('Search projects')"
                />
            </div>
            <Select v-model="filters.status">
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
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Name') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Code')
                        }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Status')
                        }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Start date')
                        }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Due date')
                        }}</TableHead>
                        <TableHead class="hidden lg:table-cell">{{
                            t('Last updated')
                        }}</TableHead>
                        <TableHead class="w-24 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="project in projects.data"
                        :key="project.id"
                    >
                        <TableCell class="font-medium">
                            <Link
                                v-if="can.update"
                                :href="edit(project.id)"
                                class="hover:underline"
                                >{{ project.name || '—' }}</Link
                            >
                            <template v-else>{{
                                project.name || '—'
                            }}</template>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{ project.code || '—' }}
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            <Badge v-if="project.status" variant="secondary">{{
                                optionLabel('status', project.status)
                            }}</Badge>
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{
                                formatDate(project.starts_on, {
                                    dateStyle: 'medium',
                                    timeZone: 'UTC',
                                })
                            }}
                        </TableCell>
                        <TableCell class="hidden md:table-cell">
                            {{
                                formatDate(project.due_on, {
                                    dateStyle: 'medium',
                                    timeZone: 'UTC',
                                })
                            }}
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground lg:table-cell"
                            >{{ formatDate(project.updated_at) }}</TableCell
                        >
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="can.update"
                                    variant="ghost"
                                    size="icon"
                                    as-child
                                >
                                    <Link
                                        :href="edit(project.id)"
                                        :aria-label="t('Edit')"
                                    >
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                                <ConfirmDialog
                                    v-if="can.delete"
                                    :title="t('Delete project')"
                                    :description="
                                        t(
                                            'The project will be deleted. This cannot be undone.',
                                        )
                                    "
                                    :confirm-text="t('Delete')"
                                    @confirm="remove(project)"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="text-destructive"
                                        :aria-label="t('Delete')"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </ConfirmDialog>
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!projects.data.length" :colspan="7">
                        {{ t('No projects found.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="projects.meta" />
    </div>
</template>
