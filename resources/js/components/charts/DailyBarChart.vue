<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';

export type DailyPoint = {
    day: string;
    cost: number;
    calls: number;
    tokens: number;
};

const props = defineProps<{
    points: DailyPoint[];
}>();

const max = computed(() => {
    const top = Math.max(0, ...props.points.map((point) => point.cost));

    if (top === 0) {
        return 1;
    }

    // Round the axis up to a clean number...
    const magnitude = 10 ** Math.floor(Math.log10(top));

    return Math.ceil(top / magnitude) * magnitude;
});

const ticks = computed(() => [max.value, max.value / 2, 0]);
const active = ref<number | null>(null);

const label = (day: string) =>
    formatDate(`${day}T12:00:00`, { day: 'numeric', month: 'short' });

const xLabels = computed(() => {
    const count = props.points.length;

    if (count === 0) {
        return [];
    }

    return [...new Set([0, Math.floor((count - 1) / 2), count - 1])].map(
        (index) => ({
            index,
            text: label(props.points[index].day),
        }),
    );
});
</script>

<template>
    <figure class="grid gap-2">
        <div class="relative flex h-48 gap-3">
            <!-- Y axis -->
            <div
                class="flex w-14 shrink-0 flex-col justify-between text-right text-[11px] text-muted-foreground"
            >
                <span v-for="tick in ticks" :key="tick" class="leading-none">{{
                    formatMoney(tick, 2)
                }}</span>
            </div>

            <div class="relative flex-1">
                <!-- Gridlines -->
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col justify-between"
                >
                    <div
                        v-for="tick in ticks"
                        :key="tick"
                        class="border-t border-border/60"
                    />
                </div>

                <!-- Columns: each slot is the hit target, the bar is capped at 24px -->
                <div class="absolute inset-0 flex items-end gap-0.5">
                    <div
                        v-for="(point, index) in points"
                        :key="point.day"
                        class="group relative flex h-full flex-1 items-end justify-center outline-none"
                        tabindex="0"
                        :aria-label="`${label(point.day)}: ${formatMoney(point.cost)}`"
                        @pointerenter="active = index"
                        @pointerleave="active = null"
                        @focus="active = index"
                        @blur="active = null"
                    >
                        <div
                            class="w-full max-w-6 rounded-t-[4px] bg-primary transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                            :style="{
                                height:
                                    point.cost > 0
                                        ? `max(2px, ${(point.cost / max) * 100}%)`
                                        : '0px',
                            }"
                        />

                        <div
                            v-if="active === index"
                            class="pointer-events-none absolute bottom-full z-10 mb-2 w-max rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md"
                            :class="
                                index > points.length / 2 ? 'right-0' : 'left-0'
                            "
                        >
                            <p class="font-medium">{{ label(point.day) }}</p>
                            <p>
                                {{ t('Cost') }}: {{ formatMoney(point.cost) }}
                            </p>
                            <p>
                                {{ t('Calls') }}:
                                {{ formatNumber(point.calls) }}
                            </p>
                            <p>
                                {{ t('Tokens') }}:
                                {{ formatNumber(point.tokens) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- X axis -->
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
