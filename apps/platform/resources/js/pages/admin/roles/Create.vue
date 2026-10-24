<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { RoleFormData } from '@/components/admin/RoleForm.vue';
import RoleForm from '@/components/admin/RoleForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { create, index, store } from '@/routes/admin/roles';
import type { PermissionGroup } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Roles & permissions', href: index() },
            { title: 'New role', href: create() },
        ],
    },
});

defineProps<{
    permissionGroups: PermissionGroup[];
    grantable: string[];
}>();

const form = useForm<RoleFormData>({ name: '', permissions: [] });

const submit = () => form.post(store().url);
</script>

<template>
    <Head :title="t('New role')" />

    <form
        class="flex max-w-5xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="t('New role')"
            :description="t('Choose the permissions granted by the role')"
        />

        <RoleForm
            :form="form"
            :permission-groups="permissionGroups"
            :grantable="grantable"
        />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Create role') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
