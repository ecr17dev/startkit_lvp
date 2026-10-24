<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock, Pencil, Plus, ShieldCheck, Trash2, Users } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { usePermissions } from '@/composables/usePermissions';
import { t } from '@/lib/i18n';
import { create, destroy, edit, index } from '@/routes/admin/roles';
import type { AdminRole } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: index() }],
    },
});

defineProps<{
    roles: AdminRole[];
    protectedRoles: string[];
    superAdminRole: string;
    totalPermissions: number;
}>();

const { can } = usePermissions();

const remove = (role: AdminRole) => router.delete(destroy(role.id).url);
</script>

<template>
    <Head :title="t('Roles & permissions')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Roles & permissions')"
            :description="
                t('Group permissions into roles and assign them to your users')
            "
        >
            <Button v-if="can('roles.create')" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    {{ t('New role') }}
                </Link>
            </Button>
        </PageHeader>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="role in roles" :key="role.id" class="justify-between">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ShieldCheck class="size-4 text-muted-foreground" />
                        {{ role.name }}
                        <Badge
                            v-if="protectedRoles.includes(role.name)"
                            variant="outline"
                            class="gap-1"
                        >
                            <Lock class="size-3" />
                            {{ t('System') }}
                        </Badge>
                    </CardTitle>
                    <CardDescription>
                        <template v-if="role.name === superAdminRole">
                            {{ t('Has every permission, always.') }}
                        </template>
                        <template v-else>
                            {{
                                t(':count of :total permissions', {
                                    count: role.permissions?.length ?? 0,
                                    total: totalPermissions,
                                })
                            }}
                        </template>
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Users class="size-4" />
                        {{
                            t(':count users', { count: role.users_count ?? 0 })
                        }}
                    </div>
                </CardContent>
                <CardFooter class="justify-end gap-1">
                    <Button
                        v-if="
                            can('roles.update') && role.name !== superAdminRole
                        "
                        variant="ghost"
                        size="sm"
                        as-child
                    >
                        <Link :href="edit(role.id)">
                            <Pencil class="size-4" />
                            {{ t('Edit') }}
                        </Link>
                    </Button>
                    <ConfirmDialog
                        v-if="
                            can('roles.delete') &&
                            !protectedRoles.includes(role.name)
                        "
                        :title="t('Delete role')"
                        :description="
                            t(
                                'Users with this role will lose its permissions. This cannot be undone.',
                            )
                        "
                        :confirm-text="t('Delete')"
                        @confirm="remove(role)"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-destructive"
                        >
                            <Trash2 class="size-4" />
                            {{ t('Delete') }}
                        </Button>
                    </ConfirmDialog>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
