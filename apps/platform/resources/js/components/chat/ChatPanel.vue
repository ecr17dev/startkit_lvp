<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatMessageBubble from '@/components/chat/ChatMessageBubble.vue';
import ProposalCard from '@/components/chat/ProposalCard.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import type { UseChat } from '@/composables/useChat';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as agentsIndex } from '@/routes/admin/ai/agents';
import { index as connectionsIndex } from '@/routes/admin/ai/connections';

const props = defineProps<{
    chat: UseChat;
    compact?: boolean;
    surface?: 'page' | 'floating';
}>();

const scroller = ref<HTMLElement | null>(null);

const bootstrap = computed(() => props.chat.bootstrap.value);
const agent = computed(() => props.chat.agent.value);
const busy = computed(() => props.chat.streaming.value);
const ready = computed(
    () =>
        !!bootstrap.value &&
        bootstrap.value.agents.length > 0 &&
        bootstrap.value.hasModels,
);

const scrollToBottom = async () => {
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: 'smooth',
    });
};

watch(
    () => [props.chat.items.value.length, busy.value],
    () => void scrollToBottom(),
    { immediate: true },
);

watch(
    () => {
        const last = props.chat.items.value[props.chat.items.value.length - 1];

        return last?.kind === 'message' ? last.content.length : 0;
    },
    () => void scrollToBottom(),
);
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
            <div
                :class="
                    cn(
                        'mx-auto flex flex-col gap-5 py-6',
                        compact ? 'px-3' : 'max-w-3xl px-4',
                    )
                "
            >
                <Alert v-if="bootstrap && !bootstrap.hasModels">
                    <AlertDescription>
                        {{ t('No AI model is enabled yet.') }}
                        <Link
                            v-if="bootstrap.canConfigure"
                            :href="connectionsIndex()"
                            class="underline"
                            >{{ t('Configure a connection') }}</Link
                        >
                        <template v-else>{{
                            t(
                                'Ask an administrator to configure the assistant.',
                            )
                        }}</template>
                    </AlertDescription>
                </Alert>
                <Alert v-else-if="bootstrap && !bootstrap.agents.length">
                    <AlertDescription>
                        {{ t('No AI agent is available for you.') }}
                        <Link
                            v-if="bootstrap.canConfigure"
                            :href="agentsIndex()"
                            class="underline"
                            >{{ t('Configure an agent') }}</Link
                        >
                        <template v-else>{{
                            t('Ask an administrator to publish one.')
                        }}</template>
                    </AlertDescription>
                </Alert>

                <div
                    v-if="!chat.items.value.length && ready"
                    class="flex flex-col items-center gap-3 py-12 text-center text-muted-foreground"
                >
                    <span
                        class="flex size-12 items-center justify-center rounded-full bg-muted text-2xl"
                    >
                        <template v-if="agent?.avatar">{{
                            agent.avatar
                        }}</template>
                        <Bot v-else class="size-6" />
                    </span>
                    <p class="text-base font-medium text-foreground">
                        {{ agent?.greeting || t('How can I help you today?') }}
                    </p>
                    <p v-if="agent?.description" class="max-w-md text-sm">
                        {{ agent.description }}
                    </p>
                </div>

                <template v-for="item in chat.items.value" :key="item.id">
                    <ProposalCard
                        v-if="item.kind === 'proposal'"
                        :proposal="item.proposal"
                        :busy="busy"
                        @decide="
                            (decision) => chat.decide(item.proposal, decision)
                        "
                    />
                    <ChatMessageBubble
                        v-else
                        :message="item"
                        :avatar="agent?.avatar"
                        :show-details="bootstrap?.canSeeDetails"
                        :compact="compact"
                    />
                </template>
            </div>
        </div>

        <div class="border-t bg-background">
            <ChatComposer
                :chat="chat"
                :ready="ready"
                :compact="compact"
                :surface="surface ?? 'page'"
            />
        </div>
    </div>
</template>
