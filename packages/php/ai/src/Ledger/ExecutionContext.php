<?php

namespace Starter\Ai\Ledger;

use Starter\Ai\Models\AiRequest;

/**
 * Links the SDK events (identified by invocation id) to the ledger request
 * of the call that triggered them. Scoped to the request / job.
 *
 * - Synchronous calls push the request while the callback runs.
 * - Streams are consumed after the controller returns: the request waits
 *   in a queue and is bound when the SDK starts streaming.
 */
class ExecutionContext
{
    /**
     * @var list<AiRequest>
     */
    protected array $active = [];

    /**
     * @var list<AiRequest>
     */
    protected array $awaiting = [];

    /**
     * @var array<string, AiRequest>
     */
    protected array $invocations = [];

    /**
     * @var array<string, float>
     */
    protected array $startedAt = [];

    public function push(AiRequest $request): void
    {
        $this->active[] = $request;
    }

    public function pop(AiRequest $request): void
    {
        $this->active = array_values(array_filter($this->active, fn (AiRequest $item) => $item->id !== $request->id));
    }

    public function await(AiRequest $request): void
    {
        $this->awaiting[] = $request;
    }

    public function current(): ?AiRequest
    {
        return $this->active === [] ? null : $this->active[array_key_last($this->active)];
    }

    /**
     * Bind an SDK invocation to the active (or awaiting stream) request.
     */
    public function bind(string $invocationId): ?AiRequest
    {
        $this->startedAt[$invocationId] = microtime(true);

        $request = $this->current() ?? array_shift($this->awaiting);

        if ($request !== null) {
            $this->invocations[$invocationId] = $request;
        }

        return $request;
    }

    public function requestFor(string $invocationId): ?AiRequest
    {
        return $this->invocations[$invocationId] ?? null;
    }

    /**
     * Milliseconds since the invocation started, when known.
     */
    public function elapsed(string $invocationId): ?int
    {
        return isset($this->startedAt[$invocationId]) ? (int) round((microtime(true) - $this->startedAt[$invocationId]) * 1000) : null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->invocations[$invocationId], $this->startedAt[$invocationId]);
    }
}
