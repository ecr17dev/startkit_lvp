<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RotateCw } from '@lucide/vue';
import { ActionsMenu, DataTable } from '@/components/kit';
import type { DataTableColumn, MenuAction } from '@/components/kit';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@starter/ui/components/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { usePermissions } from '@/composables/usePermissions';
import { useTableQuery } from '@/composables/useTableQuery';
import { formatDate, t } from '@/lib/i18n';
import { index, retry } from '@/routes/admin/mail/deliveries';
import type { SimplePaginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mail', href: index() }],
    },
});

type Status = 'pending' | 'sent' | 'failed' | 'skipped';

type Delivery = {
    id: number;
    email: string;
    subject: string | null;
    template_key: string;
    automation: string | null;
    status: Status;
    error: string | null;
    send_at: string | null;
    sent_at: string | null;
    created_at: string;
};

const props = defineProps<{
    deliveries: SimplePaginated<Delivery>;
    automations: { id: number; name: string }[];
    filters: {
        search?: string;
        sort?: string;
        direction?: string;
        status?: string;
        automation?: string;
    };
}>();

const { can } = usePermissions();

const query = useTableQuery(index().url, {
    search: props.filters.search,
    sort: props.filters.sort,
    direction: props.filters.direction,
    status: props.filters.status ?? 'all',
    automation: props.filters.automation ?? 'all',
});

const statuses: Record<
    Status,
    {
        label: string;
        variant: 'default' | 'secondary' | 'outline' | 'destructive';
    }
> = {
    pending: { label: 'Pending', variant: 'outline' },
    sent: { label: 'Sent', variant: 'secondary' },
    failed: { label: 'Failed', variant: 'destructive' },
    skipped: { label: 'Skipped', variant: 'outline' },
};

const columns: DataTableColumn<Delivery>[] = [
    { key: 'email', label: t('Recipient'), sortable: true, card: 'title' },
    {
        key: 'subject',
        label: t('Subject'),
        card: 'subtitle',
        format: (value, row) => (value as string | null) ?? row.template_key,
    },
    {
        key: 'automation',
        label: t('Automation'),
        hideBelow: 'lg',
        format: (value) => (value as string | null) ?? '—',
    },
    { key: 'status', label: t('Status'), sortable: true, card: 'badge' },
    {
        key: 'send_at',
        label: t('Scheduled for'),
        sortable: true,
        hideBelow: 'xl',
        format: (value) => formatDate(value as string | null),
    },
    {
        key: 'sent_at',
        label: t('Sent'),
        sortable: true,
        hideBelow: 'md',
        format: (value) => formatDate(value as string | null),
    },
];

const actions = (row: Delivery): MenuAction[] =>
    row.status === 'failed' || row.status === 'skipped'
        ? [
              {
                  label: t('Retry'),
                  icon: RotateCw,
                  onSelect: () =>
                      router.post(
                          retry(row.id).url,
                          {},
                          { preserveScroll: true },
                      ),
              },
          ]
        : [];
</script>

<template>
    <Head :title="t('Sends')" />

    <PageHeader
        :title="t('Sends')"
        :description="
            t(
                'Every email of the automations: pending, sent, failed or skipped, with the error of the provider.',
            )
        "
    />

    <DataTable
        v-model:search="query.search"
        v-model:sort="query.sort"
        :rows="deliveries.data"
        :columns="columns"
        :meta="deliveries"
        :label="t('Sends')"
        :search-placeholder="t('Search by email or subject')"
        :empty-text="t('No sends yet.')"
        server
    >
        <template #toolbar>
            <Select v-model="query.filters.status">
                <SelectTrigger class="sm:w-40" :aria-label="t('Status')">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All statuses') }}</SelectItem>
                    <SelectItem
                        v-for="(status, key) in statuses"
                        :key="key"
                        :value="key"
                        >{{ t(status.label) }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="query.filters.automation">
                <SelectTrigger class="sm:w-56" :aria-label="t('Automation')">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('All automations')
                    }}</SelectItem>
                    <SelectItem
                        v-for="automation in automations"
                        :key="automation.id"
                        :value="String(automation.id)"
                        >{{ automation.name }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </template>
        <template #cell-status="{ row }">
            <div class="grid gap-1">
                <Badge :variant="statuses[row.status].variant" class="w-fit">{{
                    t(statuses[row.status].label)
                }}</Badge>
                <span
                    v-if="row.error"
                    class="max-w-xs text-xs break-words text-muted-foreground"
                    >{{ row.error }}</span
                >
            </div>
        </template>
        <template v-if="can('mail.manage')" #actions="{ row }">
            <ActionsMenu v-if="actions(row).length" :items="actions(row)" />
        </template>
    </DataTable>
</template>
