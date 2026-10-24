<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bot,
    MessageSquarePlus,
    SendHorizontal,
    Square,
    Trash2,
    User as UserIcon,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
import { ScrollArea } from '@starter/ui/components/scroll-area';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@starter/ui/components/select';
import { Textarea } from '@starter/ui/components/textarea';
import type { StreamUsage } from '@starter/chat';
import { useAiStream } from '@starter/chat';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, formatMoney, formatNumber, t } from '@/lib/i18n';
import { renderMarkdown } from '@/lib/markdown';
import { cn } from '@/lib/utils';
import { chat } from '@/routes/ai';
import { destroy, stream } from '@/routes/ai/chat';
import { index as connectionsIndex } from '@/routes/admin/ai/connections';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI chat', href: chat() }],
    },
});

type ChatMessage = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    usage?: Partial<StreamUsage> | null;
    model?: string | null;
    cost?: number | null;
    costStatus?: string | null;
    error?: string | null;
    created_at?: string;
};

type Conversation = { id: string; title: string; updated_at: string };

type ModelOption = {
    id: number;
    label: string;
    connection: string;
    is_default: boolean;
};

const props = defineProps<{
    conversations: Conversation[];
    conversation: { id: string; title: string; messages: ChatMessage[] } | null;
    agents: {
        id: number;
        name: string;
        description: string | null;
        avatar: string | null;
        greeting: string | null;
        is_default: boolean;
    }[];
    models: ModelOption[];
    defaultModelId: number | null;
    canChooseModel: boolean;
    hasModels: boolean;
    monthSpend: string;
}>();

const { can } = usePermissions();
const { streaming, send, stop } = useAiStream();

const messages = ref<ChatMessage[]>([...(props.conversation?.messages ?? [])]);
const input = ref('');
const error = ref<string | null>(null);
const agentId = ref<string>(
    String(
        props.agents.find((a) => a.is_default)?.id ?? props.agents[0]?.id ?? '',
    ),
);
const target = ref<string>('default');
const scroller = ref<HTMLElement | null>(null);

watch(
    () => props.conversation,
    (conversation) => {
        if (!streaming.value) {
            messages.value = [...(conversation?.messages ?? [])];
        }
    },
);

const connections = computed(() => [
    ...new Set(props.models.map((model) => model.connection)),
]);

const scrollToBottom = async () => {
    await nextTick();
    const viewport =
        scroller.value?.querySelector('[data-slot="scroll-area-viewport"]') ??
        scroller.value;
    viewport?.scrollTo({ top: viewport.scrollHeight, behavior: 'smooth' });
};

watch(() => messages.value.length, scrollToBottom, { immediate: true });

const submit = async () => {
    const message = input.value.trim();

    if (!message || streaming.value) {
        return;
    }

    error.value = null;
    input.value = '';

    messages.value.push({
        id: `local-${Date.now()}`,
        role: 'user',
        content: message,
    });
    messages.value.push({
        id: `local-${Date.now()}-answer`,
        role: 'assistant',
        content: '',
    });

    const answer = messages.value[messages.value.length - 1];
    let conversationId = props.conversation?.id ?? null;

    await send(
        stream().url,
        {
            message,
            conversation_id: conversationId,
            agent_id: agentId.value ? Number(agentId.value) : null,
            model_id: target.value === 'default' ? null : Number(target.value),
        },
        {
            onStart: (event) => (answer.model = event.model ?? null),
            onDelta: (delta) => {
                answer.content += delta;
                void scrollToBottom();
            },
            onUsage: (usage) => (answer.usage = usage),
            onSummary: (summary) => {
                answer.cost = summary.cost;
                answer.costStatus = summary.cost_status;
                answer.model = summary.model ?? answer.model;
                conversationId = summary.conversation_id ?? conversationId;
            },
            onError: (message) => {
                answer.error = message;
                error.value = message;
            },
        },
    );

    // Load the stored conversation (title, ids, usage) without losing the scroll...
    if (conversationId && conversationId !== props.conversation?.id) {
        router.visit(chat(conversationId).url, {
            preserveScroll: true,
            preserveState: true,
        });
    } else {
        router.reload({ only: ['conversations', 'monthSpend'] });
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void submit();
    }
};

