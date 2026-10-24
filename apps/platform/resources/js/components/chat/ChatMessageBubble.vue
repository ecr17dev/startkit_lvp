<script setup lang="ts">
import {
    BookOpen,
    Bot,
    Check,
    Clock,
    ExternalLink,
    Paperclip,
    ThumbsDown,
    ThumbsUp,
    User as UserIcon,
    Wrench,
    X,
} from '@lucide/vue';
import { requestJson } from '@starter/chat';
import { computed, ref, watch } from 'vue';
import type { ChatMessageItem } from '@/composables/useChat';
import { usePermissions } from '@/composables/usePermissions';
import { show as documentShow } from '@/routes/admin/knowledge/documents';
import { feedback as feedbackRoute } from '@/routes/ai/chat';
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

const { can } = usePermissions();

const outcomeNotices: Record<string, string> = {
    abstained:
        'No evidence in the authorized sources: no answer was generated.',
    general: 'General answer: not based on the organization sources.',
    unsupported: 'The answer did not cite the retrieved sources.',
};

// Feedback on grounded answers feeds the knowledge quality indicators...
const rating = ref<number | null>(props.message.knowledge?.feedback ?? null);
const ratingError = ref(false);

watch(
    () => props.message.knowledge?.feedback,
    (value) => (rating.value = value ?? null),
);

const rate = async (value: 1 | -1) => {
    const messageId = props.message.knowledge?.message_id;

    if (!messageId || rating.value === value) {
        return;
    }

    const previous = rating.value;
    rating.value = value;
    ratingError.value = false;

    try {
        await requestJson(feedbackRoute(messageId).url, {
            method: 'POST',
            body: { rating: value },
        });
    } catch {
        rating.value = previous;
        ratingError.value = true;
    }
};

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
                <div
                    v-if="message.knowledge"
                    class="grid gap-1.5 border-t pt-2 text-xs"
                    data-test="chat-knowledge"
                >
                    <p
                        v-if="
                            message.knowledge.outcome &&
                            outcomeNotices[message.knowledge.outcome]
                        "
                        class="flex items-center gap-1.5 text-amber-700 dark:text-amber-400"
                    >
                        <BookOpen class="size-3.5 shrink-0" />
                        {{ t(outcomeNotices[message.knowledge.outcome]) }}
                    </p>
                    <ol
                        v-if="message.knowledge.citations.length"
                        class="grid gap-1"
                    >
                        <li
                            v-for="citation in message.knowledge.citations"
                            :key="citation.n"
                            class="flex items-start gap-1.5 text-muted-foreground"
                            :data-test="`citation-${citation.n}`"
                        >
                            <span class="font-mono font-medium text-foreground"
                                >[{{ citation.n }}]</span
                            >
                            <span class="min-w-0">
                                <a
                                    v-if="
                                        citation.document_id &&
                                        can('rag.documents.read')
                                    "
                                    :href="
                                        documentShow(citation.document_id).url
                                    "
                                    class="font-medium text-foreground hover:underline"
                                    >{{ citation.title }}</a
                                >
                                <span
                                    v-else
                                    class="font-medium text-foreground"
                                    >{{ citation.title }}</span
                                >
                                <template v-if="citation.location">
                                    — {{ citation.location }}</template
                                >
                                <a
                                    v-if="citation.uri"
                                    :href="citation.uri"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="ml-1 inline-flex items-center hover:text-foreground"
                                    :aria-label="t('Open origin')"
                                    ><ExternalLink class="size-3"
                                /></a>
                            </span>
                        </li>
                    </ol>
                    <p
                        v-if="message.knowledge.invalid"
                        class="text-muted-foreground"
                    >
                        {{
                            t(
                                ':n citations did not match a retrieved passage and were discarded.',
                                { n: message.knowledge.invalid },
                            )
                        }}
                    </p>
                    <div
                        v-if="message.knowledge.message_id"
                        class="flex items-center gap-1 text-muted-foreground"
                        data-test="chat-feedback"
                    >
                        <span>{{ t('Was this answer useful?') }}</span>
                        <button
                            type="button"
                            class="rounded p-1 hover:bg-muted hover:text-foreground"
                            :class="
                                rating === 1 &&
                                'text-emerald-600 dark:text-emerald-400'
                            "
                            :aria-pressed="rating === 1"
                            :aria-label="t('Useful')"
                            data-test="chat-feedback-up"
                            @click="rate(1)"
                        >
                            <ThumbsUp class="size-3.5" />
                        </button>
                        <button
                            type="button"
                            class="rounded p-1 hover:bg-muted hover:text-foreground"
                            :class="rating === -1 && 'text-destructive'"
                            :aria-pressed="rating === -1"
                            :aria-label="t('Not useful')"
                            data-test="chat-feedback-down"
                            @click="rate(-1)"
                        >
                            <ThumbsDown class="size-3.5" />
                        </button>
                        <span v-if="rating !== null">{{
                            t('Thanks for your feedback.')
                        }}</span>
                        <span v-if="ratingError" class="text-destructive">{{
                            t('The feedback could not be saved.')
                        }}</span>
                    </div>
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
