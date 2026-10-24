<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Coins, MessageSquare, Timer, Users } from '@lucide/vue';
import BarChart from '@/components/kit/charts/BarChart.vue';
import DonutChart from '@/components/kit/charts/DonutChart.vue';
import LineChart from '@/components/kit/charts/LineChart.vue';
import Sparkline from '@/components/kit/charts/Sparkline.vue';
import KpiCard from '@/components/kit/KpiCard.vue';
import Example from '@/components/ui-kit/Example.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import { index, show } from '@/routes/ui-kit';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'UI kit', href: index() },
            { title: 'Charts', href: show('charts') },
        ],
    },
});

const months = [
    '2026-01',
    '2026-02',
    '2026-03',
    '2026-04',
    '2026-05',
    '2026-06',
];
const monthLabel = (value: string) =>
    formatDate(`${value}-15T12:00:00Z`, { month: 'short', timeZone: 'UTC' });

const sales = months.map((month, position) => ({
    month,
    online: [42, 51, 48, 63, 70, 82][position] * 1000,
    stores: [38, 36, 41, 40, 44, 47][position] * 1000,
}));

const daily = Array.from({ length: 30 }, (_, position) => {
    const day = new Date(Date.UTC(2026, 8, position + 1));

    return {
        day: day.toISOString().slice(0, 10),
        visits: Math.round(1200 + 400 * Math.sin(position / 4) + position * 18),
        signups: Math.round(80 + 30 * Math.cos(position / 5) + position * 2),
    };
});
const dayLabel = (value: string) =>
    formatDate(`${value}T12:00:00Z`, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

const channels = [
    { label: t('Email'), value: 412 },
    { label: t('Chat'), value: 268 },
    { label: t('Phone'), value: 131 },
    { label: 'WhatsApp', value: 96 },
    { label: t('Web form'), value: 44 },
    { label: t('Other'), value: 21 },
];

const ranking = [
    { team: t('Customer success'), tickets: 182 },
    { team: t('Billing'), tickets: 131 },
    { team: t('Technical support'), tickets: 118 },
    { team: t('Sales'), tickets: 64 },
    { team: t('Onboarding'), tickets: 39 },
];

const money = (value: number) => formatMoney(value).replace(/\.00$/, '');
const compact = (value: number) =>
    formatNumber(Math.round(value / 100) / 10) + 'k';

const kpiCode = `
<KpiCard :title="t('Revenue')" :value="formatMoney(129400)" :delta="0.124"
    :delta-label="t('vs. previous month')" :icon="Coins" :points="[42, 51, 48, 63, 70, 82]" />
<KpiCard :title="t('Response time')" value="2 h 14 min" :delta="-0.08" invert
    :delta-label="t('vs. previous month')" :icon="Timer" />
`;

const barCode = `
<BarChart :data="sales" index="month" :format-label="monthLabel" :format="compact"
    :series="[
        { key: 'online', label: t('Online') },
        { key: 'stores', label: t('Stores') },
    ]" />
<!-- stacked: parts of one whole; horizontal: long names or rankings -->
<BarChart :data="sales" index="month" :series="series" stacked />
<BarChart :data="ranking" index="team" :series="[{ key: 'tickets', label: t('Tickets') }]" horizontal />
`;

const lineCode = `
<LineChart :data="daily" index="day" :format-label="dayLabel"
    :series="[{ key: 'visits', label: t('Visits') }]" area />
`;

const donutCode = `
<DonutChart :data="[{ label: t('Email'), value: 412 }, { label: t('Chat'), value: 268 }]"
    :center-label="t('Tickets')" />
`;
</script>

<template>
    <Head :title="t('Charts')" />

    <Example
        :title="t('KPI cards')"
        :description="
            t(
                'Headline number, change with arrow and text (never color alone) and an optional sparkline. Use invert when going down is good.',
            )
        "
        :code="kpiCode"
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <KpiCard
                :title="t('Revenue')"
                :value="money(129400)"
                :delta="0.124"
                :delta-label="t('vs. previous month')"
                :icon="Coins"
                :points="sales.map((item) => item.online + item.stores)"
            />
            <KpiCard
                :title="t('Active users')"
                :value="formatNumber(4821)"
                :delta="0.031"
                :delta-label="t('vs. previous month')"
                :icon="Users"
                :points="daily.map((item) => item.visits)"
            />
            <KpiCard
                :title="t('Response time')"
                value="2 h 14 min"
                :delta="-0.08"
                :delta-label="t('vs. previous month')"
                :icon="Timer"
                invert
            />
            <KpiCard
                :title="t('Open tickets')"
                :value="formatNumber(87)"
                :delta="0"
                :delta-label="t('no change')"
                :icon="MessageSquare"
            />
        </div>
    </Example>

    <Example
        :title="t('Bar charts')"
        :description="
            t(
                'Compare categories. Grouped by default, stacked for parts of a whole, horizontal for long names.',
            )
        "
        :code="barCode"
    >
        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Sales by channel') }}</CardTitle>
                    <CardDescription>{{ t('Grouped') }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        :data="sales"
                        index="month"
                        :format-label="monthLabel"
                        :format="compact"
                        :series="[
                            { key: 'online', label: t('Online') },
                            { key: 'stores', label: t('Stores') },
                        ]"
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Total sales') }}</CardTitle>
                    <CardDescription>{{ t('Stacked') }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        :data="sales"
                        index="month"
                        :format-label="monthLabel"
                        :format="compact"
                        :series="[
                            { key: 'online', label: t('Online') },
                            { key: 'stores', label: t('Stores') },
                        ]"
                        stacked
                    />
                </CardContent>
            </Card>
            <Card class="xl:col-span-2">
                <CardHeader>
                    <CardTitle>{{ t('Tickets by team') }}</CardTitle>
                    <CardDescription>{{ t('Horizontal') }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <BarChart
                        :data="ranking"
                        index="team"
                        :series="[{ key: 'tickets', label: t('Tickets') }]"
                        horizontal
                    />
                </CardContent>
            </Card>
        </div>
    </Example>

    <Example
        :title="t('Lines and areas')"
        :description="
            t(
                'Change over time. Move the pointer or use the arrow keys over the chart.',
            )
        "
        :code="lineCode"
    >
        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Visits') }}</CardTitle>
                    <CardDescription>{{
                        t('Area, last 30 days')
                    }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <LineChart
                        :data="daily"
                        index="day"
                        :format-label="dayLabel"
                        :series="[{ key: 'visits', label: t('Visits') }]"
                        area
                    />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Online vs. stores') }}</CardTitle>
                    <CardDescription>{{ t('Two series') }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <LineChart
                        :data="sales"
                        index="month"
                        :format-label="monthLabel"
                        :format="compact"
                        :series="[
                            { key: 'online', label: t('Online') },
                            { key: 'stores', label: t('Stores') },
                        ]"
                    />
                </CardContent>
            </Card>
        </div>
    </Example>

    <Example
        :title="t('Donut and sparklines')"
        :description="
            t(
                'Donut for up to five parts of a whole (the rest goes to Other); sparklines for trends inside tiles and tables.',
            )
        "
        :code="donutCode"
    >
        <div class="grid gap-6 xl:grid-cols-2">
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Tickets by channel') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <DonutChart :data="channels" :center-label="t('Tickets')" />
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('Trends') }}</CardTitle>
                </CardHeader>
                <CardContent class="grid gap-3">
                    <div
                        v-for="(item, position) in [
                            {
                                label: t('Visits'),
                                points: daily.map((day) => day.visits),
                            },
                            {
                                label: t('Sign-ups'),
                                points: daily.map((day) => day.signups),
                            },
                            {
                                label: t('Online'),
                                points: sales.map((month) => month.online),
                            },
                        ]"
                        :key="item.label"
                        class="flex items-center justify-between gap-3 text-sm"
                    >
                        <span>{{ item.label }}</span>
                        <Sparkline
                            :points="item.points"
                            :label="item.label"
                            :color="`var(--chart-${position + 1})`"
                        />
                    </div>
                </CardContent>
            </Card>
        </div>
    </Example>
</template>
