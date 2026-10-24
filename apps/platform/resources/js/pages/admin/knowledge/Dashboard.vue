<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChartColumn,
    Clock,
    Coins,
    FileText,
    Layers,
    ListChecks,
    SearchX,
} from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import CumulativeLineChart from '@/components/charts/CumulativeLineChart.vue';
import RankedBars from '@/components/charts/RankedBars.vue';
import type {
    ChartSeries,
    SeriesPoint,
} from '@/components/charts/SeriesBarChart.vue';
import SeriesBarChart from '@/components/charts/SeriesBarChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import { Badge } from '@starter/ui/components/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { Label } from '@starter/ui/components/label';
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
import { formatCost } from '@/lib/ai';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import {
    documentStatusLabels,
    formatBytes,
    formatMs,
    formatRatio,
    jobStatusLabels,
    jobStatusVariant,
    outcomeLabels,
    scheduleLabels,
    sourceTypeLabels,
    stageLabels,
} from '@/lib/knowledge';
import knowledge from '@/routes/admin/knowledge';
import evaluations from '@/routes/admin/knowledge/evaluations';
import type { KnowledgeReport } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Knowledge', href: knowledge.index() },
            { title: 'Dashboard', href: knowledge.dashboard() },
        ],
    },
});

const props = defineProps<{
    filters: { days: number; collection: number | null };
    collections: { id: number; name: string }[];
    report: KnowledgeReport;
}>();

const filters = reactive({
    days: String(props.filters.days),
    collection: props.filters.collection
        ? String(props.filters.collection)
        : 'all',
});

