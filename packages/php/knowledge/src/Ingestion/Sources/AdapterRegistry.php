<?php

namespace Starter\Knowledge\Ingestion\Sources;

class AdapterRegistry
{
    /**
     * @var array<string, KnowledgeAdapter>
     */
    protected array $adapters = [];

    public function register(KnowledgeAdapter $adapter): void
    {
        $this->adapters[$adapter->key()] = $adapter;
    }

    public function get(string $key): ?KnowledgeAdapter
    {
        return $this->adapters[$key] ?? null;
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        return array_map(fn (KnowledgeAdapter $adapter) => $adapter->label(), $this->adapters);
    }
}
