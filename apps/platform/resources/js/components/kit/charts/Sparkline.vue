<script setup lang="ts">
import { computed } from 'vue';

/**
 * Tiny trend line without axes, for stat tiles and table cells. The label
 * describes the trend for screen readers.
 *
 * <Sparkline :points="[3, 5, 4, 8, 7]" :label="t('Last 7 days')" />
 */
const props = withDefaults(
    defineProps<{
        points: number[];
        label: string;
        color?: string;
        area?: boolean;
    }>(),
    { color: 'var(--chart-1)', area: true },
);

const coordinates = computed(() => {
    const values = props.points.length ? props.points : [0];
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;

    return values.map((value, index) => ({
        x: values.length > 1 ? (index / (values.length - 1)) * 100 : 50,
        y: 90 - ((value - min) / range) * 80,
    }));
});

const line = computed(
    () =>
        `M${coordinates.value.map((point) => `${point.x},${point.y}`).join('L')}`,
);
const last = computed(() => coordinates.value[coordinates.value.length - 1]);
</script>

<template>
    <div class="relative h-8 w-24 shrink-0" role="img" :aria-label="label">
        <svg
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            class="absolute inset-0 size-full overflow-visible"
            aria-hidden="true"
        >
            <path
                v-if="area"
                :d="`${line}L100,100L0,100Z`"
                :fill="color"
                fill-opacity="0.12"
            />
            <path
                :d="line"
                fill="none"
                :stroke="color"
                stroke-width="2"
                stroke-linejoin="round"
                stroke-linecap="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <span
            class="absolute size-2 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-background"
            :style="{
                left: `${last.x}%`,
                top: `${last.y}%`,
                background: color,
            }"
        />
    </div>
</template>
