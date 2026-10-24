<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatDate, formatNumber, t } from '@/lib/i18n';

export type ChartSeries = {
    key: string;
    label: string;
    /** Tailwind background class of the series (fixed order, never cycled). */
    color: string;
};

export type SeriesPoint = { day: string } & Record<string, number | string>;

/**
 * Daily bars, stacked when there are several series (parts of one whole).
 * Each day slot is the hover target; a legend is shown from two series.
 */
const props = withDefaults(
    defineProps<{
        points: SeriesPoint[];
        series: ChartSeries[];
        format?: (value: number) => string;
        /** Extra tooltip lines for a day. */
        details?: (point: SeriesPoint) => string[];
        /** Shown over the plot when every day is zero. */
        empty?: string;
    }>(),
    {
        format: (value: number) => formatNumber(value),
        details: undefined,
        empty: undefined,
    },
);

const value = (point: SeriesPoint, key: string) => Number(point[key] ?? 0);

const totals = computed(() =>
    props.points.map((point) =>
        props.series.reduce((sum, item) => sum + value(point, item.key), 0),
    ),
);

const max = computed(() => {
    const top = Math.max(0, ...totals.value);

    if (top === 0) {
        return 1;
    }

    // Round the axis up to a clean number...
    const magnitude = 10 ** Math.floor(Math.log10(top));

    return Math.ceil(top / magnitude) * magnitude;
});

const ticks = computed(() => [max.value, max.value / 2, 0]);
const isEmpty = computed(() => totals.value.every((total) => total === 0));
const active = ref<number | null>(null);

const label = (day: string) =>
    formatDate(`${day}T12:00:00`, { day: 'numeric', month: 'short' });

const xLabels = computed(() => {
    const count = props.points.length;

    if (count === 0) {
        return [];
    }

    return [...new Set([0, Math.floor((count - 1) / 2), count - 1])].map(
        (index) => ({ index, text: label(props.points[index].day) }),
    );
});

// Segments from the baseline up; the top one carries the rounded end...
const segments = (point: SeriesPoint) =>
    props.series
        .map((item) => ({ ...item, value: value(point, item.key) }))
        .filter((item) => item.value > 0);
</script>

<template>
    <figure class="grid gap-2">
        <div
            v-if="series.length > 1"
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span
                v-for="item in series"
                :key="item.key"
                class="flex items-center gap-1.5"
            >
                <span class="size-2.5 rounded-sm" :class="item.color" />
                {{ item.label }}
            </span>
        </div>

        <div class="relative flex h-40 gap-3">
            <div
                class="flex w-14 shrink-0 flex-col justify-between text-right text-[11px] text-muted-foreground"
            >
                <span v-for="tick in ticks" :key="tick" class="leading-none">{{
                    format(tick)
                }}</span>
            </div>

            <div class="relative flex-1">
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                >
                    <div
                        v-for="tick in ticks"
                        :key="tick"
                        class="border-t border-border/60"
                    />
                </div>

                <p
                    v-if="isEmpty && empty"
                    class="absolute inset-0 flex items-center justify-center text-xs text-muted-foreground"
                >
                    {{ empty }}
                </p>

                <div class="absolute inset-0 flex items-end gap-0.5">
                    <div
                        v-for="(point, index) in points"
                        :key="point.day"
                        class="group relative flex h-full flex-1 items-end justify-center outline-none"
                        tabindex="0"
                        :aria-label="`${label(point.day)}: ${format(totals[index])}`"
                        @pointerenter="active = index"
                        @pointerleave="active = null"
                        @focus="active = index"
                        @blur="active = null"
                    >
                        <div
                            class="flex w-full max-w-6 flex-col-reverse gap-0.5 transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                            :style="{
                                height:
                                    totals[index] > 0
                                        ? `max(2px, ${(totals[index] / max) * 100}%)`
                                        : '0px',
                            }"
                        >
                            <div
                                v-for="(segment, position) in segments(point)"
                                :key="segment.key"
                                class="min-h-px"
                                :class="[
                                    segment.color,
                                    position === segments(point).length - 1 &&
                                        'rounded-t-[4px]',
                                ]"
                                :style="{ flexGrow: segment.value }"
                            />
                        </div>

                        <div
                            v-if="active === index"
                            class="pointer-events-none absolute bottom-full z-10 mb-2 w-max rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                            :class="
                                index > points.length / 2 ? 'right-0' : 'left-0'
                            "
                        >
                            <p class="font-medium">{{ label(point.day) }}</p>
                            <p
                                v-for="item in series"
                                :key="item.key"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    v-if="series.length > 1"
                                    class="size-2 rounded-sm"
                                    :class="item.color"
                                />
                                {{ item.label }}:
                                {{ format(value(point, item.key)) }}
                            </p>
                            <p v-if="series.length > 1" class="font-medium">
                                {{ t('Total') }}: {{ format(totals[index]) }}
                            </p>
                            <p
                                v-for="line in details?.(point) ?? []"
                                :key="line"
                                class="text-muted-foreground"
                            >
                                {{ line }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div
            class="relative ml-[4.25rem] h-4 text-[11px] text-muted-foreground"
        >
            <span
                v-for="item in xLabels"
                :key="item.index"
                class="absolute -translate-x-1/2 whitespace-nowrap first:translate-x-0 last:-translate-x-full"
                :style="{
                    left: `${((item.index + 0.5) / points.length) * 100}%`,
                }"
            >
                {{ item.text }}
            </span>
        </div>
    </figure>
</template>
