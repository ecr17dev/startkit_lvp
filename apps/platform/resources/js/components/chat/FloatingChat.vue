<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bot,
    ExternalLink,
    History,
    Maximize2,
    MessageSquarePlus,
    Minimize2,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChatPanel from '@/components/chat/ChatPanel.vue';
import { Button } from '@starter/ui/components/button';
import { useChat } from '@/composables/useChat';
import { usePermissions } from '@/composables/usePermissions';
import { formatDate, t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { chat as chatRoute } from '@/routes/ai';

const page = usePage();
const { can } = usePermissions();

const open = ref(false);
const expanded = ref(false);
const showHistory = ref(false);
const loaded = ref(false);
const loadError = ref<string | null>(null);

const chat = useChat({ surface: 'floating' });

// The chat page has its own full chat...
const visible = computed(() => can('ai.chat') && page.component !== 'ai/Chat');

const agent = computed(() => chat.agent.value);

const toggle = async () => {
    open.value = !open.value;

    if (open.value && !loaded.value) {
        try {
            await Promise.all([chat.loadBootstrap(), chat.loadConversations()]);
            loaded.value = true;
        } catch (exception) {
            loadError.value = (exception as Error).message;
        }
    }
};

const openConversation = async (id: string) => {
    await chat.loadConversation(id);
    showHistory.value = false;
};

const startNew = () => {
    chat.newConversation();
    showHistory.value = false;
};
</script>

<template>
    <div v-if="visible" class="print:hidden">
        <div
            v-if="open"
            :class="
                cn(
                    'fixed z-50 flex flex-col overflow-hidden border bg-background shadow-2xl',
                    'inset-0 sm:inset-auto sm:right-6 sm:bottom-24 sm:rounded-xl',
                    expanded
                        ? 'sm:h-[85vh] sm:w-[min(56rem,calc(100vw-3rem))]'
                        : 'sm:h-[min(36rem,calc(100vh-8rem))] sm:w-[24rem]',
                )
            "
            role="dialog"
            :aria-label="t('AI chat')"
            data-test="floating-chat"
        >
            <header class="flex items-center gap-2 border-b px-3 py-2">
                <span
                    class="flex size-8 items-center justify-center rounded-full bg-muted text-base"
                >
                    <template v-if="agent?.avatar">{{ agent.avatar }}</template>
                    <Bot v-else class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ agent?.name ?? t('AI chat') }}
                    </p>
                    <p
                        v-if="chat.title.value"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ chat.title.value }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="t('History')"
                    @click="showHistory = !showHistory"
                >
                    <History class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="t('New conversation')"
                    @click="startNew"
                >
                    <MessageSquarePlus class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="hidden size-8 sm:inline-flex"
                    :aria-label="expanded ? t('Reduce') : t('Expand')"
                    @click="expanded = !expanded"
                >
                    <Minimize2 v-if="expanded" class="size-4" />
                    <Maximize2 v-else class="size-4" />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="t('Open the chat page')"
                    as-child
                >
                    <Link
                        :href="
                            chatRoute(chat.conversationId.value ?? undefined)
                        "
                    >
                        <ExternalLink class="size-4" />
                    </Link>
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="t('Close')"
                    @click="open = false"
                >
                    <X class="size-4" />
                </Button>
            </header>

            <p v-if="loadError" class="p-4 text-sm text-destructive">
                {{ loadError }}
            </p>

            <nav
                v-else-if="showHistory"
                class="min-h-0 flex-1 overflow-y-auto p-2"
                :aria-label="t('History')"
            >
                <div
                    v-for="item in chat.conversations.value"
                    :key="item.id"
                    class="group flex items-center gap-1 rounded-md pr-1 hover:bg-muted"
                >
                    <button
                        type="button"
                        class="min-w-0 flex-1 px-2 py-2 text-left"
                        @click="openConversation(item.id)"
                    >
                        <span class="block truncate text-sm">{{
                            item.title
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            formatDate(item.updated_at)
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:text-destructive focus:opacity-100"
                        :aria-label="t('Delete')"
                        @click="chat.removeConversation(item.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
                <p
                    v-if="!chat.conversations.value.length"
                    class="px-2 py-6 text-center text-xs text-muted-foreground"
                >
                    {{ t('Your conversations will appear here.') }}
                </p>
            </nav>

            <ChatPanel
                v-else-if="loaded"
                :chat="chat"
                :compact="!expanded"
                surface="floating"
            />

            <div
                v-else
                class="flex flex-1 items-center justify-center text-sm text-muted-foreground"
            >
                {{ t('Loading…') }}
            </div>
        </div>

        <Button
            size="icon"
            class="fixed right-6 bottom-6 z-50 size-12 rounded-full shadow-lg"
            :class="open ? 'hidden sm:inline-flex' : ''"
            :aria-label="
                open ? t('Close the assistant') : t('Open the assistant')
            "
            :aria-expanded="open"
            data-test="floating-chat-toggle"
            @click="toggle"
        >
            <X v-if="open" class="size-5" />
            <Bot v-else class="size-5" />
        </Button>
    </div>
</template>
