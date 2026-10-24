<?php

namespace Starter\Ai\Catalog;

/**
 * A model reported by a provider or a structured catalog.
 */
final readonly class DiscoveredModel
{
    /**
     * @param  list<string>|null  $functions
     * @param  list<string>|null  $inputModalities
     * @param  list<string>|null  $outputModalities
     * @param  array<string, string>  $capabilities  capability => supported|unsupported
     * @param  array<string, string>  $prices  component => amount per million tokens (or per unit)
     */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public ?string $provider = null,
        public ?array $functions = null,
        public ?array $inputModalities = null,
        public ?array $outputModalities = null,
        public ?int $contextWindow = null,
        public ?int $maxOutputTokens = null,
        public array $capabilities = [],
        public array $prices = [],
    ) {}
}
