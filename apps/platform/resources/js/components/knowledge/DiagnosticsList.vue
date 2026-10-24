<script setup lang="ts">
import { CircleAlert, CircleCheck, CircleMinus, CircleX } from '@lucide/vue';
import { t } from '@/lib/i18n';
import type { DiagnosticCheck } from '@/types';

defineProps<{ checks: DiagnosticCheck[] }>();

const icon = {
    ok: CircleCheck,
    warning: CircleAlert,
    error: CircleX,
    skipped: CircleMinus,
};

const color = {
    ok: 'text-emerald-600 dark:text-emerald-400',
    warning: 'text-amber-600 dark:text-amber-400',
    error: 'text-destructive',
    skipped: 'text-muted-foreground',
};
</script>

<template>
    <ul class="grid gap-2" data-test="diagnostics">
        <li
            v-for="check in checks"
            :key="check.key"
            class="flex items-start gap-2 text-sm"
        >
            <component
                :is="icon[check.status]"
                class="mt-0.5 size-4 shrink-0"
                :class="color[check.status]"
                :aria-label="check.status"
            />
            <div class="min-w-0">
                <span class="font-medium">{{ t(check.label) }}</span>
                <span class="text-muted-foreground">
                    — {{ check.message }}</span
                >
                <pre
                    v-if="check.sql"
                    class="mt-1 overflow-x-auto rounded bg-muted px-2 py-1 text-xs"
                    >{{ check.sql }}</pre>
            </div>
        </li>
    </ul>
</template>
