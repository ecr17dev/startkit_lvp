<?php

namespace Starter\Ai\Agents;

/**
 * What a confirmation card shows: the exact target, the values before and
 * after, and the effects. The resource version fingerprints the target so
 * a proposal is rejected when the resource changed before it runs.
 */
final class ProposalPreview
{
    /**
     * @param  list<array{field: string, before: mixed, after: mixed}>  $changes
     * @param  list<string>  $effects
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null,
        public readonly ?string $resourceLabel = null,
        public readonly ?string $resourceVersion = null,
        public readonly array $changes = [],
        public readonly array $effects = [],
        public readonly ?string $url = null,
    ) {}

    /**
     * @return array{title: string, target: array{type: string|null, id: string|null, label: string|null}, changes: list<array{field: string, before: mixed, after: mixed}>, effects: list<string>, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'target' => ['type' => $this->resourceType, 'id' => $this->resourceId, 'label' => $this->resourceLabel],
            'changes' => $this->changes,
            'effects' => $this->effects,
            'url' => $this->url,
        ];
    }
}
