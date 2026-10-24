import type {
    ChatProposal,
    StreamSummary,
    StreamUsage,
    ToolActivity,
} from '@starter/chat';
import { requestJson, useAiStream } from '@starter/chat';
import { computed, reactive, ref } from 'vue';
import { bootstrap as bootstrapRoute, destroy, stream } from '@/routes/ai/chat';
import {
    index as conversationsIndex,
    show as conversationShow,
} from '@/routes/ai/conversations';

export type ChatAgent = {
    id: number;
    name: string;
    description: string | null;
    avatar: string | null;
    greeting: string | null;
    is_default: boolean;
    attachments: {
        mime_types: string[];
        max_files: number;
        max_file_mb: number;
    } | null;
    microphone: { max_seconds: number; auto_send: boolean } | null;
};

export type ChatModel = {
    id: number;
    label: string;
    connection: string;
    is_default: boolean;
};

export type ChatBootstrap = {
    agents: ChatAgent[];
    models: ChatModel[];
    canChooseModel: boolean;
    canConfigure: boolean;
    canSeeDetails: boolean;
    hasModels: boolean;
    monthSpend: string;
};

export type ChatConversationSummary = {
    id: string;
    title: string;
    updated_at: string;
};

export type ChatAttachmentInfo = { name: string | null; type: string | null };

export type ChatMessageItem = {
    kind: 'message';
    id: string;
    role: 'user' | 'assistant';
    content: string;
    usage?: Partial<StreamUsage> | null;
    model?: string | null;
    summary?: StreamSummary | null;
    tools?: ToolActivity[];
    attachments?: ChatAttachmentInfo[];
    error?: string | null;
    created_at?: string;
};

export type ChatProposalItem = {
    kind: 'proposal';
    id: string;
    proposal: ChatProposal;
    created_at: string;
};

export type ChatItem = ChatMessageItem | ChatProposalItem;

export type ChatConversation = {
    id: string;
    title: string;
    messages: (Omit<ChatMessageItem, 'kind'> & { created_at: string })[];
    proposals: ChatProposal[];
};

/**
 * State and actions of a chat with the configured agents, shared by the
 * chat page and the floating chat. The server resolves the agent version,
 * model and tools; the client only chooses among what it was offered.
 */
