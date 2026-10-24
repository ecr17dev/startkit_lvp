<script setup lang="ts">
import { CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import { icons, isIconName } from '@/lib/icons';

/**
 * Icon by name, for names that come from data (see lib/icons.ts). Unknown
 * names render a neutral fallback instead of breaking the page. Decorative
 * by default; pass `label` when the icon carries meaning on its own.
 *
 * <Icon name="users" class="size-4" />
 */
const props = defineProps<{
    name: string;
    label?: string;
}>();

const component = computed(() =>
    isIconName(props.name) ? icons[props.name] : CircleHelp,
);
</script>

<template>
    <component
        :is="component"
        :aria-hidden="label ? undefined : 'true'"
        :aria-label="label"
        :role="label ? 'img' : undefined"
    />
</template>
