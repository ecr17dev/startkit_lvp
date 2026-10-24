<script setup lang="ts">
import { computed, ref } from 'vue';
import { slotColor } from '@/lib/charts';
import { currentLocale, formatNumber, t } from '@/lib/i18n';
import ChartFrame from './ChartFrame.vue';

export type DonutSlice = { label: string; value: number; color?: string };

/**
 * Parts of one whole. Up to `maxSlices` slices (the rest is folded into
 * "Other"), each one labelled with its value and share in the legend.
 *
 * <DonutChart :data="[{ label: 'Email', value: 42 }, { label: 'Chat', value: 18 }]" />
 */
const props = withDefaults(
    defineProps<{
        data: DonutSlice[];
        format?: (value: number) => string;
        /** Text under the total in the middle. */
        centerLabel?: string;
        maxSlices?: number;
        tableToggle?: boolean;
    }>(),
    {
        format: (value: number) => formatNumber(value),
        centerLabel: '',
        maxSlices: 5,
        tableToggle: true,
    },
);

const slices = computed(() => {
    const items = props.data.map((item, index) => ({
        ...item,
        color: item.color ?? slotColor(index),
    }));

    if (items.length <= props.maxSlices) {
        return items;
    }

    const kept = items.slice(0, props.maxSlices - 1);
    const rest = items
        .slice(props.maxSlices - 1)
        .reduce((sum, item) => sum + item.value, 0);

    return [
        ...kept,
        { label: t('Other'), value: rest, color: 'var(--muted-foreground)' },
    ];
});

const total = computed(() =>
    slices.value.reduce((sum, item) => sum + Math.max(0, item.value), 0),
);

const RADIUS = 40;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;
const GAP = 1.2;

const arcs = computed(() => {
    let offset = 0;

    return slices.value.map((item) => {
        const length =
            total.value > 0
                ? (Math.max(0, item.value) / total.value) * CIRCUMFERENCE
                : 0;
        const visible =
            slices.value.length > 1 ? Math.max(0.5, length - GAP) : length;
        const arc = {
            ...item,
            dasharray: `${visible} ${CIRCUMFERENCE - visible}`,
            dashoffset: -offset,
        };
        offset += length;

        return arc;
    });
});

const share = (value: number) =>
    total.value > 0
        ? new Intl.NumberFormat(currentLocale(), {
              style: 'percent',
              maximumFractionDigits: 1,
          }).format(value / total.value)
        : '—';

const active = ref<number | null>(null);
const focus = computed(() =>
    active.value === null ? null : slices.value[active.value],
);

const table = computed(() => ({
    columns: [t('Category'), t('Value'), t('Share')],
    rows: slices.value.map((item) => [
        item.label,
        props.format(item.value),
        share(item.value),
    ]),
}));
</script>

<template>
    <ChartFrame
        :columns="table.columns"
        :rows="table.rows"
        :table-toggle="tableToggle"
    >
        <div class="flex flex-col items-center gap-6 sm:flex-row">
            <div class="relative size-44 shrink-0">
                <svg viewBox="0 0 100 100" class="size-full -rotate-90">
                    <circle
                        cx="50"
                        cy="50"
                        :r="RADIUS"
                        fill="none"
                        class="stroke-muted"
                        stroke-width="14"
                    />
                    <circle
                        v-for="(arc, index) in arcs"
                        :key="arc.label"
                        cx="50"
                        cy="50"
                        :r="RADIUS"
                        fill="none"
                        :stroke="arc.color"
                        :stroke-width="active === index ? 16 : 14"
                        :stroke-dasharray="arc.dasharray"
                        :stroke-dashoffset="arc.dashoffset"
                        class="cursor-pointer transition-[stroke-width,opacity]"
                        :class="
                            active !== null && active !== index && 'opacity-50'
                        "
                        @pointerenter="active = index"
                        @pointerleave="active = null"
                    />
                </svg>
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center"
                >
                    <span class="text-2xl font-semibold tracking-tight">
                        {{ format(focus ? focus.value : total) }}
                    </span>
                    <span
                        class="max-w-24 truncate text-xs text-muted-foreground"
                    >
                        {{ focus ? focus.label : centerLabel || t('Total') }}
                    </span>
                </div>
            </div>

            <ul class="grid w-full gap-1.5 text-sm">
                <li
                    v-for="(item, index) in slices"
                    :key="item.label"
                    class="flex items-center gap-2 rounded-md px-2 py-1 outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    :class="active === index && 'bg-muted'"
                    tabindex="0"
                    @pointerenter="active = index"
                    @pointerleave="active = null"
                    @focus="active = index"
                    @blur="active = null"
                >
                    <span
                        class="size-2.5 shrink-0 rounded-sm"
                        :style="{ background: item.color }"
                    />
                    <span class="min-w-0 flex-1 truncate">{{
                        item.label
                    }}</span>
                    <span class="font-medium tabular-nums">{{
                        format(item.value)
                    }}</span>
                    <span
                        class="w-14 text-right text-xs text-muted-foreground tabular-nums"
                        >{{ share(item.value) }}</span
                    >
                </li>
            </ul>
        </div>
    </ChartFrame>
</template>
