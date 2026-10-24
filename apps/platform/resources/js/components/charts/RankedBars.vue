<script setup lang="ts">
import { computed } from 'vue';
import { formatNumber } from '@/lib/i18n';

/**
 * Horizontal bars of one measure (magnitude, one hue), largest first, with
 * the value written next to each bar.
 */
const props = withDefaults(
    defineProps<{
        rows: { label: string; value: number; hint?: string }[];
        format?: (value: number) => string;
        empty?: string;
        /** Hints only as tooltips (long lists). */
        compact?: boolean;
    }>(),
    {
        format: (value: number) => formatNumber(value),
        empty: '—',
        compact: false,
    },
);

const max = computed(() => Math.max(1, ...props.rows.map((row) => row.value)));
</script>

<template>
    <ul v-if="rows.length" class="grid gap-2.5">
        <li
            v-for="row in rows"
            :key="row.label"
            class="grid gap-1"
            :title="row.hint"
        >
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate">{{ row.label }}</span>
                <span class="shrink-0 font-medium tabular-nums">{{
                    format(row.value)
                }}</span>
            </div>
            <div class="h-2 rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{
                        width:
                            row.value > 0
                                ? `max(4px, ${(row.value / max) * 100}%)`
                                : '0px',
                    }"
                />
            </div>
            <p
                v-if="row.hint && !compact"
                class="text-xs text-muted-foreground"
            >
                {{ row.hint }}
            </p>
        </li>
    </ul>
    <p v-else class="text-sm text-muted-foreground">{{ empty }}</p>
</template>
