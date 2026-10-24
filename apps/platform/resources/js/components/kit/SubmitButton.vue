<script setup lang="ts">
import type { ButtonVariants } from '@starter/ui/components/button';
import { Button } from '@starter/ui/components/button';
import { Spinner } from '@starter/ui/components/spinner';

/**
 * Submit button that shows a spinner and blocks double submits while the
 * request runs (pass form.processing).
 *
 * <SubmitButton :processing="form.processing">{{ t('Save') }}</SubmitButton>
 */
withDefaults(
    defineProps<{
        processing?: boolean;
        disabled?: boolean;
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
    }>(),
    {
        processing: false,
        disabled: false,
        variant: 'default',
        size: 'default',
    },
);
</script>

<template>
    <Button
        type="submit"
        :variant="variant"
        :size="size"
        :disabled="processing || disabled"
        :aria-busy="processing || undefined"
    >
        <Spinner v-if="processing" />
        <slot />
    </Button>
</template>
