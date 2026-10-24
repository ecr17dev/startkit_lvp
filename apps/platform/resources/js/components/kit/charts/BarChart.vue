<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ChartDatum, ChartSeries } from '@/lib/charts';
import {
    axisTicks,
    niceMax,
    numeric,
    spreadIndexes,
    withColors,
} from '@/lib/charts';
import { formatNumber, t } from '@/lib/i18n';
import ChartFrame from './ChartFrame.vue';

/**
 * Bars by category: grouped (default) or stacked series, vertical or
 * horizontal (long category names, rankings). Hover or focus a category
 * for its values; a table view is always one click away.
 *
 * <BarChart :data="rows" index="month" :series="[{ key: 'total', label: t('Total') }]" />
 */
const props = withDefaults(
    defineProps<{
        data: ChartDatum[];
        /** Property holding the category (x axis). */
        index: string;
        series: ChartSeries[];
        stacked?: boolean;
        horizontal?: boolean;
        format?: (value: number) => string;
        formatLabel?: (category: string) => string;
        /** Height of the plot (Tailwind class) in vertical mode. */
        height?: string;
        /** Direct value labels (single series, few categories). */
        showValues?: boolean;
        tableToggle?: boolean;
    }>(),
    {
        stacked: false,
        horizontal: false,
        format: (value: number) => formatNumber(value),
        formatLabel: (category: string) => category,
        height: 'h-56',
        showValues: false,
        tableToggle: true,
    },
);

const colored = computed(() => withColors(props.series));
const category = (datum: ChartDatum) =>
    props.formatLabel(String(datum[props.index] ?? ''));
const total = (datum: ChartDatum) =>
    props.series.reduce((sum, item) => sum + numeric(datum, item.key), 0);

const max = computed(() =>
    niceMax(
        Math.max(
            0,
            ...props.data.map((datum) =>
                props.stacked
                    ? total(datum)
                    : Math.max(
                          ...props.series.map((item) =>
                              numeric(datum, item.key),
                          ),
                      ),
            ),
        ),
    ),
);

const ticks = computed(() => axisTicks(max.value, 4));
const labels = computed(() =>
    spreadIndexes(props.data.length, props.data.length > 8 ? 5 : 12),
);
const active = ref<number | null>(null);
const percent = (value: number) => `${(value / max.value) * 100}%`;

const table = computed(() => ({
    columns: [
        props.index,
        ...props.series.map((item) => item.label),
        ...(props.stacked && props.series.length > 1 ? [t('Total')] : []),
    ],
    rows: props.data.map((datum) => [
        category(datum),
        ...props.series.map((item) => props.format(numeric(datum, item.key))),
        ...(props.stacked && props.series.length > 1
            ? [props.format(total(datum))]
            : []),
    ]),
}));
</script>

