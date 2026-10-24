<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatDate, t } from '@/lib/i18n';

/**
 * Single series cumulative line (2px) with a hover crosshair and tooltip.
 */
const props = defineProps<{
    points: { date: string; value: number }[];
    format: (value: number) => string;
    /** Accessible name of the chart (the cumulative cost by default). */
    ariaLabel?: string;
    /** Text under the middle of the axis (the top value by default). */
    summary?: (max: number) => string;
}>();

const width = 600;
const height = 160;
const active = ref<number | null>(null);

const max = computed(
    () => Math.max(0, ...props.points.map((point) => point.value)) || 1,
);

const coordinates = computed(() =>
    props.points.map((point, index) => ({
        x:
            props.points.length > 1
                ? (index / (props.points.length - 1)) * width
                : width / 2,
        y: height - (point.value / max.value) * (height - 8) - 4,
        ...point,
    })),
);

const path = computed(() =>
    coordinates.value
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${point.x.toFixed(1)},${point.y.toFixed(1)}`,
        )
        .join(' '),
);

const onMove = (event: PointerEvent) => {
    const target = event.currentTarget as SVGElement;
    const rect = target.getBoundingClientRect();
    const ratio = (event.clientX - rect.left) / rect.width;

    active.value = Math.min(
        props.points.length - 1,
        Math.max(0, Math.round(ratio * (props.points.length - 1))),
    );
};

const label = (date: string) =>
    formatDate(`${date}T12:00:00`, { day: 'numeric', month: 'short' });
</script>

<template>
    <figure class="grid gap-2">
        <div class="relative">
            <svg
                :viewBox="`0 0 ${width} ${height}`"
                preserveAspectRatio="none"
                class="h-40 w-full overflow-visible"
                role="img"
                :aria-label="ariaLabel ?? t('Cumulative cost')"
                @pointermove="onMove"
                @pointerleave="active = null"
            >
                <line
                    v-for="tick in [0, 0.5, 1]"
                    :key="tick"
                    x1="0"
                    :x2="width"
                    :y1="height - tick * (height - 8) - 4"
                    :y2="height - tick * (height - 8) - 4"
                    class="stroke-border/60"
                    stroke-width="1"
                    vector-effect="non-scaling-stroke"
                />
                <path
                    :d="path"
                    fill="none"
                    class="stroke-primary"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
                <template v-if="active !== null && coordinates[active]">
                    <line
                        :x1="coordinates[active].x"
                        :x2="coordinates[active].x"
                        y1="0"
                        :y2="height"
                        class="stroke-muted-foreground/50"
                        stroke-width="1"
                        vector-effect="non-scaling-stroke"
                    />
                </template>
            </svg>
            <div
                v-if="active !== null && coordinates[active]"
                class="pointer-events-none absolute top-0 rounded-md border bg-popover px-2 py-1 text-xs shadow-sm"
                :style="{
                    left: `min(calc(${(coordinates[active].x / width) * 100}% + 8px), calc(100% - 9rem))`,
                }"
            >
                <div class="text-muted-foreground">
                    {{ label(coordinates[active].date) }}
                </div>
                <div class="font-medium">
                    {{ format(coordinates[active].value) }}
                </div>
            </div>
        </div>
        <div
            v-if="points.length"
            class="flex justify-between text-[11px] text-muted-foreground"
        >
            <span>{{ label(points[0].date) }}</span>
            <span>{{ summary ? summary(max) : format(max) }}</span>
            <span>{{ label(points[points.length - 1].date) }}</span>
        </div>
    </figure>
</template>