const remove = (conversation: Conversation) =>
    router.delete(destroy(conversation.id).url);

const tokens = (usage?: Partial<StreamUsage> | null) =>
    usage ? (usage.input_tokens ?? 0) + (usage.output_tokens ?? 0) : 0;
</script>

<template>
    <Head :title="conversation?.title ?? t('AI chat')" />

    <div class="flex h-[calc(100svh-4rem)] min-h-0 flex-1 overflow-hidden">
        <!-- Conversations -->
        <aside class="hidden w-64 shrink-0 flex-col border-r md:flex">
            <div class="p-3">
                <Button class="w-full justify-start" variant="outline" as-child>
                    <Link :href="chat()">
                        <MessageSquarePlus class="size-4" />
                        {{ t('New conversation') }}
                    </Link>
                </Button>
            </div>
            <ScrollArea class="min-h-0 flex-1">
                <nav class="grid gap-0.5 px-2 pb-3">
                    <div
                        v-for="item in conversations"
                        :key="item.id"
                        :class="
                            cn(
                                'group flex items-center gap-1 rounded-md pr-1 text-sm hover:bg-muted',
                                item.id === conversation?.id &&
                                    'bg-muted font-medium',
                            )
                        "
                    >
                        <Link
                            :href="chat(item.id)"
                            class="min-w-0 flex-1 truncate px-2 py-1.5"
                            preserve-scroll
                        >
                            {{ item.title }}
                        </Link>
                        <ConfirmDialog
                            :title="t('Delete conversation')"
                            :description="
                                t(
                                    'The conversation and its messages will be deleted.',
                                )
                            "
                            :confirm-text="t('Delete')"
                            @confirm="remove(item)"
                        >
                            <button
                                type="button"
                                class="rounded p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:text-destructive focus:opacity-100"
                                :aria-label="t('Delete')"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </ConfirmDialog>
                    </div>
                    <p
                        v-if="!conversations.length"
                        class="px-2 py-4 text-xs text-muted-foreground"
                    >
                        {{ t('Your conversations will appear here.') }}
                    </p>
                </nav>
            </ScrollArea>
            <div class="border-t p-3 text-xs text-muted-foreground">
                {{ t('Your spend this month') }}: {{ formatMoney(monthSpend) }}
            </div>
        </aside>

        <!-- Messages -->
        <section class="flex min-w-0 flex-1 flex-col">
            <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
                <div class="mx-auto flex max-w-3xl flex-col gap-6 px-4 py-6">
                    <Alert v-if="!hasModels">
                        <AlertDescription>
                            {{ t('No AI model is enabled yet.') }}
                            <Link
                                v-if="can('ai.providers.manage')"
                                :href="connectionsIndex()"
                                class="underline"
                            >
                                {{ t('Configure a connection') }}
                            </Link>
                        </AlertDescription>
                    </Alert>

                    <div
                        v-if="!messages.length"
                        class="flex flex-col items-center gap-3 py-20 text-center text-muted-foreground"
                    >
                        <Bot class="size-10" />
                        <p class="text-lg font-medium text-foreground">
                            {{ t('How can I help you today?') }}
                        </p>
                        <p class="max-w-md text-sm">
                            {{
                                t(
                                    'Choose an agent and start writing. Your conversations are saved automatically.',
                                )
                            }}
                        </p>
                    </div>

                    <div
                        v-for="message in messages"
                        :key="message.id"
                        :class="
                            cn(
                                'flex gap-3',
                                message.role === 'user' && 'flex-row-reverse',
                            )
                        "
                    >
                        <div
                            class="flex size-8 shrink-0 items-center justify-center rounded-full"
                            :class="
                                message.role === 'user'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted'
                            "
                        >
                            <UserIcon
                                v-if="message.role === 'user'"
                                class="size-4"
                            />
                            <Bot v-else class="size-4" />
                        </div>
                        <div
                            :class="
                                cn(
                                    'grid min-w-0 gap-1',
                                    message.role === 'user'
                                        ? 'max-w-[80%] justify-items-end'
                                        : 'flex-1',
                                )
                            "
                        >
                            <div
                                v-if="message.role === 'user'"
                                class="rounded-2xl bg-primary px-4 py-2 text-sm whitespace-pre-wrap text-primary-foreground"
                            >
                                {{ message.content }}
                            </div>
                            <template v-else>
                                <div
                                    v-if="message.content"
                                    class="markdown"
                                    v-html="renderMarkdown(message.content)"
                                />
                                <div
                                    v-else-if="!message.error"
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
                                <p
                                    v-if="message.error"
                                    class="text-sm text-destructive"
                                >
                                    {{ message.error }}
                                </p>
                                <p
                                    v-if="
                                        message.model || tokens(message.usage)
                                    "
                                    class="text-[11px] text-muted-foreground"
                                >
                                    <span
                                        v-if="message.model"
                                        class="font-mono"
                                        >{{ message.model }}</span
                                    >
                                    <template v-if="tokens(message.usage)">
                                        ·
                                        {{
                                            formatNumber(tokens(message.usage))
                                        }}
                                        {{ t('tokens') }}
                                    </template>
                                    <template
                                        v-if="
                                            message.cost !== undefined &&
                                            message.cost !== null
                                        "
                                    >
                                        ·
                                        {{
                                            formatMoney(message.cost)
                                        }}</template
                                    >
                                    <template
                                        v-else-if="
                                            message.costStatus === 'unknown'
                                        "
                                    >
                                        · {{ t('cost unknown') }}</template
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
                </div>
            </div>

            <!-- Composer -->
            <div class="border-t bg-background">
                <form
                    class="mx-auto grid max-w-3xl gap-2 px-4 py-3"
                    @submit.prevent="submit"
                >
                    <div class="flex flex-wrap gap-2">
                        <Select v-if="agents.length" v-model="agentId">
                            <SelectTrigger size="sm" class="w-48">
                                <SelectValue :placeholder="t('Agent')" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="agent in agents"
                                    :key="agent.id"
                                    :value="String(agent.id)"
                                >
                                    {{ agent.avatar ? `${agent.avatar} ` : ''
                                    }}{{ agent.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            v-if="canChooseModel && models.length"
                            v-model="target"
                        >
                            <SelectTrigger size="sm" class="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="default">{{
                                    t('Default model')
                                }}</SelectItem>
                                <SelectGroup
                                    v-for="connection in connections"
                                    :key="connection"
                                >
                                    <SelectLabel>{{ connection }}</SelectLabel>
                                    <SelectItem
                                        v-for="option in models.filter(
                                            (item) =>
                                                item.connection === connection,
                                        )"
                                        :key="option.id"
                                        :value="String(option.id)"
                                    >
                                        {{ option.label }}
                                    </SelectItem>
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="relative">
                        <Textarea
                            v-model="input"
                            rows="2"
                            class="max-h-48 min-h-14 resize-none pr-12"
                            :placeholder="
                                t(
                                    'Write a message... (Enter to send, Shift+Enter for a new line)',
                                )
                            "
                            :disabled="!hasModels"
                            data-test="chat-input"
                            @keydown="onKeydown"
                        />
                        <Button
                            v-if="streaming"
                            type="button"
                            size="icon"
                            variant="secondary"
                            class="absolute right-2 bottom-2"
                            :aria-label="t('Stop')"
                            @click="stop"
                        >
                            <Square class="size-4" />
                        </Button>
                        <Button
                            v-else
                            type="submit"
                            size="icon"
                            class="absolute right-2 bottom-2"
                            :disabled="!input.trim() || !hasModels"
                            :aria-label="t('Send')"
                        >
                            <SendHorizontal class="size-4" />
                        </Button>
                    </div>
                    <p v-if="error" class="text-xs text-destructive">
                        {{ error }}
                    </p>
                </form>
            </div>
        </section>
    </div>
</template>