<template>
    <ChartFrame
        :legend="colored"
        :columns="table.columns"
        :rows="table.rows"
        :table-toggle="tableToggle"
    >
        <!-- Horizontal: one row per category, label | bar | value -->
        <div v-if="horizontal" class="grid gap-2">
            <div
                v-for="(datum, position) in data"
                :key="position"
                class="group relative grid grid-cols-[minmax(0,8rem)_1fr_4rem] items-center gap-3 text-sm outline-none"
                tabindex="0"
                @pointerenter="active = position"
                @pointerleave="active = null"
                @focus="active = position"
                @blur="active = null"
            >
                <span class="truncate text-muted-foreground">{{
                    category(datum)
                }}</span>
                <div
                    v-if="stacked || series.length === 1"
                    class="flex h-4 gap-0.5"
                >
                    <div
                        v-for="(item, slot) in colored"
                        :key="item.key"
                        class="h-full min-w-px transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                        :class="
                            slot === colored.length - 1 && 'rounded-r-[4px]'
                        "
                        :style="{
                            background: item.color,
                            width: percent(numeric(datum, item.key)),
                        }"
                    />
                </div>
                <div v-else class="grid gap-0.5">
                    <div
                        v-for="item in colored"
                        :key="item.key"
                        class="h-2.5 min-w-px rounded-r-[4px] transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                        :style="{
                            background: item.color,
                            width: percent(numeric(datum, item.key)),
                        }"
                    />
                </div>
                <span class="text-right font-medium tabular-nums">{{
                    stacked || series.length === 1 ? format(total(datum)) : ''
                }}</span>

                <div
                    v-if="active === position && series.length > 1"
                    class="pointer-events-none absolute bottom-full left-32 z-10 mb-1 w-max rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                >
                    <p
                        v-for="item in colored"
                        :key="item.key"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-2 rounded-sm"
                            :style="{ background: item.color }"
                        />
                        {{ item.label }}:
                        <span class="font-medium tabular-nums">{{
                            format(numeric(datum, item.key))
                        }}</span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Vertical -->
        <div v-else class="grid gap-2">
            <div class="flex gap-3" :class="height">
                <div
                    class="flex w-12 shrink-0 flex-col justify-between text-right text-[11px] text-muted-foreground tabular-nums"
                >
                    <span
                        v-for="tick in ticks"
                        :key="tick"
                        class="leading-none"
                    >
                        {{ format(tick) }}
                    </span>
                </div>
                <div class="relative flex-1">
                    <div
                        class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                    >
                        <div
                            v-for="tick in ticks"
                            :key="tick"
                            class="border-t border-border/60 last:border-border"
                        />
                    </div>

                    <div class="absolute inset-0 flex items-end gap-1">
                        <div
                            v-for="(datum, position) in data"
                            :key="position"
                            class="group relative flex h-full flex-1 items-end justify-center outline-none"
                            tabindex="0"
                            :aria-label="`${category(datum)}: ${series
                                .map(
                                    (item) =>
                                        `${item.label} ${format(numeric(datum, item.key))}`,
                                )
                                .join(', ')}`"
                            @pointerenter="active = position"
                            @pointerleave="active = null"
                            @focus="active = position"
                            @blur="active = null"
                        >
                            <!-- Stacked: one column, segments from the baseline -->
                            <div
                                v-if="stacked"
                                class="flex w-full max-w-10 flex-col-reverse gap-0.5 transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                                :style="{ height: percent(total(datum)) }"
                            >
                                <div
                                    v-for="(item, slot) in colored"
                                    :key="item.key"
                                    class="min-h-px"
                                    :class="
                                        slot === colored.length - 1 &&
                                        'rounded-t-[4px]'
                                    "
                                    :style="{
                                        background: item.color,
                                        flexGrow: numeric(datum, item.key),
                                    }"
                                />
                            </div>

                            <!-- Grouped: side by side, 2px apart -->
                            <div
                                v-else
                                class="flex h-full w-full max-w-16 items-end justify-center gap-0.5"
                            >
                                <div
                                    v-for="item in colored"
                                    :key="item.key"
                                    class="relative flex-1 rounded-t-[4px] transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                                    :style="{
                                        background: item.color,
                                        height: percent(
                                            numeric(datum, item.key),
                                        ),
                                    }"
                                >
                                    <span
                                        v-if="showValues && series.length === 1"
                                        class="absolute -top-5 left-1/2 -translate-x-1/2 text-[11px] font-medium whitespace-nowrap tabular-nums"
                                    >
                                        {{ format(numeric(datum, item.key)) }}
                                    </span>
                                </div>
                            </div>

                            <div
                                v-if="active === position"
                                class="pointer-events-none absolute bottom-full z-10 mb-2 w-max rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                                :class="
                                    position > data.length / 2
                                        ? 'right-0'
                                        : 'left-0'
                                "
                            >
                                <p class="mb-1 font-medium">
                                    {{ category(datum) }}
                                </p>
                                <p
                                    v-for="item in colored"
                                    :key="item.key"
                                    class="flex items-center gap-1.5"
                                >
                                    <span
                                        v-if="series.length > 1"
                                        class="size-2 rounded-sm"
                                        :style="{ background: item.color }"
                                    />
                                    {{ item.label }}:
                                    <span class="font-medium tabular-nums">{{
                                        format(numeric(datum, item.key))
                                    }}</span>
                                </p>
                                <p
                                    v-if="stacked && series.length > 1"
                                    class="mt-1 font-medium"
                                >
                                    {{ t('Total') }}: {{ format(total(datum)) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative ml-15 h-4 text-[11px] text-muted-foreground">
                <span
                    v-for="position in labels"
                    :key="position"
                    class="absolute -translate-x-1/2 whitespace-nowrap"
                    :style="{
                        left: `${((position + 0.5) / data.length) * 100}%`,
                    }"
                >
                    {{ category(data[position]) }}
                </span>
            </div>
        </div>
    </ChartFrame>
</template>
