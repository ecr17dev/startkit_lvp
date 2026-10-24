<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { MoreHorizontal, Pencil, Plug, Plus, Radar, Trash2 } from '@lucide/vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@starter/ui/components/dropdown-menu';
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
import connectionRoutes from '@/routes/admin/ai/connections';
import { index as modelsIndex } from '@/routes/admin/ai/models';
import type { AiConnection } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'AI connections', href: connectionRoutes.index() },
        ],
    },
});

defineProps<{ connections: AiConnection[] }>();

const { can } = usePermissions();

const statusVariant = (status: string | null) =>
    status === 'ok' ? 'default' : status ? 'destructive' : 'outline';

const post = (url: string) => router.post(url, {}, { preserveScroll: true });
const remove = (connection: AiConnection) =>
    router.delete(connectionRoutes.destroy(connection.id).url, {
        preserveScroll: true,
    });
</script>

<template>
    <Head :title="t('AI connections')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('AI connections')"
            :description="
                t(
                    'Credentials and endpoints of each provider. Several connections can use the same provider.',
                )
            "
        >
            <Button as-child>
                <Link :href="connectionRoutes.create()">
                    <Plus class="size-4" />
                    {{ t('New connection') }}
                </Link>
            </Button>
        </PageHeader>

        <Alert>
            <AlertDescription>
                {{
                    t(
                        'Keys are encrypted with the application key or read from deployment secrets (env:NAME). They are never shown again nor sent to the browser.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Connection') }}</TableHead>
                        <TableHead class="hidden md:table-cell">{{
                            t('Credential')
                        }}</TableHead>
                        <TableHead>{{ t('Models') }}</TableHead>
                        <TableHead>{{ t('Last test') }}</TableHead>
                        <TableHead class="w-16 text-right">{{
                            t('Actions')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="connection in connections"
                        :key="connection.id"
                    >
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    connection.label
                                }}</span>
                                <Badge v-if="connection.is_default">{{
                                    t('Default')
                                }}</Badge>
                                <Badge
                                    v-if="!connection.is_active"
                                    variant="outline"
                                    >{{ t('Inactive') }}</Badge
                                >
                            </div>
                            <div class="text-xs text-muted-foreground">
                                {{ connection.driver_label }} ·
                                {{ connection.endpoint ?? t('SDK default') }}
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden font-mono text-xs text-muted-foreground md:table-cell"
                        >
                            {{
                                connection.masked_credentials.api_key ??
                                (connection.driver === 'bedrock'
                                    ? t('AWS credentials')
                                    : t('None'))
                            }}
                        </TableCell>
                        <TableCell>
                            <Link
                                :href="
                                    modelsIndex({
                                        query: { connection: connection.id },
                                    })
                                "
                                class="hover:underline"
                                >{{ connection.models_count ?? 0 }}</Link
                            >
                        </TableCell>
                        <TableCell>
                            <Badge
                                :variant="
                                    statusVariant(connection.last_test_status)
                                "
                                :title="connection.last_test_message ?? ''"
                                >{{
                                    connection.last_test_status
                                        ? t(connection.last_test_status)
                                        : t('Not tested')
                                }}</Badge
                            >
                            <div
                                v-if="connection.last_tested_at"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ formatDate(connection.last_tested_at) }}
                            </div>
                        </TableCell>
                        <TableCell class="text-right">
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="t('Actions')"
                                        :data-test="`connection-actions-${connection.name}`"
                                    >
                                        <MoreHorizontal class="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="
                                            post(
                                                connectionRoutes.test(
                                                    connection.id,
                                                ).url,
                                            )
                                        "
                                    >
                                        <Plug class="size-4" />
                                        {{ t('Test connection') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="
                                            connection.discovery &&
                                            can('ai.models.manage')
                                        "
                                        @click="
                                            post(
                                                connectionRoutes.discover(
                                                    connection.id,
                                                ).url,
                                            )
                                        "
                                    >
                                        <Radar class="size-4" />
                                        {{ t('Discover models') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem as-child>
                                        <Link
                                            :href="
                                                connectionRoutes.edit(
                                                    connection.id,
                                                )
                                            "
                                        >
                                            <Pencil class="size-4" />
                                            {{ t('Edit') }}
                                        </Link>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <ConfirmDialog
                                :title="t('Delete connection')"
                                :description="
                                    t(
                                        'Its models and prices are removed. The usage ledger keeps its history.',
                                    )
                                "
                                :confirm-text="t('Delete')"
                                @confirm="remove(connection)"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive"
                                    :aria-label="t('Delete')"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </ConfirmDialog>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="!connections.length" :colspan="5">
                        {{
                            t('No connections yet. Add one to start using AI.')
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
