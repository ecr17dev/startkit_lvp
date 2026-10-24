<?php

namespace Starter\Knowledge\Ingestion\Sources;

/**
 * Contract for internal content (application records, generated docs...).
 * The adapter delivers stable ids, text, metadata and the access list of
 * every document; the starter indexes, versions and deletes them.
 */
interface KnowledgeAdapter
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  array<string, mixed>  $config
     * @return iterable<AdapterDocument>
     */
    public function documents(array $config): iterable;
}
