<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { MessageSquarePlus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import ChatPanel from '@/components/chat/ChatPanel.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@starter/ui/components/button';
import { ScrollArea } from '@starter/ui/components/scroll-area';
import type {
    ChatBootstrap,
    ChatConversation,
    ChatConversationSummary,
} from '@/composables/useChat';
import { useChat } from '@/composables/useChat';
import { formatMoney, t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { chat as chatRoute } from '@/routes/ai';
import { destroy } from '@/routes/ai/chat';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'AI chat', href: chatRoute() }],
    },
});

const props = defineProps<
    ChatBootstrap & {
        conversations: ChatConversationSummary[];
        conversation: ChatConversation | null;
    }
>();

const bootstrapFromProps = (): ChatBootstrap => ({
    agents: props.agents,
    models: props.models,
    canChooseModel: props.canChooseModel,
    canConfigure: props.canConfigure,
    canSeeDetails: props.canSeeDetails,
    hasModels: props.hasModels,
    monthSpend: props.monthSpend,
});

const chat = useChat({
    surface: 'page',
    // Show the stored conversation in the address bar...
    onConversation: (id) =>
        router.visit(chatRoute(id).url, {
            preserveScroll: true,
            preserveState: true,
            only: ['conversation', 'conversations', 'monthSpend'],
        }),
});

chat.setBootstrap(bootstrapFromProps());
chat.conversations.value = props.conversations;
chat.open(props.conversation);

watch(
    () => props.conversation,
    (conversation) => {
        if (!chat.streaming.value) {
            chat.open(conversation);
        }
    },
);

watch(
    () => props.conversations,
    (conversations) => (chat.conversations.value = conversations),
);

const monthSpend = computed(
    () => chat.bootstrap.value?.monthSpend ?? props.monthSpend,
);

const remove = (conversation: ChatConversationSummary) =>
    router.delete(destroy(conversation.id).url);
</script>

<template>
    <Head :title="chat.title.value ?? t('AI chat')" />

    <div class="flex h-[calc(100svh-4rem)] min-h-0 flex-1 overflow-hidden">
        <aside class="hidden w-64 shrink-0 flex-col border-r md:flex">
            <div class="p-3">
                <Button class="w-full justify-start" variant="outline" as-child>
                    <Link :href="chatRoute()">
                        <MessageSquarePlus class="size-4" />
                        {{ t('New conversation') }}
                    </Link>
                </Button>
            </div>
            <ScrollArea class="min-h-0 flex-1">
                <nav class="grid gap-0.5 px-2 pb-3">
                    <div
                        v-for="item in chat.conversations.value"
                        :key="item.id"
                        :class="
                            cn(
                                'group flex items-center gap-1 rounded-md pr-1 text-sm hover:bg-muted',
                                item.id === chat.conversationId.value &&
                                    'bg-muted font-medium',
                            )
                        "
                    >
                        <Link
                            :href="chatRoute(item.id)"
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
                        v-if="!chat.conversations.value.length"
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

        <section class="flex min-w-0 flex-1 flex-col">
            <ChatPanel :chat="chat" />
        </section>
    </div>
</template>
