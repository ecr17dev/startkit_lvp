<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Ban, CircleCheck, LogOut, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import ActivityList from '@/components/admin/ActivityList.vue';
import type { UserFormData } from '@/components/admin/UserForm.vue';
import UserForm from '@/components/admin/UserForm.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SessionList from '@/components/SessionList.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Spinner } from '@starter/ui/components/spinner';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { formatDate, t } from '@/lib/i18n';
import users from '@/routes/admin/users';
import type { AdminUser, AuditEvent, BrowserSession } from '@/types';

const props = defineProps<{
    user: AdminUser;
    roles: string[];
    permissions: string[];
    sessions: BrowserSession[];
    tokens: number;
    activity: AuditEvent[] | null;
    passwordsEnabled: boolean;
    isLastOwner: boolean;
    can: {
        update: boolean;
        assignRoles: boolean;
        disable: boolean;
        delete: boolean;
        manageSessions: boolean;
    };
}>();

defineOptions({
    layout: (props: { user: AdminUser }) => ({
        breadcrumbs: [
            { title: 'Users', href: users.index() },
            {
                title: props.user.name || props.user.email,
                href: users.edit(props.user.id),
            },
        ],
    }),
});

const form = useForm<UserFormData>({
    name: props.user.name,
    email: props.user.email,
    password: '',
    email_verified: props.user.email_verified_at !== null,
    locale: props.user.locale,
    roles: props.user.roles ?? [],
});

const statusForm = useForm({ active: !props.user.is_active });
const deleteForm = useForm({});

// Status and delete errors (e.g. last owner) are reported on the "user" key...
const userError = computed(
    () =>
        (statusForm.errors as Record<string, string | undefined>).user ??
        (deleteForm.errors as Record<string, string | undefined>).user,
);

const permissionGroups = computed(() => {
    const groups: Record<string, string[]> = {};

    for (const permission of props.permissions) {
        const group = permission.split('.')[0];
        (groups[group] ??= []).push(permission);
    }

    return groups;
});

const submit = () =>
    form
        .transform((data) => {
            const payload: Record<string, unknown> = {
                name: data.name,
                email: data.email,
                locale: data.locale,
                email_verified: data.email_verified,
            };

            if (props.can.assignRoles) {
                payload.roles = data.roles;
            }

            if (props.passwordsEnabled && data.password) {
                payload.password = data.password;
            }

            return payload;
        })
        .put(users.update(props.user.id).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('password'),
        });

const toggleStatus = () =>
    statusForm
        .transform(() => ({ active: !props.user.is_active }))
        .patch(users.status(props.user.id).url, { preserveScroll: true });

const remove = () => deleteForm.delete(users.destroy(props.user.id).url);

const revokeSession = (session: BrowserSession) =>
    router.delete(users.sessions.destroy([props.user.id, session.id]).url, {
        preserveScroll: true,
    });

