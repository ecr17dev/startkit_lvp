<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { UserFormData } from '@/components/admin/UserForm.vue';
import UserForm from '@/components/admin/UserForm.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';
import { t } from '@/lib/i18n';
import { create, index, store } from '@/routes/admin/users';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: 'New user', href: create() },
        ],
    },
});

const props = defineProps<{
    roles: string[];
    defaultRole: string | null;
    passwordsEnabled: boolean;
}>();

const form = useForm<UserFormData>({
    name: '',
    email: '',
    password: '',
    email_verified: false,
    locale: null,
    roles:
        props.defaultRole && props.roles.includes(props.defaultRole)
            ? [props.defaultRole]
            : [],
});

const submit = () =>
    form
        .transform((data) => ({
            ...data,
            password:
                props.passwordsEnabled && data.password
                    ? data.password
                    : undefined,
        }))
        .post(store().url);
</script>

<template>
    <Head :title="t('New user')" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader
            :title="t('New user')"
            :description="
                t(
                    'Create an account and assign its roles. To let people choose their own name, send an invitation instead.',
                )
            "
        />

        <UserForm
            :form="form"
            :roles="roles"
            :passwords-enabled="passwordsEnabled"
        />

        <div class="flex items-center gap-2">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                {{ t('Create user') }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">{{ t('Cancel') }}</Link>
            </Button>
        </div>
    </form>
</template>
