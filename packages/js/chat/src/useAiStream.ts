import { ref } from 'vue';
import { xsrfToken } from './http';

export type StreamUsage = {
    input_tokens: number;
    output_tokens: number;
    cache_read_input_tokens?: number | null;
    reasoning_tokens?: number | null;
};

export type StreamSummary = {
    conversation_id: string | null;
    request_id: string | null;
    /** Calculated cost in USD, or null when it is unknown (never zero by default). */
    cost: string | null;
    cost_status: string | null;
    model: string | null;
    provider?: string | null;
    latency_ms?: number | null;
    input_tokens?: number | null;
    output_tokens?: number | null;
    agent_version?: number | null;
};

export type ProposalChange = { field: string; before: unknown; after: unknown };

/** A change prepared by an agent tool, waiting for the user's decision. */
export type ChatProposal = {
    id: string;
    tool: string;
    kind: 'write' | 'sensitive';
    status:
        | 'pending'
        | 'approved'
        | 'executing'
        | 'executed'
        | 'failed'
        | 'rejected'
        | 'expired'
        | 'superseded';
    preview: {
        title: string;
        target: { type: string | null; id: string | null; label: string | null };
        changes: ProposalChange[];
        effects: string[];
        url: string | null;
    };
    result: Record<string, unknown> | null;
    error: string | null;
    expires_at: string;
    created_at: string;
    conversation_id: string | null;
};

/** A passage cited by an answer, validated against the retrieved set. */
export type KnowledgeCitation = {
    n: number;
    document_id: number | null;
    chunk_id: number;
    title: string | null;
    location: string | null;
    uri: string | null;
    score: number;
};

/** Knowledge used by an answer (agents with RAG). */
export type StreamKnowledge = {
    mode: string | null;
    used?: boolean;
    /** answered, general (not based on the sources), unsupported (no valid citation) or abstained. */
    outcome: 'answered' | 'general' | 'unsupported' | 'abstained' | null;
    hits?: number;
    citations: KnowledgeCitation[];
    invalid: number;
};

export type ToolActivity = {
    id: string;
    name: string;
    status: 'running' | 'done' | 'failed' | 'denied' | 'waiting';
};

type StreamEvent = {
    type?: string;
    delta?: string;
    message?: string;
    provider?: string;
    model?: string | null;
    usage?: StreamUsage | null;
    proposal?: ChatProposal;
    tool_id?: string;
    tool_name?: string;
    successful?: boolean;
    denied?: boolean;
    preliminary?: boolean;
    approvals?: { id: string; tool: string }[];
    knowledge?: StreamKnowledge;
} & Partial<StreamSummary>;

export type StreamHandlers = {
    onDelta: (delta: string) => void;
    onStart?: (event: { provider?: string; model?: string | null }) => void;
    onUsage?: (usage: StreamUsage) => void;
    onSummary?: (summary: StreamSummary) => void;
    onProposal?: (proposal: ChatProposal) => void;
    onKnowledge?: (knowledge: StreamKnowledge) => void;
    onTool?: (activity: ToolActivity) => void;
    onError?: (message: string) => void;
};

/**
 * POST a payload (JSON or FormData) to a Laravel AI SDK streaming endpoint
 * and dispatch the server-sent events: text deltas, tool calls and
 * results, approval requests and proposals, the summary and errors.
 */
export function useAiStream() {
    const streaming = ref(false);
    let controller: AbortController | null = null;

    const handle = (payload: StreamEvent, handlers: StreamHandlers) => {
        switch (payload.type) {
            case 'stream_start':
                handlers.onStart?.(payload as { provider?: string; model?: string });
                break;
            case 'text_delta':
                handlers.onDelta(payload.delta ?? '');
                break;
            case 'tool_call':
                handlers.onTool?.({
                    id: payload.tool_id ?? '',
                    name: payload.tool_name ?? '',
                    status: 'running',
                });
                break;
            case 'tool_result':
                if (!payload.preliminary) {
                    handlers.onTool?.({
                        id: payload.tool_id ?? '',
                        name: payload.tool_name ?? '',
                        status: payload.denied
                            ? 'denied'
                            : payload.successful
                              ? 'done'
                              : 'failed',
                    });
                }

                break;
            case 'tool_approval_request':
                for (const approval of payload.approvals ?? []) {
                    handlers.onTool?.({
                        id: approval.id,
                        name: approval.tool,
                        status: 'waiting',
                    });
                }

                break;
            case 'proposal':
                if (payload.proposal) {
                    handlers.onProposal?.(payload.proposal);
                }

                break;
            case 'knowledge':
                if (payload.knowledge) {
                    handlers.onKnowledge?.(payload.knowledge);
                }

                break;
            case 'stream_end':
                if (payload.usage) {
                    handlers.onUsage?.(payload.usage);
                }

                break;
            case 'summary':
                handlers.onSummary?.({
                    conversation_id: payload.conversation_id ?? null,
                    request_id: payload.request_id ?? null,
                    cost: payload.cost ?? null,
                    cost_status: payload.cost_status ?? null,
                    model: payload.model ?? null,
                    provider: payload.provider ?? null,
                    latency_ms: payload.latency_ms ?? null,
                    input_tokens: payload.input_tokens ?? null,
                    output_tokens: payload.output_tokens ?? null,
                    agent_version: payload.agent_version ?? null,
                });
                break;
            case 'error':
                handlers.onError?.(payload.message ?? 'Error');
                break;
        }
    };

    const send = async (
        url: string,
        body: Record<string, unknown> | FormData,
        handlers: StreamHandlers,
    ) => {
        controller = new AbortController();
        streaming.value = true;

        const isForm = body instanceof FormData;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    ...(isForm ? {} : { 'Content-Type': 'application/json' }),
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: isForm ? body : JSON.stringify(body),
                signal: controller.signal,
                credentials: 'same-origin',
            });

            if (!response.ok || !response.body) {
                const data = await response.json().catch(() => ({}));
                const errors = data?.errors
                    ? Object.values(data.errors).flat().join(' ')
                    : null;

                handlers.onError?.(
                    errors || data?.message || `HTTP ${response.status}`,
                );

                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();

                if (done) {
                    break;
                }

                buffer += decoder.decode(value, { stream: true });

                let boundary = buffer.indexOf('\n\n');

                while (boundary !== -1) {
                    const chunk = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);
                    boundary = buffer.indexOf('\n\n');

                    const data = chunk
                        .split('\n')
                        .filter((line) => line.startsWith('data:'))
                        .map((line) => line.slice(5).trimStart())
                        .join('\n');

                    if (!data || data === '[DONE]') {
                        continue;
                    }

                    try {
                        handle(JSON.parse(data) as StreamEvent, handlers);
                    } catch {
                        // Ignore malformed events...
                    }
                }
            }
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                handlers.onError?.((error as Error).message);
            }
        } finally {
            streaming.value = false;
            controller = null;
        }
    };

    const stop = () => controller?.abort();

    return { streaming, send, stop };
}
