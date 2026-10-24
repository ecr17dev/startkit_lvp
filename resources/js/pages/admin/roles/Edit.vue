<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { RoleFormData } from '@/components/admin/RoleForm.vue';
import RoleForm from '@/components/admin/RoleForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit, index, update } from '@/routes/admin/roles';
import type { AdminRole, PermissionGroup } from '@/types';

const props = defineProps<{
    role: AdminRole;
    permissionGroups: PermissionGroup[];
    isProtected: boolean;
}>();

defineOptions({
    layout: (props: { role: AdminRole }) => ({
        breadcrumbs: [
            { title: 'Roles & permissions', href: index() },
            { title: props.role.name, href: edit(props.role.id) },
        ],
    }),
});

const form = useForm<RoleFormData>({
    name: props.role.name,
    permissions: props.role.permissions ?? [],
});

const submit = () =>
    form.put(update(props.role.id).url, { preserveScroll: true });
</script>

<template>
    <Head :title="role.name" />

    <form
        class="flex max-w-5xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="t('Edit role :name', { name: role.name })"
            :description="t('Choose the permissions granted by the role')"
        />

        <RoleForm
            :form="form"
            :permission-groups="permissionGroups"
            :name-locked="isProtected"
        />

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
