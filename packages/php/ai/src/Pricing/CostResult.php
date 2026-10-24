<?php

namespace Starter\Ai\Pricing;

use Starter\Ai\Enums\CostStatus;

/**
 * Outcome of a cost computation: a decimal amount, or null when some units
 * have no price (unknown, never zero).
 */
final readonly class CostResult
{
    /**
     * @param  list<array<string, mixed>>  $prices  snapshots of the applied prices
     * @param  array<string, int|float>  $units
     * @param  list<string>  $missing  components with units but without price
     */
    public function __construct(
        public ?string $amount,
        public CostStatus $status,
        public array $prices = [],
        public array $units = [],
        public array $missing = [],
        public string $currency = 'USD',
    ) {}

    public function isKnown(): bool
    {
        return $this->amount !== null;
    }
}
