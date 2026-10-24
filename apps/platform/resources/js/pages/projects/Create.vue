<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import ProjectForm from '@/modules/projects/ProjectForm.vue';
import type { ProjectFormData, ProjectOptions } from '@/modules/projects/types';
import { create, index, store } from '@/routes/projects';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Projects', href: index() },
            { title: 'New project', href: create() },
        ],
    },
});

defineProps<{
    options: ProjectOptions;
}>();

const form = useForm<ProjectFormData>({
    name: '',
    code: '',
    description: '',
    status: 'planned',
    starts_on: '',
    due_on: '',
    budget: '',
    is_billable: false,
});

const submit = () => form.post(store().url);
</script>

<template>
    <Head :title="t('New project')" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader :title="t('New project')" />

        <ProjectForm :form="form" :options="options" />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Create project') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
