<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    Clock,
    Coins,
    Download,
    MessageSquare,
    TriangleAlert,
} from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import type { DailyPoint } from '@/components/charts/DailyBarChart.vue';
import CumulativeLineChart from '@/components/charts/CumulativeLineChart.vue';
import DailyBarChart from '@/components/charts/DailyBarChart.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatCard from '@/components/StatCard.vue';
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
import { Input } from '@starter/ui/components/input';
import { Label } from '@starter/ui/components/label';
import { Progress } from '@starter/ui/components/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Switch } from '@starter/ui/components/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@starter/ui/components/table';
import {
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@starter/ui/components/tabs';
import { usePermissions } from '@/composables/usePermissions';
import { costStatusLabels, featureLabels, formatCost } from '@/lib/ai';
import {
    currentLocale,
    formatDate,
    formatMoney,
    formatNumber,
    t,
} from '@/lib/i18n';
import { usage } from '@/routes/admin/ai';
import usageRoutes from '@/routes/admin/ai/usage';
import type {
    Breakdown,
    BudgetStatus,
    LedgerRequest,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage & costs', href: usage() }],
    },
});

type Totals = {
    requests: number;
    cost: string;
    estimated_cost: string;
    unknown_cost_requests: number;
    errors: number;
    blocked: number;
    error_rate: number;
    input_tokens: number;
    output_tokens: number;
    cache_read_tokens: number;
    avg_latency_ms: number | null;
    p50_latency_ms: number | null;
    p95_latency_ms: number | null;
    avg_ttft_ms: number | null;
};

const props = defineProps<{
    range: { from: string; to: string };
    filters: Record<string, string | undefined>;
    totals: Totals;
    daily: {
        date: string;
        cost: string;
        requests: number;
        tokens: number;
        unknown: number;
    }[];
    breakdowns: Record<
        'feature' | 'connection' | 'model' | 'user' | 'agent',
        Breakdown[]
    >;
    errors: { type: string; count: number }[];
    budgets: ({
        id: number;
        name: string;
        scope: string;
        scope_value: string | null;
        period: string;
        hard_limit: boolean;
    } & BudgetStatus)[];
    requests: Paginated<LedgerRequest>;
    settings: { dashboard: boolean; display_currency: string };
    exchangeRate: {
        currency: string;
        rate: string;
        date: string;
        source: string;
    } | null;
    features: string[];
    statuses: string[];
    costStatuses: string[];
}>();

const { can } = usePermissions();

const filters = reactive({
    from: props.range.from,
    to: props.range.to,
    feature: props.filters.feature ?? 'all',
    cost_status: props.filters.cost_status ?? 'all',
});

const query = computed(() => {
    const result: Record<string, string> = {
        from: filters.from,
        to: filters.to,
    };

    if (filters.feature !== 'all') result.feature = filters.feature;
    if (filters.cost_status !== 'all') result.cost_status = filters.cost_status;

    return result;
});

watch(
    filters,
    () =>
        router.get(usage().url, query.value, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        }),
    { deep: true },
);

const points = computed<DailyPoint[]>(() =>
    props.daily.map((day) => ({
        day: day.date,
        cost: Number(day.cost),
        calls: day.requests,
        tokens: day.tokens,
    })),
);

const cumulative = computed(() => {
    let total = 0;

    return props.daily.map((day) => {
        total += Number(day.cost);

        return { date: day.date, value: total };
    });
});

// Visual conversion only: the ledger keeps USD...
const converted = (usd: string | number | null) => {
    if (
        usd === null ||
        props.settings.display_currency === 'USD' ||
        !props.exchangeRate
    ) {
        return null;
    }

    return new Intl.NumberFormat(currentLocale(), {
        style: 'currency',
        currency: props.exchangeRate.currency,
        maximumFractionDigits: 0,
    }).format(Number(usd) * Number(props.exchangeRate.rate));
};

const settingsForm = useForm({
    dashboard: props.settings.dashboard,
    display_currency: props.settings.display_currency,
});
const rateForm = useForm({ rate: '', date: '' });

const saveSettings = () =>
    settingsForm.put(usageRoutes.settings().url, { preserveScroll: true });
const fetchRate = () =>
    rateForm
        .transform(() => ({}))
        .post(usageRoutes.exchangeRate().url, { preserveScroll: true });
