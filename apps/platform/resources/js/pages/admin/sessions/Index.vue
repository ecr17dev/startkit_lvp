<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatDate, t } from '@/lib/i18n';
import { destroy, index } from '@/routes/admin/sessions';
import { edit } from '@/routes/admin/users';
import type { BrowserSession, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sessions', href: index() }],
    },
});

defineProps<{
    sessions: Paginated<BrowserSession>;
    canRevoke: boolean;
}>();

const revoke = (session: BrowserSession) =>
    session.user &&
    router.delete(destroy([session.user.id, session.id]).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="t('Sessions')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Active sessions')"
            :description="
                t('Browser sessions active during the session lifetime.')
            "
        />

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('User') }}</TableHead>
                        <TableHead>{{ t('Device') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('IP address')
                        }}</TableHead>
                        <TableHead>{{ t('Last active') }}</TableHead>
                        <TableHead class="w-24 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="session in sessions.data"
                        :key="session.id"
                    >
                        <TableCell>
                            <Link
                                v-if="session.user"
                                :href="edit(session.user.id)"
                                class="font-medium hover:underline"
                                >{{
                                    session.user.name || session.user.email
                                }}</Link
                            >
                            <div class="text-xs text-muted-foreground">
                                {{ session.user?.email }}
                            </div>
                        </TableCell>
                        <TableCell class="text-sm">
                            {{ session.browser }} · {{ session.platform }}
                            <Badge
                                v-if="session.current"
                                variant="secondary"
                                class="ml-1"
                                >{{ t('This device') }}</Badge
                            >
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground md:table-cell"
                            >{{ session.ip_address ?? '—' }}</TableCell
                        >
                        <TableCell class="text-sm text-muted-foreground">{{
                            formatDate(session.last_active_at)
                        }}</TableCell>
                        <TableCell class="text-right">
                            <ConfirmDialog
                                v-if="canRevoke && !session.current"
                                :title="t('Revoke session')"
                                :description="
                                    t(
                                        'The device will be logged out on its next request.',
                                    )
                                "
                                :confirm-text="t('Revoke')"
                                @confirm="revoke(session)"
                            >
                                <Button variant="ghost" size="sm">{{
                                    t('Revoke')
                                }}</Button>
                            </ConfirmDialog>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!sessions.data.length" :colspan="5">
                        {{ t('No active sessions.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="sessions.meta" />
    </div>
</template>
