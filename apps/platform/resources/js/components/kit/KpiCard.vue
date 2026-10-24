<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowDownRight, ArrowRight, ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import { Card, CardContent } from '@starter/ui/components/card';
import { currentLocale } from '@/lib/i18n';
import Sparkline from './charts/Sparkline.vue';

/**
 * Headline number with an optional change against the previous period and
 * a sparkline. The change carries an arrow and text, never color alone.
 *
 * <KpiCard :title="t('Revenue')" value="$12.4k" :delta="0.082" :points="[...]" />
 */
const props = withDefaults(
    defineProps<{
        title: string;
        value: string | number;
        /** Relative change (0.08 = +8 %). */
        delta?: number | null;
        /** Text after the change, e.g. "vs. last month". */
        deltaLabel?: string;
        /** A decrease is good news (costs, errors, latency). */
        invert?: boolean;
        icon?: LucideIcon;
        points?: number[];
        hint?: string;
    }>(),
    {
        delta: null,
        deltaLabel: '',
        invert: false,
        icon: undefined,
        points: () => [],
        hint: '',
    },
);

const direction = computed(() =>
    props.delta === null || props.delta === 0
        ? 'flat'
        : props.delta > 0
          ? 'up'
          : 'down',
);

const good = computed(
    () =>
        direction.value !== 'flat' &&
        (direction.value === 'up') !== props.invert,
);

const deltaText = computed(() =>
    props.delta === null
        ? ''
        : new Intl.NumberFormat(currentLocale(), {
              style: 'percent',
              maximumFractionDigits: 1,
              signDisplay: 'exceptZero',
          }).format(props.delta),
);
</script>

<template>
    <Card class="gap-0 py-5">
        <CardContent class="grid gap-3 px-5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-medium text-muted-foreground">
                    {{ title }}
                </p>
                <component
                    :is="icon"
                    v-if="icon"
                    class="size-4 text-muted-foreground"
                />
            </div>
            <!-- The sparkline drops below long values instead of overlapping them -->
            <div
                class="flex flex-wrap items-end justify-between gap-x-3 gap-y-2"
            >
                <p class="text-2xl font-semibold tracking-tight">
                    {{ value }}
                </p>
                <Sparkline
                    v-if="points.length > 1"
                    :points="points"
                    :label="title"
                />
            </div>
            <p
                v-if="delta !== null || hint"
                class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
            >
                <span
                    v-if="delta !== null"
                    class="inline-flex items-center gap-0.5 rounded px-1 py-0.5 font-medium text-foreground"
                    :class="
                        direction === 'flat'
                            ? 'bg-muted'
                            : good
                              ? 'bg-success/15'
                              : 'bg-destructive/15'
                    "
                >
                    <ArrowUpRight
                        v-if="direction === 'up'"
                        class="size-3.5"
                        :class="good ? 'text-success' : 'text-destructive'"
                    />
                    <ArrowDownRight
                        v-else-if="direction === 'down'"
                        class="size-3.5"
                        :class="good ? 'text-success' : 'text-destructive'"
                    />
                    <ArrowRight v-else class="size-3.5" />
                    {{ deltaText }}
                </span>
                {{ deltaLabel || hint }}
            </p>
        </CardContent>
    </Card>
</template>
