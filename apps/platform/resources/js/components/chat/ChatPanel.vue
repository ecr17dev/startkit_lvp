<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, SendHorizontal, Square } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ChatMessageBubble from '@/components/chat/ChatMessageBubble.vue';
import ProposalCard from '@/components/chat/ProposalCard.vue';
import { Alert, AlertDescription } from '@starter/ui/components/alert';
import { Button } from '@starter/ui/components/button';
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
import type { UseChat } from '@/composables/useChat';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { index as agentsIndex } from '@/routes/admin/ai/agents';
import { index as connectionsIndex } from '@/routes/admin/ai/connections';

const props = defineProps<{ chat: UseChat; compact?: boolean }>();

const input = ref('');
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

const connections = computed(() => [
    ...new Set(
        (bootstrap.value?.models ?? []).map((model) => model.connection),
    ),
]);

const agentValue = computed({
    get: () =>
        props.chat.agentId.value ? String(props.chat.agentId.value) : '',
    set: (value: string) =>
        (props.chat.agentId.value = value ? Number(value) : null),
});

const modelValue = computed({
    get: () =>
        props.chat.modelId.value ? String(props.chat.modelId.value) : 'default',
    set: (value: string) =>
        (props.chat.modelId.value = value === 'default' ? null : Number(value)),
});

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

const submit = async () => {
    if (!ready.value || busy.value || !input.value.trim()) {
        return;
    }

    const message = input.value;
    input.value = '';

    await props.chat.sendMessage(message);
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        void submit();
    }
};

defineExpose({
    focus: () =>
        scroller.value?.parentElement?.querySelector('textarea')?.focus(),
});
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
            <form
                :class="
                    cn(
                        'mx-auto grid gap-2 py-3',
                        compact ? 'px-3' : 'max-w-3xl px-4',
                    )
                "
                @submit.prevent="submit"
            >
                <div
                    v-if="
                        bootstrap &&
                        (bootstrap.agents.length > 1 ||
                            (bootstrap.canChooseModel &&
                                bootstrap.models.length))
                    "
                    class="flex flex-wrap gap-2"
                >
                    <Select
                        v-if="bootstrap.agents.length > 1"
                        v-model="agentValue"
                    >
                        <SelectTrigger size="sm" class="w-44">
                            <SelectValue :placeholder="t('Agent')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in bootstrap.agents"
                                :key="option.id"
                                :value="String(option.id)"
                                >{{ option.avatar ? `${option.avatar} ` : ''
                                }}{{ option.name }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <Select
                        v-if="
                            bootstrap.canChooseModel && bootstrap.models.length
                        "
                        v-model="modelValue"
                    >
                        <SelectTrigger size="sm" class="w-52">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="default">{{
                                t('Agent model')
                            }}</SelectItem>
                            <SelectGroup
                                v-for="connection in connections"
                                :key="connection"
                            >
                                <SelectLabel>{{ connection }}</SelectLabel>
                                <SelectItem
                                    v-for="model in bootstrap.models.filter(
                                        (m) => m.connection === connection,
                                    )"
                                    :key="model.id"
                                    :value="String(model.id)"
                                    >{{ model.label }}</SelectItem
                                >
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>
                <div class="relative">
                    <Textarea
                        v-model="input"
                        :rows="compact ? 2 : 3"
                        class="resize-none pr-12"
                        :disabled="!ready"
                        :placeholder="
                            t(
                                'Write a message… (Enter to send, Shift+Enter for a new line)',
                            )
                        "
                        data-test="chat-input"
                        @keydown="onKeydown"
                    />
                    <Button
                        v-if="busy"
                        type="button"
                        size="icon"
                        variant="secondary"
                        class="absolute right-2 bottom-2"
                        :aria-label="t('Stop')"
                        @click="chat.stop()"
                    >
                        <Square class="size-4" />
                    </Button>
                    <Button
                        v-else
                        type="submit"
                        size="icon"
                        class="absolute right-2 bottom-2"
                        :disabled="!ready || !input.trim()"
                        :aria-label="t('Send')"
                        data-test="chat-send"
                    >
                        <SendHorizontal class="size-4" />
                    </Button>
                </div>
                <p v-if="chat.error.value" class="text-xs text-destructive">
                    {{ chat.error.value }}
                </p>
            </form>
        </div>
    </div>
</template>
