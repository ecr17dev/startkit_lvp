<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="space-y-1">
            <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.default" class="flex items-center gap-2">
            <slot />
        </div>
    </div>
</template>
