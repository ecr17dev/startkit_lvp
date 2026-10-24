<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import { formatDate, t } from '@/lib/i18n';
import ProjectForm from '@/modules/projects/ProjectForm.vue';
import type {
    Project,
    ProjectFormData,
    ProjectOptions,
} from '@/modules/projects/types';
import { destroy, edit, index, update } from '@/routes/projects';

const props = defineProps<{
    project: Project;
    options: ProjectOptions;
    canDelete: boolean;
}>();

defineOptions({
    layout: (props: { project: Project }) => ({
        breadcrumbs: [
            { title: 'Projects', href: index() },
            {
                title: String(props.project.name ?? props.project.id),
                href: edit(props.project.id),
            },
        ],
    }),
});

const form = useForm<ProjectFormData>({
    name: props.project.name ?? '',
    code: props.project.code ?? '',
    description: props.project.description ?? '',
    status: props.project.status ?? '',
    starts_on: props.project.starts_on ?? '',
    due_on: props.project.due_on ?? '',
    budget: props.project.budget ?? '',
    is_billable: props.project.is_billable,
});

const submit = () =>
    form.put(update(props.project.id).url, { preserveScroll: true });

const remove = () => router.delete(destroy(props.project.id).url);
</script>

<template>
    <Head :title="t('Edit project')" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader :title="t('Edit project')">
            <ConfirmDialog
                v-if="canDelete"
                :title="t('Delete project')"
                :description="
                    t('The project will be deleted. This cannot be undone.')
                "
                :confirm-text="t('Delete')"
                @confirm="remove"
            >
                <Button
                    type="button"
                    variant="outline"
                    class="text-destructive"
                >
                    <Trash2 class="size-4" />
                    {{ t('Delete') }}
                </Button>
            </ConfirmDialog>
        </PageHeader>

        <ProjectForm :form="form" :options="options" />

        <p class="text-xs text-muted-foreground">
            <template v-if="project.creator"
                >{{ t('Created by') }} {{ project.creator.name }} ·
            </template>
            {{ t('Last updated') }} {{ formatDate(project.updated_at) }}
        </p>

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Save changes') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
