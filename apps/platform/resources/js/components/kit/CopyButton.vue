<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';
import { notify } from '@/lib/notify';

/**
 * Copies a value to the clipboard and confirms it with a toast. Never use
 * it for secrets that should not be shown.
 *
 * <CopyButton :value="invitationUrl" />
 */
const props = withDefaults(
    defineProps<{
        value: string;
        label?: string;
        size?: 'icon' | 'icon-sm' | 'sm' | 'default';
    }>(),
    { label: '', size: 'icon-sm' },
);

const { copy, copied, isSupported } = useClipboard({ legacy: true });

async function run() {
    await copy(props.value);
    notify.success(t('Copied to the clipboard.'));
}
</script>

<template>
    <Button
        v-if="isSupported"
        type="button"
        variant="ghost"
        :size="size"
        :aria-label="label || t('Copy')"
        @click="run"
    >
        <Check v-if="copied" class="size-4" />
        <Copy v-else class="size-4" />
        <template v-if="size === 'sm' || size === 'default'">
            {{ label || t('Copy') }}
        </template>
    </Button>
</template>
