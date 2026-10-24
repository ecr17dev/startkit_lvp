<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CircleAlert,
    Coins,
    MessageSquare,
    TrendingUp,
    Wallet,
} from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import type { DailyPoint } from '@/components/charts/DailyBarChart.vue';
import DailyBarChart from '@/components/charts/DailyBarChart.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import StatCard from '@/components/StatCard.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import { usage } from '@/routes/admin/ai';
import { update as updateSettings } from '@/routes/admin/ai/settings';
import type { SimplePaginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Usage & costs', href: usage() }],
    },
});

type Log = {
    id: number;
    provider: string;
    model: string;
    source: string;
    agent: string;
    user: string | null;
    prompt: string | null;
    input_tokens: number;
    output_tokens: number;
    cache_read_tokens: number;
    reasoning_tokens: number;
    cost: number;
    priced: boolean;
    created_at: string;
};

const props = defineProps<{
    filters: { period: string; provider: string | null };
    totals: {
        calls: number;
        cost: number;
        input_tokens: number;
        output_tokens: number;
        cache_read_tokens: number;
        unpriced_calls: number;
    };
    daily: DailyPoint[];
    byModel: {
        provider: string;
        model: string;
        calls: number;
        cost: number;
        input_tokens: number;
        output_tokens: number;
        priced: boolean;
    }[];
    byUser: {
        user: { id: number; name: string; email: string } | null;
        calls: number;
        cost: number;
        tokens: number;
    }[];
    recent: SimplePaginated<Log>;
    providers: string[];
    budget: {
        monthly_budget: number | string | null;
        user_monthly_budget: number | string | null;
        enforce_budget: boolean;
        generate_titles: boolean;
        month_spend: number;
    };
}>();

const { can } = usePermissions();

const filters = reactive({
    period: props.filters.period,
    provider: props.filters.provider ?? 'all',
});

