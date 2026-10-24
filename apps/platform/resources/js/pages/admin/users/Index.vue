<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { MailPlus, Pencil, Plus, Search } from '@lucide/vue';
import { reactive } from 'vue';
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
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, t } from '@/lib/i18n';
import { index as invitations } from '@/routes/admin/invitations';
import { create, edit, index } from '@/routes/admin/users';
import type { AdminUser, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: index() }],
    },
});

const props = defineProps<{
    users: Paginated<AdminUser>;
    roles: string[];
    filters: { search?: string; role?: string; status?: string };
}>();

const { can } = usePermissions();

const filters = reactive({
    search: props.filters.search ?? '',
    role: props.filters.role ?? 'all',
    status: props.filters.status ?? 'all',
});

watchDebounced(
    filters,
    () => {
        router.get(
            index().url,
            {
                search: filters.search || undefined,
                role: filters.role === 'all' ? undefined : filters.role,
                status: filters.status === 'all' ? undefined : filters.status,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    },
    { debounce: 300, deep: true },
);
</script>

<template>
    <Head :title="t('Users')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Users')"
            :description="
                t('Manage the accounts, roles and access of your users')
            "
        >
            <div class="flex flex-wrap gap-2">
                <Button v-if="can('users.invite')" variant="outline" as-child>
                    <Link :href="invitations()">
                        <MailPlus class="size-4" />
                        {{ t('Invitations') }}
                    </Link>
                </Button>
                <Button v-if="can('users.create')" as-child>
                    <Link :href="create()">
                        <Plus class="size-4" />
                        {{ t('New user') }}
                    </Link>
                </Button>
            </div>
        </PageHeader>

        <div class="flex flex-col gap-3 sm:flex-row">
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="filters.search"
                    class="pl-9"
                    :placeholder="t('Search by name or email')"
                />
            </div>
            <Select v-model="filters.role">
                <SelectTrigger class="sm:w-48">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All roles') }}</SelectItem>
                    <SelectItem
                        v-for="role in roles"
                        :key="role"
                        :value="role"
                        >{{ role }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filters.status">
                <SelectTrigger class="sm:w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All statuses') }}</SelectItem>
                    <SelectItem value="active">{{ t('Active') }}</SelectItem>
                    <SelectItem value="disabled">{{
                        t('Disabled')
                    }}</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('User') }}</TableHead>
                        <TableHead>{{ t('Roles') }}</TableHead>
                        <TableHead>{{ t('Status') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Last login')
                        }}</TableHead>
                        <TableHead class="w-24 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="user in users.data" :key="user.id">
                        <TableCell>
                            <Link
                                :href="edit(user.id)"
                                class="font-medium hover:underline"
                                >{{ user.name || '—' }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ user.email }}
                            </div>
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in user.roles"
                                    :key="role"
                                    variant="secondary"
                                    >{{ role }}</Badge
                                >
                            </div>
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    user.is_active ? 'default' : 'destructive'
                                "
                                >{{
                                    user.is_active ? t('Active') : t('Disabled')
                                }}</Badge
                            >
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                        >
                            {{
                                user.last_login_at
                                    ? formatDate(user.last_login_at)
                                    : t('Never')
                            }}
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="edit(user.id)"
                                        :aria-label="t('Edit')"
                                    >
                                        <Pencil class="size-4" />
                                    </Link>
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!users.data.length" :colspan="5">
                        {{ t('No users found.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="users.meta" />
    </div>
</template>
