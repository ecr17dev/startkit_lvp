<script setup lang="ts">
import { Code } from '@lucide/vue';
import { ref } from 'vue';
import CopyButton from '@/components/kit/CopyButton.vue';
import { Button } from '@starter/ui/components/button';
import { t } from '@/lib/i18n';

/**
 * One example of the catalog: title, live preview and the code to copy.
 */
defineProps<{
    title: string;
    description?: string;
    code?: string;
}>();

const showCode = ref(false);
</script>

<template>
    <section class="grid min-w-0 gap-3">
        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0 space-y-0.5">
                <h2 class="font-semibold">{{ title }}</h2>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <Button
                v-if="code"
                variant="ghost"
                size="sm"
                :aria-pressed="showCode"
                @click="showCode = !showCode"
            >
                <Code class="size-4" />
                {{ showCode ? t('Hide code') : t('Code') }}
            </Button>
        </div>
        <div class="min-w-0 rounded-lg border bg-card p-4 md:p-6">
            <slot />
        </div>
        <div v-if="code && showCode" class="relative">
            <pre
                class="max-h-96 overflow-auto rounded-lg border bg-muted/50 p-4 pr-12 text-xs leading-relaxed"
            ><code>{{ code.trim() }}</code></pre>
            <CopyButton :value="code.trim()" class="absolute top-2 right-2" />
        </div>
    </section>
</template>
