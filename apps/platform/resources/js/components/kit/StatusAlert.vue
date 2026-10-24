<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Alert,
    AlertDescription,
    AlertTitle,
} from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
import { cn } from '@/lib/utils';
import { t } from '@/lib/i18n';

/**
 * Inline message with a status: info, success, warning or error. The
 * status is told by the icon and the title, not only by the color.
 *
 * <StatusAlert tone="warning" :title="t('Budget almost spent')">
 *     {{ t('You used 92 % of this month budget.') }}
 * </StatusAlert>
 */
const props = withDefaults(
    defineProps<{
        tone?: 'info' | 'success' | 'warning' | 'error';
        title?: string;
        dismissible?: boolean;
        class?: string;
    }>(),
    { tone: 'info', title: '', dismissible: false, class: '' },
);

const emit = defineEmits<{ dismiss: [] }>();

const tones = {
    info: { icon: Info, class: 'border-info/40 bg-info/5 [&>svg]:text-info' },
    success: {
        icon: CircleCheck,
        class: 'border-success/40 bg-success/5 [&>svg]:text-success',
    },
    warning: {
        icon: TriangleAlert,
        class: 'border-warning/60 bg-warning/10 [&>svg]:text-warning',
    },
    error: {
        icon: CircleAlert,
        class: 'border-destructive/40 bg-destructive/5 [&>svg]:text-destructive',
    },
} as const;

const tone = computed(() => tones[props.tone]);
const visible = ref(true);

function dismiss() {
    visible.value = false;
    emit('dismiss');
}
</script>

<template>
    <Alert
        v-if="visible"
        :role="
            props.tone === 'error' || props.tone === 'warning'
                ? 'alert'
                : 'status'
        "
        :class="cn(tone.class, dismissible && 'pr-10', props.class)"
    >
        <component :is="tone.icon" />
        <AlertTitle v-if="title">{{ title }}</AlertTitle>
        <AlertDescription v-if="$slots.default">
            <slot />
        </AlertDescription>
        <Button
            v-if="dismissible"
            variant="ghost"
            size="icon-sm"
            class="absolute top-2 right-2 size-7"
            :aria-label="t('Dismiss')"
            @click="dismiss"
        >
            <X class="size-4" />
        </Button>
    </Alert>
</template>