const saveRate = () =>
    rateForm.post(usageRoutes.exchangeRate().url, {
        preserveScroll: true,
        onSuccess: () => rateForm.reset(),
    });

const exportUrl = computed(
    () => usageRoutes.export({ query: query.value }).url,
);

const breakdownTabs = [
    { key: 'feature', label: 'Feature' },
    { key: 'connection', label: 'Connection' },
    { key: 'model', label: 'Model' },
    { key: 'user', label: 'User' },
    { key: 'agent', label: 'Agent' },
] as const;

const ms = (value: number | null) =>
    value === null ? '—' : `${formatNumber(value)} ms`;
</script>

<template>
    <Head :title="t('Usage & costs')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Usage & costs')"
            :description="
                t(
                    'Every AI call is recorded. Costs are in USD; estimates and unknown costs are shown apart, never as real invoices.',
                )
            "
        >
            <Button variant="outline" as-child>
                <a :href="exportUrl" data-test="export-usage">
                    <Download class="size-4" />
                    {{ t('Export CSV') }}
                </a>
            </Button>
        </PageHeader>

        <Alert v-if="!settings.dashboard">
            <AlertDescription>{{
                t(
                    'The dashboard is hidden for other users. Measurement continues.',
                )
            }}</AlertDescription>
        </Alert>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="grid gap-1">
                <Label for="from" class="text-xs">{{ t('From') }}</Label>
                <Input id="from" v-model="filters.from" type="date" />
            </div>
            <div class="grid gap-1">
                <Label for="to" class="text-xs">{{ t('To') }}</Label>
                <Input id="to" v-model="filters.to" type="date" />
            </div>
            <div class="grid gap-1">
                <Label class="text-xs">{{ t('Feature') }}</Label>
                <Select v-model="filters.feature">
                    <SelectTrigger class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{
                            t('All features')
                        }}</SelectItem>
                        <SelectItem
                            v-for="feature in features"
                            :key="feature"
                            :value="feature"
                            >{{
                                t(featureLabels[feature] ?? feature)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1">
                <Label class="text-xs">{{ t('Cost status') }}</Label>
                <Select v-model="filters.cost_status">
                    <SelectTrigger class="w-full"
                        ><SelectValue
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{{ t('All') }}</SelectItem>
                        <SelectItem
                            v-for="status in costStatuses"
                            :key="status"
                            :value="status"
                            >{{
                                t(costStatusLabels[status] ?? status)
                            }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                :title="t('Calculated cost')"
                :value="formatMoney(totals.cost)"
                :hint="
                    converted(totals.cost) ??
                    t(':count requests', {
                        count: formatNumber(totals.requests),
                    })
                "
                :icon="Coins"
            />
            <StatCard
                :title="t('Unknown cost')"
                :value="formatNumber(totals.unknown_cost_requests)"
                :hint="t('Requests without a complete price')"
                :icon="CircleAlert"
            />
            <StatCard
                :title="t('Errors')"
                :value="`${totals.error_rate}%`"
                :hint="
                    t(
                        ':errors failed or interrupted · :blocked blocked by budget',
                        { errors: totals.errors, blocked: totals.blocked },
                    )
                "
                :icon="TriangleAlert"
            />
            <StatCard
                :title="t('Latency p50 / p95')"
                :value="`${ms(totals.p50_latency_ms)} / ${ms(totals.p95_latency_ms)}`"
                :hint="
                    t('Tokens: :input in · :output out', {
                        input: formatNumber(totals.input_tokens),
                        output: formatNumber(totals.output_tokens),
                    })
                "
                :icon="Clock"
            />
        </div>

        <p
            v-if="Number(totals.estimated_cost) > 0"
            class="text-sm text-muted-foreground"
        >
            {{
                t(
                    'Provisional estimates of interrupted calls: :amount (not included above).',
                    { amount: formatMoney(totals.estimated_cost) },
                )
            }}
        </p>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ t('Daily cost') }}</CardTitle>
                    <CardDescription
                        >{{ formatDate(range.from, { dateStyle: 'medium' }) }} –
                        {{
                            formatDate(range.to, { dateStyle: 'medium' })
                        }}</CardDescription
                    >
                </CardHeader>
                <CardContent>
                    <Tabs default-value="chart">
                        <TabsList>
                            <TabsTrigger value="chart">{{
                                t('Chart')
                            }}</TabsTrigger>
                            <TabsTrigger value="cumulative">{{
                                t('Cumulative')
                            }}</TabsTrigger>
                            <TabsTrigger value="table">{{
                                t('Table')
                            }}</TabsTrigger>
                        </TabsList>
                        <TabsContent value="chart" class="pt-4"
                            ><DailyBarChart :points="points"
                        /></TabsContent>
                        <TabsContent value="cumulative" class="pt-4"
                            ><CumulativeLineChart
                                :points="cumulative"
                                :format="(value) => formatMoney(value)"
                        /></TabsContent>
                        <TabsContent
                            value="table"
                            class="max-h-80 overflow-y-auto pt-4"
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{{ t('Date') }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Requests')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Tokens')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Cost')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Unknown')
                                        }}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow
                                        v-for="day in daily"
                                        :key="day.date"
                                    >
                                        <TableCell>{{
                                            formatDate(`${day.date}T12:00:00`, {
                                                dateStyle: 'medium',
                                            })
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatNumber(day.requests)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatNumber(day.tokens)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatMoney(day.cost)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            day.unknown
                                        }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Budgets') }}</CardTitle>
                    <CardDescription>{{
                        t(
                            'Current period. Hard limits block calls before they run.',
                        )
                    }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div
                        v-for="budget in budgets"
                        :key="budget.id"
                        class="grid gap-1.5"
                    >
                        <div
                            class="flex items-center justify-between gap-2 text-sm"
                        >
                            <span class="font-medium">{{ budget.name }}</span>
                            <Badge
                                :variant="
                                    budget.hard_limit ? 'default' : 'outline'
                                "
                                >{{
                                    budget.hard_limit
                                        ? t('Blocks')
                                        : t('Alerts')
                                }}</Badge
                            >
                        </div>
                        <Progress
                            :model-value="Math.min(100, budget.percent)"
                            :aria-label="budget.name"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ formatMoney(budget.spent) }} /
                            {{ formatMoney(budget.limit) }} ({{
                                budget.percent
                            }}%) · {{ budget.period_key }}
                            <template v-if="Number(budget.reserved) > 0">
                                ·
                                {{
                                    t(':amount reserved', {
                                        amount: formatMoney(budget.reserved),
                                    })
                                }}</template
                            >
                        </p>
                    </div>
                    <p
                        v-if="!budgets.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('No active budgets.') }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card class="min-w-0 xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ t('Breakdown') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <Tabs default-value="feature">
                        <TabsList class="h-auto flex-wrap">
                            <TabsTrigger
                                v-for="tab in breakdownTabs"
                                :key="tab.key"
                                :value="tab.key"
                                >{{ t(tab.label) }}</TabsTrigger
                            >
                        </TabsList>
                        <TabsContent
                            v-for="tab in breakdownTabs"
                            :key="tab.key"
                            :value="tab.key"
                            class="overflow-x-auto pt-4"
                        >
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{{
                                            t(tab.label)
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Requests')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Tokens')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Cost')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Unknown')
                                        }}</TableHead>
                                        <TableHead class="text-right">{{
                                            t('Errors')
                                        }}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow
                                        v-for="row in breakdowns[tab.key]"
                                        :key="row.key ?? 'none'"
                                    >
                                        <TableCell class="font-medium">{{
                                            tab.key === 'feature'
                                                ? t(
                                                      featureLabels[
                                                          row.label
                                                      ] ?? row.label,
                                                  )
                                                : row.label
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatNumber(row.requests)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatNumber(row.tokens)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            formatMoney(row.cost)
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            row.unknown
                                        }}</TableCell>
                                        <TableCell class="text-right">{{
                                            row.errors
                                        }}</TableCell>
                                    </TableRow>
                                    <TableEmpty
                                        v-if="!breakdowns[tab.key].length"
                                        :colspan="6"
                                        >{{
                                            t('No data in this range.')
                                        }}</TableEmpty
                                    >
                                </TableBody>
                            </Table>
                        </TabsContent>
                    </Tabs>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Errors by type') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ul class="grid gap-2 text-sm">
                        <li
                            v-for="error in errors"
                            :key="error.type"
                            class="flex justify-between"
                        >
                            <span>{{ t(error.type) }}</span>
                            <span class="font-medium">{{ error.count }}</span>
                        </li>
                        <li v-if="!errors.length" class="text-muted-foreground">
                            {{ t('No errors in this range.') }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Ledger') }}</CardTitle>
                <CardDescription>{{
                    t(
                        'Logical requests; retries and failovers are recorded as attempts.',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-4 overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('Feature') }}</TableHead>
                            <TableHead>{{ t('Model') }}</TableHead>
                            <TableHead class="hidden md:table-cell">{{
                                t('User')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Tokens')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Cost')
                            }}</TableHead>
                            <TableHead>{{ t('Status') }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="request in requests.data"
                            :key="request.id"
                        >
                            <TableCell
                                class="text-sm whitespace-nowrap text-muted-foreground"
                                >{{ formatDate(request.created_at) }}</TableCell
                            >
                            <TableCell class="text-sm">{{
                                t(
                                    featureLabels[request.feature] ??
                                        request.feature,
                                )
                            }}</TableCell>
                            <TableCell class="text-sm">
                                {{ request.model ?? '—' }}
                                <div class="text-xs text-muted-foreground">
                                    {{ request.connection }}
                                </div>
                            </TableCell>
                            <TableCell class="hidden text-sm md:table-cell">{{
                                request.user?.name ?? '—'
                            }}</TableCell>
                            <TableCell class="text-right text-sm">{{
                                formatNumber(
                                    request.input_tokens +
                                        request.output_tokens,
                                )
                            }}</TableCell>
                            <TableCell class="text-right text-sm">
                                {{
                                    request.cost_status === 'estimated'
                                        ? `≈ ${formatCost(request.estimated_cost)}`
                                        : formatCost(
                                              request.reconciled_cost ??
                                                  request.cost,
                                          )
                                }}
                                <div class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            costStatusLabels[
                                                request.cost_status
                                            ] ?? request.cost_status,
                                        )
                                    }}
                                </div>
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="
                                        request.status === 'succeeded'
                                            ? 'secondary'
                                            : request.status === 'running'
                                              ? 'outline'
                                              : 'destructive'
                                    "
                                    :title="request.error_type ?? ''"
                                    >{{ t(request.status) }}</Badge
                                >
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="!requests.data.length" :colspan="7">{{
                            t('No AI calls in this range.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
                <Pagination :meta="requests.meta" />
            </CardContent>
        </Card>

        <Card v-if="can('ai.budgets.manage') || can('settings.update')">
            <CardHeader>
                <CardTitle>{{ t('Display') }}</CardTitle>
                <CardDescription>{{
                    t(
                        'Converted amounts are only visual and always show their rate, date and source.',
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent class="grid gap-6 md:grid-cols-2">
                <form class="grid gap-4" @submit.prevent="saveSettings">
                    <div class="flex items-center justify-between gap-4">
                        <Label for="dashboard">{{
                            t('Show the dashboard')
                        }}</Label>
                        <Switch
                            id="dashboard"
                            v-model="settingsForm.dashboard"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>{{ t('Display currency') }}</Label>
                        <Select v-model="settingsForm.display_currency">
                            <SelectTrigger class="w-full"
                                ><SelectValue
                            /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="USD">USD</SelectItem>
                                <SelectItem value="CLP">CLP</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        class="justify-self-start"
                        :disabled="settingsForm.processing"
                        >{{ t('Save') }}</Button
                    >
                </form>
                <div class="grid gap-3">
                    <p class="text-sm">
                        <template v-if="exchangeRate">
                            {{
                                t('USD/CLP :rate (:date, :source)', {
                                    rate: exchangeRate.rate,
                                    date: exchangeRate.date,
                                    source: exchangeRate.source,
                                })
                            }}
                        </template>
                        <template v-else>{{
                            t('No exchange rate stored.')
                        }}</template>
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="justify-self-start"
                        :disabled="rateForm.processing"
                        @click="fetchRate"
                        >{{ t('Fetch from mindicador.cl') }}</Button
                    >
                    <form
                        class="flex flex-wrap items-end gap-2"
                        @submit.prevent="saveRate"
                    >
                        <Input
                            v-model="rateForm.rate"
                            type="number"
                            step="any"
                            min="0"
                            class="w-32"
                            :placeholder="t('Rate')"
                        />
                        <Input
                            v-model="rateForm.date"
                            type="date"
                            class="w-40"
                        />
                        <Button
                            type="submit"
                            variant="ghost"
                            :disabled="!rateForm.rate"
                            >{{ t('Save manual rate') }}</Button
                        >
                    </form>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
