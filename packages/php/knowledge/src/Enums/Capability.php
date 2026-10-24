<?php

namespace Starter\Knowledge\Enums;

/**
 * Operations a RAG connection declares; the interface only enables these.
 */
enum Capability: string
{
    case Query = 'query';
    case ListCollections = 'list_collections';
    case ListDocuments = 'list_documents';
    case Ingest = 'ingest';
    case Delete = 'delete';
    case Jobs = 'jobs';
    case Metrics = 'metrics';
    case Chunks = 'chunks';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn (self $capability) => $capability->value, self::cases());
    }
}
