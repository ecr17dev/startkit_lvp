<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@starter/ui/components/card';

defineProps<{
    title: string;
    value: string | number;
    hint?: string;
    icon?: LucideIcon;
}>();
</script>

<template>
    <Card class="gap-2 py-5">
        <CardHeader
            class="flex flex-row items-center justify-between space-y-0 px-5"
        >
            <CardTitle class="text-sm font-medium text-muted-foreground">
                {{ title }}
            </CardTitle>
            <component
                :is="icon"
                v-if="icon"
                class="size-4 text-muted-foreground"
            />
        </CardHeader>
        <CardContent class="px-5">
            <div class="text-2xl font-semibold tracking-tight">{{ value }}</div>
            <p v-if="hint" class="mt-1 text-xs text-muted-foreground">
                {{ hint }}
            </p>
        </CardContent>
    </Card>
</template>
