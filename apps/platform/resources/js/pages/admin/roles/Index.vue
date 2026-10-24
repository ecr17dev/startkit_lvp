<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Grid3x3,
    Lock,
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    Users,
} from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { t } from '@/lib/i18n';
import { index as matrix } from '@/routes/admin/permissions';
import { create, destroy, edit, index } from '@/routes/admin/roles';
import type { AdminRole } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles & permissions', href: index() }],
    },
});

defineProps<{
    roles: AdminRole[];
    totalPermissions: number;
    can: { create: boolean; viewMatrix: boolean };
}>();

const OWNER = 'owner';

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
            <div class="flex flex-wrap gap-2">
                <Button v-if="can.viewMatrix" variant="outline" as-child>
                    <Link :href="matrix()">
                        <Grid3x3 class="size-4" />
                        {{ t('Permission matrix') }}
                    </Link>
                </Button>
                <Button v-if="can.create" as-child>
                    <Link :href="create()">
                        <Plus class="size-4" />
                        {{ t('New role') }}
                    </Link>
                </Button>
            </div>
        </PageHeader>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Card v-for="role in roles" :key="role.id" class="justify-between">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <ShieldCheck class="size-4 text-muted-foreground" />
                        {{ role.name }}
                        <Badge
                            v-if="role.is_system"
                            variant="outline"
                            class="gap-1"
                        >
                            <Lock class="size-3" />
                            {{ t('System') }}
                        </Badge>
                    </CardTitle>
                    <CardDescription>
                        <template v-if="role.name === OWNER">
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
                        v-if="can.create && role.name !== OWNER"
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
                        v-if="can.create && !role.is_system"
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
