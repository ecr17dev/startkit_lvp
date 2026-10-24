<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import type { ButtonVariants } from '@starter/ui/components/button';
import { Button } from '@starter/ui/components/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@starter/ui/components/tooltip';

/**
 * Icon-only button: the label is its accessible name and its tooltip.
 * For navigation, use Button with as-child and a Link instead.
 *
 * <IconButton :icon="Pencil" :label="t('Edit')" @click="edit" />
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        label: string;
        variant?: ButtonVariants['variant'];
        size?: 'icon' | 'icon-sm' | 'icon-lg';
        side?: 'top' | 'right' | 'bottom' | 'left';
    }>(),
    { variant: 'ghost', size: 'icon', side: 'top' },
);
</script>

<template>
    <TooltipProvider :delay-duration="200">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    type="button"
                    :variant="variant"
                    :size="size"
                    :aria-label="label"
                >
                    <component :is="icon" class="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent :side="side">{{ label }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