const revokeAll = () =>
    router.delete(users.sessions.destroyAll(props.user.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="user.name || user.email" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <PageHeader :title="user.name || user.email" :description="user.email">
            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="user.is_owner">{{ t('Owner') }}</Badge>
                <Badge
                    :variant="user.is_active ? 'secondary' : 'destructive'"
                    >{{ user.is_active ? t('Active') : t('Disabled') }}</Badge
                >
                <Badge v-if="user.two_factor_enabled" variant="outline"
                    >2FA</Badge
                >
            </div>
        </PageHeader>

        <p class="text-xs text-muted-foreground">
            {{ t('Created') }} {{ formatDate(user.created_at) }} ·
            {{ t('Last login') }}
            {{
                user.last_login_at ? formatDate(user.last_login_at) : t('Never')
            }}
            <template v-if="user.disabled_at">
                · {{ t('Disabled') }}
                {{ formatDate(user.disabled_at) }}</template
            >
        </p>

        <Tabs default-value="profile" class="gap-6">
            <TabsList class="w-full justify-start overflow-x-auto sm:w-fit">
                <TabsTrigger value="profile">{{ t('Profile') }}</TabsTrigger>
                <TabsTrigger value="access">{{ t('Access') }}</TabsTrigger>
                <TabsTrigger v-if="can.manageSessions" value="sessions"
                    >{{ t('Sessions') }} ({{ sessions.length }})</TabsTrigger
                >
                <TabsTrigger v-if="activity" value="activity">{{
                    t('Activity')
                }}</TabsTrigger>
            </TabsList>

            <TabsContent value="profile">
                <form class="flex flex-col gap-6" @submit.prevent="submit">
                    <UserForm
                        :form="form"
                        :roles="roles"
                        editing
                        :passwords-enabled="passwordsEnabled"
                        :can-assign-roles="can.assignRoles"
                        :disabled="!can.update"
                    />

                    <div v-if="can.update" class="flex items-center gap-2">
                        <Button type="submit" :disabled="form.processing">
                            <Spinner v-if="form.processing" />
                            {{ t('Save changes') }}
                        </Button>
                        <Button variant="ghost" as-child>
                            <Link :href="users.index()">{{ t('Cancel') }}</Link>
                        </Button>
                    </div>
                </form>
            </TabsContent>

            <TabsContent value="access" class="grid gap-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{{ t('Effective permissions') }}</CardTitle>
                        <CardDescription>{{
                            t('Granted through the roles of the user.')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-3">
                        <div
                            v-for="(items, group) in permissionGroups"
                            :key="group"
                            class="flex flex-wrap items-center gap-1.5"
                        >
                            <span
                                class="w-24 text-xs font-medium text-muted-foreground uppercase"
                                >{{ group }}</span
                            >
                            <Badge
                                v-for="permission in items"
                                :key="permission"
                                variant="outline"
                                class="font-mono text-[11px]"
                                >{{ permission }}</Badge
                            >
                        </div>
                        <p
                            v-if="!permissions.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ t('This user has no permissions.') }}
                        </p>
                    </CardContent>
                </Card>

                <Card v-if="can.disable || can.delete">
                    <CardHeader>
                        <CardTitle>{{ t('Account status') }}</CardTitle>
                        <CardDescription>
                            {{
                                isLastOwner
                                    ? t(
                                          'This is the last active owner: it cannot be disabled nor deleted.',
                                      )
                                    : t(
                                          'Disabling revokes every session and API token immediately.',
                                      )
                            }}
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-wrap gap-2">
                        <ConfirmDialog
                            v-if="can.disable"
                            :title="
                                user.is_active
                                    ? t('Disable user')
                                    : t('Enable user')
                            "
                            :description="
                                user.is_active
                                    ? t(
                                          ':name will be logged out everywhere and will not be able to log in.',
                                          { name: user.name },
                                      )
                                    : t(':name will be able to log in again.', {
                                          name: user.name,
                                      })
                            "
                            :confirm-text="
                                user.is_active ? t('Disable') : t('Enable')
                            "
                            :destructive="user.is_active"
                            @confirm="toggleStatus"
                        >
                            <Button
                                variant="outline"
                                :disabled="
                                    statusForm.processing ||
                                    (user.is_active && isLastOwner)
                                "
                                data-test="toggle-user-status"
                            >
                                <component
                                    :is="user.is_active ? Ban : CircleCheck"
                                    class="size-4"
                                />
                                {{
                                    user.is_active
                                        ? t('Disable user')
                                        : t('Enable user')
                                }}
                            </Button>
                        </ConfirmDialog>

                        <ConfirmDialog
                            v-if="can.delete"
                            :title="t('Delete user')"
                            :description="
                                t(
                                    'The personal data of :name will be anonymized. This cannot be undone.',
                                    { name: user.name },
                                )
                            "
                            :confirm-text="t('Delete')"
                            @confirm="remove"
                        >
                            <Button
                                variant="outline"
                                class="text-destructive"
                                :disabled="deleteForm.processing || isLastOwner"
                                data-test="delete-user"
                            >
                                <Trash2 class="size-4" />
                                {{ t('Delete user') }}
                            </Button>
                        </ConfirmDialog>
                        <InputError class="w-full" :message="userError" />
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent
                v-if="can.manageSessions"
                value="sessions"
                class="grid gap-4"
            >
                <SessionList
                    :sessions="sessions"
                    can-revoke
                    @revoke="revokeSession"
                />
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-muted-foreground">
                        {{ t('Active API tokens: :count', { count: tokens }) }}
                    </p>
                    <ConfirmDialog
                        v-if="sessions.length"
                        :title="t('Revoke all sessions')"
                        :description="
                            t('The user will be logged out from every device.')
                        "
                        :confirm-text="t('Revoke all')"
                        @confirm="revokeAll"
                    >
                        <Button variant="outline">
                            <LogOut class="size-4" />
                            {{ t('Revoke all sessions') }}
                        </Button>
                    </ConfirmDialog>
                </div>
            </TabsContent>

            <TabsContent v-if="activity" value="activity">
                <ActivityList :events="activity" />
            </TabsContent>
        </Tabs>
    </div>
</template>
