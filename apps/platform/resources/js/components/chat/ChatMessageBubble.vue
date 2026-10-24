<script setup lang="ts">
import {
    Bot,
    Check,
    Clock,
    Paperclip,
    User as UserIcon,
    Wrench,
    X,
} from '@lucide/vue';
import { computed } from 'vue';
import type { ChatMessageItem } from '@/composables/useChat';
import { costStatusLabels, formatCost } from '@/lib/ai';
import { formatDate, formatNumber, t } from '@/lib/i18n';
import { renderMarkdown } from '@/lib/markdown';
import { cn } from '@/lib/utils';

const props = defineProps<{
    message: ChatMessageItem;
    avatar?: string | null;
    showDetails?: boolean;
    compact?: boolean;
}>();

const tokens = computed(() => {
    const usage = props.message.usage;
    const summary = props.message.summary;

    if (summary?.input_tokens !== undefined && summary?.input_tokens !== null) {
        return (summary.input_tokens ?? 0) + (summary.output_tokens ?? 0);
    }

    return usage ? (usage.input_tokens ?? 0) + (usage.output_tokens ?? 0) : 0;
});

const toolLabels: Record<string, string> = {
    running: 'running',
    done: 'done',
    failed: 'failed',
    denied: 'not confirmed',
    waiting: 'waiting for confirmation',
};
</script>

<template>
    <div
        :class="cn('flex gap-3', message.role === 'user' && 'flex-row-reverse')"
    >
        <div
            v-if="!compact || message.role === 'assistant'"
            class="flex size-8 shrink-0 items-center justify-center rounded-full text-base"
            :class="
                message.role === 'user'
                    ? 'bg-primary text-primary-foreground'
                    : 'bg-muted'
            "
        >
            <UserIcon v-if="message.role === 'user'" class="size-4" />
            <template v-else-if="avatar">{{ avatar }}</template>
            <Bot v-else class="size-4" />
        </div>
        <div
            :class="
                cn(
                    'grid min-w-0 gap-1',
                    message.role === 'user'
                        ? 'max-w-[85%] justify-items-end'
                        : 'flex-1',
                )
            "
        >
            <template v-if="message.role === 'user'">
                <div
                    v-if="message.content"
                    class="rounded-2xl bg-primary px-4 py-2 text-sm whitespace-pre-wrap text-primary-foreground"
                >
                    {{ message.content }}
                </div>
                <div
                    v-if="message.attachments?.length"
                    class="flex flex-wrap justify-end gap-1"
                >
                    <span
                        v-for="(file, index) in message.attachments"
                        :key="index"
                        class="flex items-center gap-1 rounded-md border bg-muted/50 px-2 py-0.5 text-xs"
                    >
                        <Paperclip class="size-3" />
                        {{ file.name ?? t('Attachment') }}
                    </span>
                </div>
            </template>
            <template v-else>
                <div v-if="message.tools?.length" class="flex flex-wrap gap-1">
                    <span
                        v-for="tool in message.tools"
                        :key="tool.id"
                        class="flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] text-muted-foreground"
                    >
                        <Wrench class="size-3" />
                        <code>{{ tool.name }}</code>
                        <Check
                            v-if="tool.status === 'done'"
                            class="size-3 text-emerald-600"
                        />
                        <X
                            v-else-if="
                                tool.status === 'failed' ||
                                tool.status === 'denied'
                            "
                            class="size-3 text-destructive"
                        />
                        <Clock v-else class="size-3" />
                        <span class="sr-only">{{
                            t(toolLabels[tool.status])
                        }}</span>
                    </span>
                </div>
                <div
                    v-if="message.content"
                    class="markdown"
                    v-html="renderMarkdown(message.content)"
                />
                <div
                    v-else-if="!message.error && !message.summary"
                    class="flex gap-1 py-2"
                    :aria-label="t('Thinking...')"
                >
                    <span
                        class="size-2 animate-bounce rounded-full bg-muted-foreground/60"
                    />
                    <span
                        class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:150ms]"
                    />
                    <span
                        class="size-2 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:300ms]"
                    />
                </div>
                <p v-if="message.error" class="text-sm text-destructive">
                    {{ message.error }}
                </p>
                <p
                    v-if="showDetails && (message.model || tokens)"
                    class="text-[11px] text-muted-foreground"
                >
                    <span v-if="message.model" class="font-mono">{{
                        message.model
                    }}</span>
                    <template v-if="message.summary?.provider">
                        · {{ message.summary.provider }}</template
                    >
                    <template v-if="message.summary?.agent_version">
                        · v{{ message.summary.agent_version }}</template
                    >
                    <template v-if="tokens">
                        · {{ formatNumber(tokens) }} {{ t('tokens') }}</template
                    >
                    <template v-if="message.summary?.latency_ms">
                        ·
                        {{ formatNumber(message.summary.latency_ms) }}
                        ms</template
                    >
                    <template v-if="message.summary">
                        · {{ formatCost(message.summary.cost) }}
                        <template
                            v-if="
                                message.summary.cost_status &&
                                message.summary.cost_status !== 'calculated'
                            "
                            >({{
                                t(
                                    costStatusLabels[
                                        message.summary.cost_status
                                    ] ?? message.summary.cost_status,
                                )
                            }})</template
                        ></template
                    >
                    <template v-if="message.created_at">
                        ·
                        {{
                            formatDate(message.created_at, {
                                timeStyle: 'short',
                            })
                        }}</template
                    >
                </p>
            </template>
        </div>
    </div>
</template>