watch(filters, () =>
    router.get(
        knowledge.dashboard().url,
        {
            days: filters.days,
            ...(filters.collection === 'all'
                ? {}
                : { collection: filters.collection }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    ),
);

const totals = computed(() => props.report.totals);

// Status is state, not identity: reserved colors, always with a label...
const statusOrder = [
    'indexed',
    'indexing',
    'pending',
    'empty',
    'duplicate',
    'failed',
] as const;
const statusColor: Record<string, string> = {
    indexed: 'bg-emerald-600 dark:bg-emerald-500',
    indexing: 'bg-muted-foreground/50',
    pending: 'bg-muted-foreground/30',
    empty: 'bg-amber-500',
    duplicate: 'bg-amber-300 dark:bg-amber-700',
    failed: 'bg-destructive',
};

const statusSummary = (counts: Record<string, number>) =>
    statusOrder
        .filter((status) => counts[status])
        .map((status) => `${t(documentStatusLabels[status])} ${counts[status]}`)
        .join(' · ');

const costSeries: ChartSeries[] = [
    { key: 'embedding', label: t('Indexing embeddings'), color: 'bg-chart-1' },
    { key: 'rag', label: t('Query embeddings'), color: 'bg-chart-2' },
];

const ingestion = computed<SeriesPoint[]>(() => props.report.ingestion_daily);
const costs = computed<SeriesPoint[]>(() => props.report.costs_daily);
const queries = computed<SeriesPoint[]>(() => props.report.queries_daily);

const latency = computed(() =>
    props.report.queries_daily.map((day) => ({
        date: day.day,
        value: day.p95_latency_ms,
    })),
);

const outcomes = computed(() =>
    Object.entries(props.report.outcomes)
        .map(([outcome, value]) => ({
            label: t(outcomeLabels[outcome] ?? outcome),
            value,
        }))
        .sort((a, b) => b.value - a.value),
);

const collectionRows = computed(() =>
    [...props.report.collections]
        .sort((a, b) => b.chunks - a.chunks)
        .map((collection) => ({
            label: collection.name,
            value: collection.chunks,
            hint: t('Documents: :documents · tokens: :tokens · :size', {
                documents: formatNumber(collection.documents),
                tokens: formatNumber(collection.tokens),
                size: formatBytes(collection.bytes),
            }),
        })),
);

const stageRows = computed(() =>
    props.report.queue.stages.map((stage) => ({
        label: t(stageLabels[stage.stage] ?? stage.stage),
        value: stage.avg_ms,
        hint: t('Jobs: :n', { n: formatNumber(stage.jobs) }),
    })),
);

const errorGroups = computed(() => [
    {
        title: t('Documents by type'),
        rows: props.report.errors.by_type,
    },
    { title: t('Jobs by error'), rows: props.report.errors.by_job },
    {
        title: t('Provider calls'),
        rows: props.report.errors.by_provider,
    },
]);

const queryDetails = (point: SeriesPoint) => [
    t('Average :ms · p95 :p95', {
        ms: formatMs(Number(point.avg_latency_ms)),
        p95: formatMs(Number(point.p95_latency_ms)),
    }),
    t('Without results: :n · abstained: :m', {
        n: formatNumber(Number(point.empty)),
        m: formatNumber(Number(point.abstained)),
    }),
];

const ingestionDetails = (point: SeriesPoint) => [
    t('New versions: :n', { n: formatNumber(Number(point.versions)) }),
];

const age = (hours: number | null) => {
    if (hours === null) {
        return t('Never');
    }

    return hours < 48
        ? t(':n h', { n: hours })
        : t(':n days', { n: Math.round(hours / 24) });
};

const period = computed(
    () =>
        `${formatDate(props.report.window.from, { dateStyle: 'medium' })} – ${formatDate(props.report.window.to, { dateStyle: 'medium' })}`,
);
</script>

<template>
    <Head :title="t('Knowledge dashboard')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Knowledge dashboard')"
            :description="
                t(
                    'Operation and quality of the knowledge base, derived from real events: documents, jobs, the AI ledger and the retrieval traces.',
                )
            "
        >
            <template #icon><ChartColumn class="size-5" /></template>
        </PageHeader>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="grid gap-1">
                <Label class="text-xs">{{ t('Period') }}</Label>
                <Select v-model="filters.days">
                    <SelectTrigger class="w-full" data-test="dashboard-days"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="7">{{
                            t('Last 7 days')
                        }}</SelectItem>
                        <SelectItem value="30">{{
                            t('Last 30 days')
                        }}</SelectItem>
                        <SelectItem value="90">{{
                            t('Last 90 days')
                        }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1">
                <Label class="text-xs">{{ t('Collection') }}</Label>
                <Select v-model="filters.collection">
                    <SelectTrigger class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('All collections')
                        }}</SelectItem>
                        <SelectItem
                            v-for="collection in collections"
                            :key="collection.id"
                            :value="String(collection.id)"
                            >{{ collection.name }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <p
                class="self-end text-xs text-muted-foreground sm:col-span-2 sm:text-right"
            >
                {{ period }}
            </p>
        </div>

        <!-- Operation -->
        <section class="grid gap-4">
            <h2 class="text-base font-semibold">{{ t('Operation') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :title="t('Searchable documents')"
                    :value="formatNumber(totals.indexed)"
                    :hint="
                        t(
                            'Processed: :processed of :total · sources: :sources',
                            {
                                processed: formatNumber(totals.processed),
                                total: formatNumber(totals.documents),
                                sources: formatNumber(totals.sources),
                            },
                        )
                    "
                    :icon="FileText"
                />
                <StatCard
                    :title="t('Chunks in active indexes')"
                    :value="formatNumber(totals.chunks)"
                    :hint="
                        t('Originals: :size · collections: :n', {
                            size: formatBytes(totals.bytes),
                            n: totals.collections,
                        })
                    "
                    :icon="Layers"
                />
                <StatCard
                    :title="t('Open jobs')"
                    :value="formatNumber(report.queue.open)"
                    :hint="t('Queued, running, retrying or paused')"
                    :icon="ListChecks"
                />
                <StatCard
                    :title="t('Embedding cost')"
                    :value="formatMoney(totals.embedding_cost)"
                    :hint="
                        totals.unknown_cost_calls
                            ? t('Calls with unknown cost (not included): :n', {
                                  n: totals.unknown_cost_calls,
                              })
                            : t('Queries: :amount', {
                                  amount: formatMoney(totals.query_cost),
                              })
                    "
                    :icon="Coins"
                />
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Documents by status and source')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('What is ready, pending or failing?')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-3">
                        <div
                            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        >
                            <span
                                v-for="status in statusOrder"
                                :key="status"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2.5 rounded-sm"
                                    :class="statusColor[status]"
                                />
                                {{ t(documentStatusLabels[status]) }}
                            </span>
                        </div>
                        <div
                            v-for="source in report.documents_by_status"
                            :key="source.source_id"
                            class="grid gap-1"
                        >
                            <div
                                class="flex items-baseline justify-between gap-2 text-sm"
                            >
                                <span class="min-w-0 truncate"
                                    >{{ source.source }}
                                    <span class="text-xs text-muted-foreground"
                                        >· {{ source.collection }}</span
                                    ></span
                                >
                                <span class="shrink-0 tabular-nums">{{
                                    formatNumber(source.total)
                                }}</span>
                            </div>
                            <div class="flex h-2.5 gap-0.5">
                                <div
                                    v-for="status in statusOrder.filter(
                                        (item) => source.counts[item],
                                    )"
                                    :key="status"
                                    class="h-full first:rounded-l-[4px] last:rounded-r-[4px]"
                                    :class="statusColor[status]"
                                    :style="{ flexGrow: source.counts[status] }"
                                    :title="`${t(documentStatusLabels[status])}: ${source.counts[status]}`"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ statusSummary(source.counts) }}
                            </p>
                        </div>
                        <p
                            v-if="!report.documents_by_status.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ t('No documents yet.') }}
                        </p>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Ingestion per day')
                        }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'Documents indexed per day (new versions in the tooltip).',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SeriesBarChart
                            :points="ingestion"
                            :series="[
                                {
                                    key: 'indexed',
                                    label: t('Documents indexed'),
                                    color: 'bg-primary',
                                },
                            ]"
                            :details="ingestionDetails"
                            :empty="t('Nothing indexed in the period.')"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Chunks and volume per collection')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('Where is the content concentrated?')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <RankedBars
                            :rows="collectionRows"
                            :empty="t('No collections.')"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Queue and time per stage')
                        }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'Average time of each stage in the jobs of the period: where does indexing get stuck?',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4">
                        <div class="flex flex-wrap gap-1.5">
                            <Badge
                                v-for="(count, status) in report.queue
                                    .by_status"
                                :key="status"
                                :variant="jobStatusVariant[status] ?? 'outline'"
                                >{{ t(jobStatusLabels[status] ?? status) }}
                                {{ count }}</Badge
                            >
                        </div>
                        <RankedBars
                            :rows="stageRows"
                            compact
                            :format="formatMs"
                            :empty="t('No finished jobs in the period.')"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Errors by parser and provider')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('Which integration needs attention?')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="grid gap-4 sm:grid-cols-3">
                        <div
                            v-for="group in errorGroups"
                            :key="group.title"
                            class="grid content-start gap-2"
                        >
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ group.title }}
                            </p>
                            <RankedBars
                                :rows="
                                    group.rows.map((row) => ({
                                        label: row.label,
                                        value: row.total,
                                    }))
                                "
                                :empty="t('No errors.')"
                            />
                        </div>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Embedding and indexing cost')
                        }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'Calculated costs from the AI ledger (USD). Unknown costs are counted apart, never as zero.',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SeriesBarChart
                            :points="costs"
                            :series="costSeries"
                            :empty="t('No calculated cost in the period.')"
                            :format="(value) => formatMoney(value, 4)"
                            :details="
                                (point) =>
                                    Number(point.unknown)
                                        ? [
                                              t('Calls with unknown cost: :n', {
                                                  n: Number(point.unknown),
                                              }),
                                          ]
                                        : []
                            "
                        />
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- Use and quality -->
        <section class="grid gap-4">
            <h2 class="text-base font-semibold">{{ t('Use and quality') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    :title="t('Retrieval queries')"
                    :value="formatNumber(totals.queries)"
                    :hint="
                        t('Query cost: :amount', {
                            amount: formatMoney(totals.query_cost),
                        })
                    "
                    :icon="ChartColumn"
                />
                <StatCard
                    :title="t('Latency average / p95')"
                    :value="`${formatMs(totals.avg_latency_ms)} / ${formatMs(totals.p95_latency_ms)}`"
                    :icon="Clock"
                />
                <StatCard
                    :title="t('Without results')"
                    :value="formatRatio(totals.no_result_rate)"
                    :hint="t('Queries where no authorized passage matched')"
                    :icon="SearchX"
                />
                <StatCard
                    :title="t('Abstentions')"
                    :value="formatRatio(totals.abstention_rate)"
                    :hint="t('Answers not generated for lack of evidence')"
                    :icon="SearchX"
                />
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Retrieval queries per day')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('How does the system respond under real use?')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SeriesBarChart
                            :points="queries"
                            :series="[
                                {
                                    key: 'queries',
                                    label: t('Queries'),
                                    color: 'bg-primary',
                                },
                            ]"
                            :details="queryDetails"
                            :empty="t('No queries in the period.')"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Latency p95 per day')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('Embedding of the query plus the search.')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CumulativeLineChart
                            :points="latency"
                            :aria-label="t('Latency p95 per day')"
                            :summary="
                                (max) =>
                                    t('Max: :value', { value: formatMs(max) })
                            "
                            :format="(value) => formatMs(value)"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Outcome of grounded answers')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('Chat, tool and API answers with knowledge.')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <RankedBars
                            :rows="outcomes"
                            :empty="t('No grounded answers in the period.')"
                        />
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Questions without answer')
                        }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'No results or abstained: what knowledge is missing?',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="pl-6">{{
                                        t('Question')
                                    }}</TableHead>
                                    <TableHead class="text-right">{{
                                        t('Times')
                                    }}</TableHead>
                                    <TableHead class="pr-6 text-right">{{
                                        t('Last')
                                    }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="row in report.unanswered"
                                    :key="row.query"
                                >
                                    <TableCell
                                        class="max-w-64 truncate pl-6"
                                        :title="row.query"
                                        >{{ row.query }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{ row.total }}</TableCell
                                    >
                                    <TableCell
                                        class="pr-6 text-right text-xs text-muted-foreground"
                                        >{{
                                            formatDate(row.last_at)
                                        }}</TableCell
                                    >
                                </TableRow>
                                <TableEmpty
                                    v-if="!report.unanswered.length"
                                    :colspan="3"
                                    >{{
                                        t('Nothing missing in the period.')
                                    }}</TableEmpty
                                >
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Card class="min-w-0">
                <CardHeader>
                    <CardTitle class="text-base">{{
                        t('Citations and feedback per agent')
                    }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'What evidence do the agents use and how do users rate it?',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="px-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="pl-6">{{
                                    t('Agent')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Answers')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Citations / answer')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Discarded citations')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Without citations')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Abstained')
                                }}</TableHead>
                                <TableHead class="pr-6 text-right">{{
                                    t('Useful / not useful')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in report.agents"
                                :key="row.agent"
                            >
                                <TableCell class="pl-6 font-medium">{{
                                    row.agent
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.answers
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.avg_citations.toFixed(2)
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.invalid
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.unsupported
                                }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{
                                    row.abstained
                                }}</TableCell>
                                <TableCell class="pr-6 text-right tabular-nums"
                                    >{{ row.positive }} /
                                    {{ row.negative }}</TableCell
                                >
                            </TableRow>
                            <TableEmpty
                                v-if="!report.agents.length"
                                :colspan="7"
                                >{{
                                    t(
                                        'No agent answered with knowledge in the period.',
                                    )
                                }}</TableEmpty
                            >
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <div class="grid gap-4 xl:grid-cols-2">
                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Age and changes per source')
                        }}</CardTitle>
                        <CardDescription>{{
                            t(
                                'Which content needs to be updated? Changes of the last sync.',
                            )
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="pl-6">{{
                                        t('Source')
                                    }}</TableHead>
                                    <TableHead class="pr-6 text-right">{{
                                        t('Last sync')
                                    }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="row in report.freshness"
                                    :key="`${row.collection}-${row.source}`"
                                >
                                    <TableCell class="pl-6">
                                        <span class="font-medium">{{
                                            row.source
                                        }}</span>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ row.collection }} ·
                                            {{
                                                t(
                                                    sourceTypeLabels[
                                                        row.type
                                                    ] ?? row.type,
                                                )
                                            }}
                                            <template v-if="row.schedule">
                                                ·
                                                {{
                                                    t(
                                                        scheduleLabels[
                                                            row.schedule
                                                        ] ?? row.schedule,
                                                    )
                                                }}</template
                                            >
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    'Changed :c · deleted :d · same :u',
                                                    {
                                                        c: row.changed,
                                                        d: row.deleted,
                                                        u: row.unchanged,
                                                    },
                                                )
                                            }}
                                        </p>
                                    </TableCell>
                                    <TableCell
                                        class="pr-6 text-right align-top text-sm"
                                        >{{ age(row.age_hours) }}</TableCell
                                    >
                                </TableRow>
                                <TableEmpty
                                    v-if="!report.freshness.length"
                                    :colspan="2"
                                    >{{ t('No sources.') }}</TableEmpty
                                >
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card class="min-w-0">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            t('Retrieval quality (evaluations)')
                        }}</CardTitle>
                        <CardDescription>{{
                            t('Latest completed run of every evaluation set.')
                        }}</CardDescription>
                    </CardHeader>
                    <CardContent class="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="pl-6">{{
                                        t('Set')
                                    }}</TableHead>
                                    <TableHead class="text-right"
                                        >Recall@k</TableHead
                                    >
                                    <TableHead class="text-right"
                                        >MRR</TableHead
                                    >
                                    <TableHead class="pr-6 text-right">{{
                                        t('Cost')
                                    }}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="row in report.evaluations"
                                    :key="row.set_id"
                                >
                                    <TableCell class="pl-6">
                                        <Link
                                            :href="evaluations.show(row.set_id)"
                                            class="font-medium hover:underline"
                                            >{{ row.set }}</Link
                                        >
                                        <p
                                            v-if="row.finished_at"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ formatDate(row.finished_at) }}
                                        </p>
                                    </TableCell>
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{
                                            formatRatio(
                                                row.metrics?.recall_at_k,
                                            )
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="text-right tabular-nums"
                                        >{{
                                            row.metrics
                                                ? row.metrics.mrr.toFixed(3)
                                                : '—'
                                        }}</TableCell
                                    >
                                    <TableCell
                                        class="pr-6 text-right tabular-nums"
                                        >{{
                                            row.metrics
                                                ? formatCost(row.metrics.cost)
                                                : '—'
                                        }}</TableCell
                                    >
                                </TableRow>
                                <TableEmpty
                                    v-if="!report.evaluations.length"
                                    :colspan="4"
                                    >{{
                                        t('No evaluation has been run yet.')
                                    }}</TableEmpty
                                >
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </section>
    </div>
</template>
