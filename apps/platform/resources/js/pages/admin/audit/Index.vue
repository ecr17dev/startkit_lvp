<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { reactive } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@starter/ui/components/badge';
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
import { auditActionLabel, auditChannels } from '@/lib/audit';
import { formatDate, t } from '@/lib/i18n';
import { index } from '@/routes/admin/audit';
import type { AuditEvent, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit trail', href: index() }],
    },
});

const props = defineProps<{
    events: Paginated<AuditEvent>;
    actions: string[];
    filters: { action?: string; channel?: string; from?: string; to?: string };
}>();

const filters = reactive({
    action: props.filters.action ?? 'all',
    channel: props.filters.channel ?? 'all',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

watchDebounced(
    filters,
    () =>
        router.get(
            index().url,
            {
                action: filters.action === 'all' ? undefined : filters.action,
                channel:
                    filters.channel === 'all' ? undefined : filters.channel,
                from: filters.from || undefined,
                to: filters.to || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        ),
    { debounce: 300, deep: true },
);

const subject = (event: AuditEvent) =>
    event.subject_type ? `${event.subject_type} #${event.subject_id}` : '—';

const details = (event: AuditEvent) => {
    const entries = Object.entries(event.properties ?? {});

    return entries.length
        ? entries
              .map(
                  ([key, value]) =>
                      `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`,
              )
              .join(' · ')
        : '';
};
</script>

<template>
    <Head :title="t('Audit trail')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Audit trail')"
            :description="
                t(
                    'Who did what, when and from where: panel, API, CLI or AI chat.',
                )
            "
        />

        <div class="grid gap-3 sm:grid-cols-4">
            <Select v-model="filters.action">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All actions') }}</SelectItem>
                    <SelectItem
                        v-for="action in actions"
                        :key="action"
                        :value="action"
                        >{{ auditActionLabel(action) }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filters.channel">
                <SelectTrigger class="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{ t('All channels') }}</SelectItem>
                    <SelectItem
                        v-for="channel in auditChannels"
                        :key="channel"
                        :value="channel"
                        >{{ channel.toUpperCase() }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Input v-model="filters.from" type="date" :aria-label="t('From')" />
            <Input v-model="filters.to" type="date" :aria-label="t('To')" />
        </div>

        <div class="rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Date') }}</TableHead>
                        <TableHead>{{ t('Action') }}</TableHead>
                        <TableHead>{{ t('Actor') }}</TableHead>
                        <TableHead class="hidden lg:table-cell">{{
                            t('Resource')
                        }}</TableHead>
                        <TableHead class="hidden xl:table-cell">{{
                            t('Details')
                        }}</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="event in events.data" :key="event.id">
                        <TableCell
                            class="text-sm whitespace-nowrap text-muted-foreground"
                            >{{ formatDate(event.created_at) }}</TableCell
                        >
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{
                                    auditActionLabel(event.action)
                                }}</span>
                                <Badge variant="outline" class="uppercase">{{
                                    event.channel
                                }}</Badge>
                            </div>
                            <code class="text-xs text-muted-foreground">{{
                                event.action
                            }}</code>
                        </TableCell>
                        <TableCell class="text-sm">
                            {{
                                event.actor
                                    ? (event.actor.name ?? '#' + event.actor.id)
                                    : t('System')
                            }}
                            <div
                                v-if="event.ip_address"
                                class="text-xs text-muted-foreground"
                            >
                                {{ event.ip_address }}
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-sm text-muted-foreground lg:table-cell"
                            >{{ subject(event) }}</TableCell
                        >
                        <TableCell
                            class="hidden max-w-md truncate text-xs text-muted-foreground xl:table-cell"
                            :title="details(event)"
                            >{{ details(event) }}</TableCell
                        >
                    </TableRow>
                    <TableEmpty v-if="!events.data.length" :colspan="5">
                        {{ t('No events found.') }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Pagination :meta="events.meta" />
    </div>
</template>