watch(filters, () =>
    router.get(
        usage().url,
        {
            period: filters.period,
            provider: filters.provider === 'all' ? undefined : filters.provider,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    ),
);

const periods = [
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: 'month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: '90d', label: 'Last 90 days' },
];

const budgetUsage = computed(() => {
    const budget = Number(props.budget.monthly_budget);

    return budget > 0
        ? Math.min(100, (props.budget.month_spend / budget) * 100)
        : null;
});

const budgetForm = useForm({
    monthly_budget: props.budget.monthly_budget ?? '',
    user_monthly_budget: props.budget.user_monthly_budget ?? '',
    enforce_budget: !!props.budget.enforce_budget,
    generate_titles: props.budget.generate_titles !== false,
});

const saveBudget = () =>
    budgetForm
        .transform((data) => ({
            ...data,
            monthly_budget:
                data.monthly_budget === '' ? null : data.monthly_budget,
            user_monthly_budget:
                data.user_monthly_budget === ''
                    ? null
                    : data.user_monthly_budget,
        }))
        .put(updateSettings().url, { preserveScroll: true });
</script>

<template>
    <Head :title="t('Usage & costs')" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="t('Usage & costs')"
            :description="t('Tokens and estimated cost of every AI call')"
        >
            <Select v-model="filters.provider">
                <SelectTrigger class="w-40">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{{
                        t('All providers')
                    }}</SelectItem>
                    <SelectItem
                        v-for="provider in providers"
                        :key="provider"
                        :value="provider"
                        >{{ provider }}</SelectItem
                    >
                </SelectContent>
            </Select>
            <Select v-model="filters.period">
                <SelectTrigger class="w-44">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="period in periods"
                        :key="period.value"
                        :value="period.value"
                        >{{ t(period.label) }}</SelectItem
                    >
                </SelectContent>
            </Select>
        </PageHeader>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                :title="t('Estimated cost')"
                :value="formatMoney(totals.cost)"
                :icon="Wallet"
            />
            <StatCard
                :title="t('Calls')"
                :value="formatNumber(totals.calls)"
                :icon="MessageSquare"
            />
            <StatCard
                :title="t('Input tokens')"
                :value="formatNumber(totals.input_tokens)"
                :hint="
                    t(':count read from cache', {
                        count: formatNumber(totals.cache_read_tokens),
                    })
                "
                :icon="Coins"
            />
            <StatCard
                :title="t('Output tokens')"
                :value="formatNumber(totals.output_tokens)"
                :icon="TrendingUp"
            />
        </div>

        <Alert v-if="totals.unpriced_calls > 0">
            <CircleAlert class="size-4" />
            <AlertDescription>
                {{
                    t(
                        ':count calls used models without a price. Configure their prices to include them in the costs.',
                        {
                            count: formatNumber(totals.unpriced_calls),
                        },
                    )
                }}
            </AlertDescription>
        </Alert>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Daily cost') }}</CardTitle>
                <CardDescription>{{
                    t('USD per day for the selected period')
                }}</CardDescription>
            </CardHeader>
            <CardContent>
                <DailyBarChart :points="daily" />
            </CardContent>
        </Card>

        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('By model') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('Model') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Calls')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Tokens')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Cost')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="row in byModel"
                                :key="`${row.provider}-${row.model}`"
                            >
                                <TableCell>
                                    <div class="font-mono text-xs">
                                        {{ row.model }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ row.provider }}
                                        <Badge
                                            v-if="!row.priced"
                                            variant="outline"
                                            class="ml-1"
                                            >{{ t('No price') }}</Badge
                                        >
                                    </div>
                                </TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.calls)
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(
                                        row.input_tokens + row.output_tokens,
                                    )
                                }}</TableCell>
                                <TableCell class="text-right font-medium">{{
                                    formatMoney(row.cost)
                                }}</TableCell>
                            </TableRow>
                            <TableEmpty v-if="!byModel.length" :colspan="4">{{
                                t('No usage yet.')
                            }}</TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Top users') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ t('User') }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Calls')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Tokens')
                                }}</TableHead>
                                <TableHead class="text-right">{{
                                    t('Cost')
                                }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="(row, index) in byUser"
                                :key="row.user?.id ?? `system-${index}`"
                            >
                                <TableCell>
                                    <div class="font-medium">
                                        {{
                                            row.user?.name ??
                                            t('System / queue')
                                        }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ row.user?.email }}
                                    </div>
                                </TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.calls)
                                }}</TableCell>
                                <TableCell class="text-right">{{
                                    formatNumber(row.tokens)
                                }}</TableCell>
                                <TableCell class="text-right font-medium">{{
                                    formatMoney(row.cost)
                                }}</TableCell>
                            </TableRow>
                            <TableEmpty v-if="!byUser.length" :colspan="4">{{
                                t('No usage yet.')
                            }}</TableEmpty>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Recent calls') }}</CardTitle>
            </CardHeader>
            <CardContent class="grid gap-4">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Date') }}</TableHead>
                            <TableHead>{{ t('User') }}</TableHead>
                            <TableHead>{{ t('Model') }}</TableHead>
                            <TableHead class="hidden lg:table-cell">{{
                                t('Source')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('In / Out')
                            }}</TableHead>
                            <TableHead class="text-right">{{
                                t('Cost')
                            }}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="log in recent.data" :key="log.id">
                            <TableCell class="text-xs whitespace-nowrap">{{
                                formatDate(log.created_at)
                            }}</TableCell>
                            <TableCell class="text-sm">{{
                                log.user ?? '—'
                            }}</TableCell>
                            <TableCell>
                                <div class="font-mono text-xs">
                                    {{ log.model }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ log.provider }} ·
                                    {{ log.prompt ?? log.agent }}
                                </div>
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">
                                <Badge variant="secondary">{{
                                    log.source
                                }}</Badge>
                            </TableCell>
                            <TableCell
                                class="text-right text-xs whitespace-nowrap"
                            >
                                {{ formatNumber(log.input_tokens) }} /
                                {{ formatNumber(log.output_tokens) }}
                            </TableCell>
                            <TableCell class="text-right text-sm">
                                {{ log.priced ? formatMoney(log.cost) : '—' }}
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-if="!recent.data.length" :colspan="6">{{
                            t('No usage yet.')
                        }}</TableEmpty>
                    </TableBody>
                </Table>
                <Pagination :meta="recent" />
            </CardContent>
        </Card>

        <Card v-if="can('ai.providers.manage')">
            <CardHeader>
                <CardTitle>{{ t('Budget & limits') }}</CardTitle>
                <CardDescription>
                    {{ t('Spent this month') }}:
                    {{ formatMoney(budget.month_spend) }}
                    <template v-if="budget.monthly_budget">
                        / {{ formatMoney(Number(budget.monthly_budget)) }}
                    </template>
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="budgetUsage !== null"
                    class="mb-6 h-2 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        class="h-full rounded-full"
                        :class="
                            budgetUsage >= 90 ? 'bg-destructive' : 'bg-primary'
                        "
                        :style="{ width: `${budgetUsage}%` }"
                    />
                </div>

                <form class="grid gap-6" @submit.prevent="saveBudget">
                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="monthly_budget">{{
                                t('Monthly budget (USD)')
                            }}</Label>
                            <Input
                                id="monthly_budget"
                                v-model="budgetForm.monthly_budget"
                                type="number"
                                min="0"
                                step="0.01"
                                :placeholder="t('No limit')"
                            />
                            <InputError
                                :message="budgetForm.errors.monthly_budget"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="user_monthly_budget">{{
                                t('Monthly budget per user (USD)')
                            }}</Label>
                            <Input
                                id="user_monthly_budget"
                                v-model="budgetForm.user_monthly_budget"
                                type="number"
                                min="0"
                                step="0.01"
                                :placeholder="t('No limit')"
                            />
                            <InputError
                                :message="budgetForm.errors.user_monthly_budget"
                            />
                        </div>
                    </div>
                    <div class="grid gap-4 rounded-lg border p-4">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <Label for="enforce_budget">{{
                                    t('Block requests over budget')
                                }}</Label>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'When disabled the budget is only informative.',
                                        )
                                    }}
                                </p>
                            </div>
                            <Switch
                                id="enforce_budget"
                                v-model="budgetForm.enforce_budget"
                            />
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <Label for="generate_titles">{{
                                    t('Generate conversation titles with AI')
                                }}</Label>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'Uses the cheapest model of the provider for each new conversation.',
                                        )
                                    }}
                                </p>
                            </div>
                            <Switch
                                id="generate_titles"
                                v-model="budgetForm.generate_titles"
                            />
                        </div>
                    </div>
                    <div>
                        <Button
                            type="submit"
                            :disabled="budgetForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </div>
                </form>
            </CardContent>
        </Card>
    </div>
</template>
