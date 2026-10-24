<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import type { UserFormData } from '@/components/admin/UserForm.vue';
import UserForm from '@/components/admin/UserForm.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, t } from '@/lib/i18n';
import { destroy, edit, index, update } from '@/routes/admin/users';
import type { AdminUser } from '@/types';

const props = defineProps<{
    user: AdminUser;
    roles: string[];
    canDelete: boolean;
}>();

defineOptions({
    layout: (props: { user: AdminUser }) => ({
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: props.user.name, href: edit(props.user.id) },
        ],
    }),
});

const form = useForm<UserFormData>({
    name: props.user.name,
    email: props.user.email,
    password: '',
    is_active: props.user.is_active,
    email_verified: props.user.email_verified_at !== null,
    locale: props.user.locale,
    roles: props.user.roles ?? [],
});

const submit = () =>
    form.put(update(props.user.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('password'),
    });

const remove = () => router.delete(destroy(props.user.id).url);
</script>

<template>
    <Head :title="user.name" />

    <form
        class="flex max-w-3xl flex-col gap-6 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <PageHeader :title="user.name" :description="user.email">
            <Badge v-if="user.two_factor_enabled" variant="secondary"
                >2FA</Badge
            >
        </PageHeader>

        <p class="text-xs text-muted-foreground">
            {{ t('Created') }} {{ formatDate(user.created_at) }} ·
            {{ t('Last login') }}
            {{
                user.last_login_at ? formatDate(user.last_login_at) : t('Never')
            }}
        </p>

        <UserForm :form="form" :roles="roles" editing />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    {{ t('Save changes') }}
                </Button>
                <Button variant="ghost" as-child>
                    <Link :href="index()">{{ t('Cancel') }}</Link>
                </Button>
            </div>

            <ConfirmDialog
                v-if="canDelete"
                :title="t('Delete user')"
                :description="
                    t(
                        'Are you sure you want to delete :name? This cannot be undone.',
                        {
                            name: user.name,
                        },
                    )
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
                    {{ t('Delete user') }}
                </Button>
            </ConfirmDialog>
        </div>
    </form>
</template>