export function useChat(options: {
    surface: 'page' | 'floating';
    onConversation?: (id: string) => void;
}) {
    const bootstrap = ref<ChatBootstrap | null>(null);
    const conversations = ref<ChatConversationSummary[]>([]);
    const conversationId = ref<string | null>(null);
    const title = ref<string | null>(null);
    const items = ref<ChatItem[]>([]);
    const error = ref<string | null>(null);
    const agentId = ref<number | null>(null);
    const modelId = ref<number | null>(null);
    const { streaming, send, stop } = useAiStream();

    const agent = computed(
        () =>
            bootstrap.value?.agents.find((item) => item.id === agentId.value) ??
            null,
    );

    const setBootstrap = (data: ChatBootstrap) => {
        bootstrap.value = data;

        if (!data.agents.some((item) => item.id === agentId.value)) {
            agentId.value =
                data.agents.find((item) => item.is_default)?.id ??
                data.agents[0]?.id ??
                null;
        }
    };

    const loadBootstrap = async () =>
        setBootstrap(
            await requestJson<ChatBootstrap>(
                bootstrapRoute({ query: { surface: options.surface } }).url,
            ),
        );

    const loadConversations = async () => {
        conversations.value = (
            await requestJson<{ data: ChatConversationSummary[] }>(
                conversationsIndex().url,
            )
        ).data;
    };

    const timeline = (conversation: ChatConversation | null): ChatItem[] => {
        if (!conversation) {
            return [];
        }

        const entries: ChatItem[] = [
            ...conversation.messages
                // A turn paused on a proposal may store an empty answer...
                .filter(
                    (message) =>
                        message.role === 'user' ||
                        message.content.trim() !== '',
                )
                .map((message) => ({ ...message, kind: 'message' as const })),
            ...conversation.proposals.map((proposal) => ({
                kind: 'proposal' as const,
                id: proposal.id,
                proposal,
                created_at: proposal.created_at,
            })),
        ];

        // Same-second ties: the question, then its proposals, then the answer...
        const rank = (item: ChatItem) =>
            item.kind === 'proposal' ? 1 : item.role === 'user' ? 0 : 2;

        return entries.sort(
            (a, b) =>
                new Date(a.created_at ?? 0).getTime() -
                    new Date(b.created_at ?? 0).getTime() || rank(a) - rank(b),
        );
    };

    const open = (conversation: ChatConversation | null) => {
        conversationId.value = conversation?.id ?? null;
        title.value = conversation?.title ?? null;
        items.value = timeline(conversation);
        error.value = null;
    };

    const loadConversation = async (id: string) =>
        open(
            (
                await requestJson<{ data: ChatConversation }>(
                    conversationShow(id).url,
                )
            ).data,
        );

    const newConversation = () => open(null);

    const removeConversation = async (id: string) => {
        await requestJson(destroy(id).url, { method: 'DELETE' });
        conversations.value = conversations.value.filter(
            (item) => item.id !== id,
        );

        if (conversationId.value === id) {
            newConversation();
        }
    };

    const upsertProposal = (proposal: ChatProposal) => {
        const existing = items.value.find(
            (item): item is ChatProposalItem =>
                item.kind === 'proposal' && item.id === proposal.id,
        );

        if (existing) {
            existing.proposal = proposal;
        } else {
            items.value.push({
                kind: 'proposal',
                id: proposal.id,
                proposal,
                created_at: proposal.created_at,
            });
        }
    };

    const run = async (
        body: Record<string, unknown> | FormData,
        userItem?: ChatMessageItem,
    ) => {
        error.value = null;

        if (userItem) {
            items.value.push(userItem);
        }

        const answer = reactive<ChatMessageItem>({
            kind: 'message',
            id: `local-${Date.now()}-answer`,
            role: 'assistant',
            content: '',
            tools: [],
        });
        items.value.push(answer);

        const previous = conversationId.value;

        await send(stream().url, body, {
            onStart: (event) => (answer.model = event.model ?? null),
            onDelta: (delta) => (answer.content += delta),
            onTool: (activity) => {
                const tool = answer.tools?.find(
                    (item) => item.id === activity.id,
                );

                if (tool) {
                    tool.status = activity.status;
                } else {
                    answer.tools?.push(activity);
                }
            },
            onProposal: upsertProposal,
            onUsage: (usage) => (answer.usage = usage),
            onSummary: (summary) => {
                answer.summary = summary;
                answer.model = summary.model ?? answer.model;
                conversationId.value =
                    summary.conversation_id ?? conversationId.value;
            },
            onError: (message) => {
                answer.error = message;
                error.value = message;
            },
        });

        // Drop an empty answer of a turn paused on a proposal...
        if (!answer.content && !answer.error && !answer.tools?.length) {
            items.value = items.value.filter((item) => item.id !== answer.id);
        }

        void loadConversations().catch(() => undefined);
        void loadBootstrap().catch(() => undefined);

        if (conversationId.value && conversationId.value !== previous) {
            options.onConversation?.(conversationId.value);
        }
    };

    const sendMessage = async (text: string, files: File[] = []) => {
        const message = text.trim();

        if ((!message && !files.length) || streaming.value) {
            return;
        }

        const fields: Record<string, string | null> = {
            message,
            conversation_id: conversationId.value,
            agent_id: agentId.value ? String(agentId.value) : null,
            model_id: modelId.value ? String(modelId.value) : null,
            surface: options.surface,
        };

        let body: Record<string, unknown> | FormData = fields;

        if (files.length) {
            const form = new FormData();

            for (const [key, value] of Object.entries(fields)) {
                if (value !== null) {
                    form.append(key, value);
                }
            }

            files.forEach((file) => form.append('files[]', file));
            body = form;
        }

        await run(body, {
            kind: 'message',
            id: `local-${Date.now()}`,
            role: 'user',
            content: message,
            attachments: files.map((file) => ({
                name: file.name,
                type: file.type,
            })),
            created_at: new Date().toISOString(),
        });
    };

    const decide = async (
        proposal: ChatProposal,
        decision: 'approve' | 'reject',
    ) => {
        if (streaming.value) {
            return;
        }

        upsertProposal({
            ...proposal,
            status: decision === 'approve' ? 'approved' : 'rejected',
        });

        await run({
            proposal_id: proposal.id,
            decision,
            surface: options.surface,
        });
    };

    return {
        bootstrap,
        conversations,
        conversationId,
        title,
        items,
        error,
        agentId,
        modelId,
        agent,
        streaming,
        stop,
        setBootstrap,
        loadBootstrap,
        loadConversations,
        loadConversation,
        open,
        newConversation,
        removeConversation,
        sendMessage,
        decide,
    };
}

export type UseChat = ReturnType<typeof useChat>;
