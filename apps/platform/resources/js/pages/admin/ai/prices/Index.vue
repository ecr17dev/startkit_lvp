<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { RefreshCw } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Badge } from '@starter/ui/components/badge';
import { Button } from '@starter/ui/components/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import { formatPrice, priceComponentLabels, priceUnitLabels } from '@/lib/ai';
import { formatDate, t } from '@/lib/i18n';
import models from '@/routes/admin/ai/models';
import prices from '@/routes/admin/ai/prices';
import type { ModelPriceRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Prices', href: prices.index() }],
    },
});

defineProps<{
    sources: {
        id: number;
        key: string;
        name: string;
        kind: string;
        url: string | null;
        current_prices: number;
        last_synced_at: string | null;
        syncable: boolean;
    }[];
    runs: {
        id: number;
        source: string;
        status: string;
        stats: Record<string, number>;
        error: string | null;
        started_at: string;
        finished_at: string | null;
    }[];
    changes: ModelPriceRow[];
}>();

const kindLabels: Record<string, string> = {
    direct: 'Provider list price',
    gateway: 'Gateway price',
    custom: 'Custom agreement',
    manual: 'Manual entry',
};

const sync = () => router.post(prices.sync().url, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Prices')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Prices')"
            :description="
                t(
                    'Where prices come from and how they changed. Prices are versioned: the ledger keeps the price applied to each call.',
                )
            "
        >
            <Button variant="outline" @click="sync">
                <RefreshCw class="size-4" />
                {{ t('Sync gateway prices') }}
            </Button>
        </PageHeader>

        <Alert>
            <AlertDescription>{{
                t(
                    'Prices are never invented: they come from structured catalogs (OpenRouter models API for OpenRouter connections) or are entered manually with their source. A model without a price has an unknown cost.',
                )
            }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 md:grid-cols-2">
            <Card v-for="source in sources" :key="source.id">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        {{ t(source.name) }}
                        <Badge
                            v-if="
                                t(kindLabels[source.kind] ?? source.kind) !==
                                t(source.name)
                            "
                            variant="outline"
                            >{{
                                t(kindLabels[source.kind] ?? source.kind)
                            }}</Badge
                        >
                    </CardTitle>
                    <CardDescription>
                        <a
                            v-if="source.url"
                            :href="source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline"
                            >{{ source.url }}</a
                        >
                    </CardDescription>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    {{
                        t(':count current prices', {
                            count: source.current_prices,
                        })
                    }}
                    <template v-if="source.last_synced_at">
                        · {{ t('Synced') }}
                        {{ formatDate(source.last_synced_at) }}</template
                    >
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Synchronization runs') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Started') }}</TableHead>
                            <TableHead>{{ t('Source') }}</TableHead>
                            <TableHead>{{ t('Status') }}</TableHead>
                            <TableHead>{{ t('Result') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="run in runs" :key="run.id">
                            <TableCell class="text-sm text-muted-foreground">{{
                                formatDate(run.started_at)
                            }}</TableCell>
                            <TableCell class="text-sm">{{
                                run.source
                            }}</TableCell>
                            <TableCell
                                ><Badge
                                    :variant="
                                        run.status === 'succeeded'
                                            ? 'secondary'
                                            : run.status === 'failed'
                                              ? 'destructive'
                                              : 'outline'
                                    "
                                    >{{ t(run.status) }}</Badge
                                ></TableCell
                            >
                            <TableCell class="text-xs text-muted-foreground">
                                <template v-if="run.error">{{
                                    run.error
                                }}</template>
                                <template v-else>{{
                                    Object.entries(run.stats)
                                        .map(
                                            ([key, value]) =>
                                                `${t(key)}: ${value}`,
                                        )
                                        .join(' · ')
                                }}</template>
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="!runs.length" :colspan="4">{{
                            t('No synchronization yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Latest price changes') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Model') }}</TableHead>
                            <TableHead>{{ t('Component') }}</TableHead>
                            <TableHead>{{ t('Price') }}</TableHead>
                            <TableHead>{{ t('Source') }}</TableHead>
                            <TableHead>{{ t('From') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="price in changes" :key="price.id">
                            <TableCell class="text-sm">
                                <Link
                                    v-if="price.model"
                                    :href="models.show(price.model.id)"
                                    class="font-medium hover:underline"
                                    >{{ price.model.name }}</Link
                                >
                                <div class="text-xs text-muted-foreground">
                                    {{ price.model?.connection }}
                                </div>
                            </TableCell>
                            <TableCell class="text-sm">{{
                                t(
                                    priceComponentLabels[price.component] ??
                                        price.component,
                                )
                            }}</TableCell>
                            <TableCell class="text-sm"
                                >{{ formatPrice(price.amount) }}
                                {{ t(priceUnitLabels[price.unit] ?? price.unit)
                                }}<span
                                    v-if="price.effective_to"
                                    class="ml-1 text-xs text-muted-foreground"
                                    >({{ t('replaced') }})</span
                                ></TableCell
                            >
                            <TableCell class="text-sm">{{
                                price.source ? t(price.source.name) : ''
                            }}</TableCell>
                            <TableCell class="text-sm text-muted-foreground">{{
                                formatDate(price.effective_from)
                            }}</TableCell>
                        </TableRow>
                        <TableEmpty v-if="!changes.length" :colspan="5">{{
                            t('No prices yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    </div>
</template>
