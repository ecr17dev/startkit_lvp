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
    cost: number | null;
    cost_status: string | null;
    model: string | null;
};

type StreamEvent = {
    type?: string;
    delta?: string;
    message?: string;
    provider?: string;
    model?: string | null;
    usage?: StreamUsage | null;
    conversation_id?: string | null;
    cost?: number | null;
    cost_status?: string | null;
    request_id?: string | null;
};

type Handlers = {
    onDelta: (delta: string) => void;
    onStart?: (event: { provider?: string; model?: string | null }) => void;
    onUsage?: (usage: StreamUsage) => void;
    onSummary?: (summary: StreamSummary) => void;
    onError?: (message: string) => void;
};

/**
 * POST a payload to a Laravel AI SDK streaming endpoint and dispatch the
 * server-sent events (text_delta, stream_end, summary, error...).
 */
export function useAiStream() {
    const streaming = ref(false);
    let controller: AbortController | null = null;

    const handle = (payload: StreamEvent, handlers: Handlers) => {
        switch (payload.type) {
            case 'stream_start':
                handlers.onStart?.(
                    payload as { provider?: string; model?: string },
                );
                break;
            case 'text_delta':
                handlers.onDelta(payload.delta ?? '');
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
                });
                break;
            case 'error':
                handlers.onError?.(payload.message ?? 'Error');
                break;
        }
    };

    const send = async (
        url: string,
        body: Record<string, unknown>,
        handlers: Handlers,
    ) => {
        controller = new AbortController();
        streaming.value = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(body),
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
