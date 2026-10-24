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
import { formatNumber } from '@/lib/i18n';
import ChartFrame from './ChartFrame.vue';

/**
 * Lines (or areas) over an ordered index such as days or months. A single
 * y axis from zero; the crosshair follows the pointer or the arrow keys.
 *
 * <LineChart :data="rows" index="day" :series="[{ key: 'visits', label: t('Visits') }]" area />
 */
const props = withDefaults(
    defineProps<{
        data: ChartDatum[];
        index: string;
        series: ChartSeries[];
        /** Fill the area under each line. */
        area?: boolean;
        format?: (value: number) => string;
        formatLabel?: (value: string) => string;
        height?: string;
        tableToggle?: boolean;
    }>(),
    {
        area: false,
        format: (value: number) => formatNumber(value),
        formatLabel: (value: string) => value,
        height: 'h-56',
        tableToggle: true,
    },
);

const colored = computed(() => withColors(props.series));
const label = (datum: ChartDatum) =>
    props.formatLabel(String(datum[props.index] ?? ''));

const max = computed(() =>
    niceMax(
        Math.max(
            0,
            ...props.data.flatMap((datum) =>
                props.series.map((item) => numeric(datum, item.key)),
            ),
        ),
    ),
);

const ticks = computed(() => axisTicks(max.value, 4));
const labels = computed(() =>
    spreadIndexes(props.data.length, props.data.length <= 8 ? 8 : 5),
);

const x = (position: number) =>
    props.data.length > 1 ? (position / (props.data.length - 1)) * 100 : 50;
const y = (value: number) => 100 - (value / max.value) * 100;

const paths = computed(() =>
    colored.value.map((item) => {
        const points = props.data.map(
            (datum, position) =>
                `${x(position)},${y(numeric(datum, item.key))}`,
        );

        return {
            ...item,
            line: `M${points.join('L')}`,
            area: `M${x(0)},100L${points.join('L')}L${x(props.data.length - 1)},100Z`,
        };
    }),
);

const active = ref<number | null>(null);
const plot = ref<HTMLElement | null>(null);

function track(event: PointerEvent) {
    const box = plot.value?.getBoundingClientRect();

    if (!box || props.data.length === 0) {
        return;
    }

    const ratio = Math.min(
        1,
        Math.max(0, (event.clientX - box.left) / box.width),
    );
    active.value = Math.round(ratio * (props.data.length - 1));
}

function step(delta: number) {
    const last = props.data.length - 1;
    active.value = Math.min(last, Math.max(0, (active.value ?? last) + delta));
}

// What a screen reader announces for the focused point...
const summary = computed(() => {
    const names = props.series.map((item) => item.label).join(', ');
    const datum = active.value === null ? undefined : props.data[active.value];

    if (!datum) {
        return names;
    }

    const values = props.series
        .map(
            (item) => `${item.label} ${props.format(numeric(datum, item.key))}`,
        )
        .join(', ');

    return `${names} — ${label(datum)}: ${values}`;
});

const table = computed(() => ({
    columns: [props.index, ...props.series.map((item) => item.label)],
    rows: props.data.map((datum) => [
        label(datum),
        ...props.series.map((item) => props.format(numeric(datum, item.key))),
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
        <div class="grid gap-2">
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

                <div
                    ref="plot"
                    class="relative flex-1 outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    tabindex="0"
                    role="img"
                    :aria-label="summary"
                    @pointermove="track"
                    @pointerleave="active = null"
                    @focus="active = data.length - 1"
                    @blur="active = null"
                    @keydown.left.prevent="step(-1)"
                    @keydown.right.prevent="step(1)"
                >
                    <div
                        class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                    >
                        <div
                            v-for="tick in ticks"
                            :key="tick"
                            class="border-t border-border/60 last:border-border"
                        />
                    </div>

                    <svg
                        class="absolute inset-0 size-full overflow-visible"
                        viewBox="0 0 100 100"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <template v-for="item in paths" :key="item.key">
                            <path
                                v-if="area"
                                :d="item.area"
                                :fill="item.color"
                                fill-opacity="0.12"
                            />
                            <path
                                :d="item.line"
                                fill="none"
                                :stroke="item.color"
                                stroke-width="2"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                                vector-effect="non-scaling-stroke"
                            />
                        </template>
                    </svg>

                    <template v-if="active !== null && data[active]">
                        <div
                            class="pointer-events-none absolute inset-y-0 border-l border-foreground/30"
                            :style="{ left: `${x(active)}%` }"
                        />
                        <span
                            v-for="item in colored"
                            :key="item.key"
                            class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-background"
                            :style="{
                                left: `${x(active)}%`,
                                top: `${y(numeric(data[active], item.key))}%`,
                                background: item.color,
                            }"
                        />
                        <div
                            class="pointer-events-none absolute top-0 z-10 w-max rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                            :style="
                                x(active) > 50
                                    ? {
                                          right: `calc(${100 - x(active)}% + 0.75rem)`,
                                      }
                                    : { left: `calc(${x(active)}% + 0.75rem)` }
                            "
                        >
                            <p class="mb-1 font-medium">
                                {{ label(data[active]) }}
                            </p>
                            <p
                                v-for="item in colored"
                                :key="item.key"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    v-if="series.length > 1"
                                    class="size-2 rounded-full"
                                    :style="{ background: item.color }"
                                />
                                {{ item.label }}:
                                <span class="font-medium tabular-nums">{{
                                    format(numeric(data[active], item.key))
                                }}</span>
                            </p>
                        </div>
                    </template>
                </div>
            </div>

            <div class="relative ml-15 h-4 text-[11px] text-muted-foreground">
                <span
                    v-for="position in labels"
                    :key="position"
                    class="absolute whitespace-nowrap"
                    :class="
                        position === 0
                            ? ''
                            : position === data.length - 1
                              ? '-translate-x-full'
                              : '-translate-x-1/2'
                    "
                    :style="{ left: `${x(position)}%` }"
                >
                    {{ label(data[position]) }}
                </span>
            </div>
        </div>
    </ChartFrame>
</template>
