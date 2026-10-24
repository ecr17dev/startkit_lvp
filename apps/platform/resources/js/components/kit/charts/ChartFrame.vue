<script setup lang="ts">
import { Table2, ChartNoAxesColumn } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';

/**
 * Common frame of the kit charts: legend (from two series), a switch to a
 * table view (identity never depends on color alone) and the plot slot.
 */
withDefaults(
    defineProps<{
        legend?: { label: string; color: string }[];
        /** Header row of the table view. */
        columns: string[];
        /** Body rows of the table view (already formatted). */
        rows: string[][];
        tableToggle?: boolean;
    }>(),
    { legend: () => [], tableToggle: true },
);

const showTable = ref(false);
</script>

<template>
    <figure class="grid gap-3">
        <div
            v-if="legend.length > 1 || tableToggle"
            class="flex items-start justify-between gap-3"
        >
            <ul
                class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
            >
                <template v-if="legend.length > 1">
                    <li
                        v-for="item in legend"
                        :key="item.label"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="size-2.5 rounded-sm"
                            :style="{ background: item.color }"
                        />
                        {{ item.label }}
                    </li>
                </template>
            </ul>
            <Button
                v-if="tableToggle"
                variant="ghost"
                size="sm"
                class="-mt-1 h-7 shrink-0 px-2 text-xs text-muted-foreground"
                :aria-pressed="showTable"
                @click="showTable = !showTable"
            >
                <component
                    :is="showTable ? ChartNoAxesColumn : Table2"
                    class="size-3.5"
                />
                {{ showTable ? t('Chart') : t('Table') }}
            </Button>
        </div>

        <div v-if="showTable" class="max-h-72 overflow-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-muted">
                    <tr>
                        <th
                            v-for="(column, index) in columns"
                            :key="column"
                            class="px-3 py-1.5 font-medium"
                            :class="index === 0 ? 'text-left' : 'text-right'"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="index"
                        class="border-t"
                    >
                        <td
                            v-for="(cell, position) in row"
                            :key="position"
                            class="px-3 py-1.5 tabular-nums"
                            :class="position === 0 ? 'text-left' : 'text-right'"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template v-else>
            <slot />
            <table class="sr-only">
                <thead>
                    <tr>
                        <th v-for="column in columns" :key="column">
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="index">
                        <td v-for="(cell, position) in row" :key="position">
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </template>
    </figure>
</template>
