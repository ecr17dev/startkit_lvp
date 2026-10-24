<?php

namespace Starter\Administration\Health;

/**
 * Result of a single readiness check.
 */
final readonly class HealthCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public function __construct(
        public string $name,
        public string $status,
        public bool $required,
        public ?string $message = null,
        public ?float $durationMs = null,
    ) {}

    /**
     * Determine whether the check blocks readiness.
     */
    public function blocksReadiness(): bool
    {
        return $this->required && $this->status === self::FAILED;
    }

    /**
     * @return array{name: string, status: string, required: bool, message: string|null, duration_ms: float|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'required' => $this->required,
            'message' => $this->message,
            'duration_ms' => $this->durationMs,
        ];
    }
}
