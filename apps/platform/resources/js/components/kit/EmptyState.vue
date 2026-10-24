<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Inbox } from '@lucide/vue';
import {
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@starter/ui/components/empty';

/**
 * Placeholder for lists without data: what is missing and the next step.
 *
 * <EmptyState :icon="FolderOpen" :title="t('No projects yet')" :description="t('Create the first one.')">
 *     <Button>{{ t('New project') }}</Button>
 * </EmptyState>
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
        /** Dashed border, for empty areas inside a page. */
        bordered?: boolean;
    }>(),
    { description: '', icon: undefined, bordered: true },
);
</script>

<template>
    <Empty :class="bordered && 'border'">
        <EmptyHeader>
            <EmptyMedia variant="icon">
                <component :is="icon ?? Inbox" />
            </EmptyMedia>
            <EmptyTitle>{{ title }}</EmptyTitle>
            <EmptyDescription v-if="description">
                {{ description }}
            </EmptyDescription>
        </EmptyHeader>
        <EmptyContent v-if="$slots.default">
            <div class="flex flex-wrap justify-center gap-2">
                <slot />
            </div>
        </EmptyContent>
    </Empty>
</template>
