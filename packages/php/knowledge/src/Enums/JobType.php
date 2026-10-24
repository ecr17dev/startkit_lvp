<?php

namespace Starter\Knowledge\Enums;

enum JobType: string
{
    // Discover, read and extract the documents of a source...
    case SyncSource = 'sync_source';
    // Chunk, embed and persist one document version in its index versions...
    case IndexDocument = 'index_document';
    // Rebuild a collection in a new index version and publish it when complete...
    case Reindex = 'reindex';
    // Remove originals and vectors of deleted documents...
    case PurgeDocument = 'purge_document';
    case PurgeIndexVersion = 'purge_index_version';
    case PurgeSource = 'purge_source';
    case PurgeCollection = 'purge_collection';
}
