<?php

namespace Starter\Knowledge\Enums;

/**
 * Stages of the indexing pipeline, in order.
 */
enum JobStage: string
{
    case Discover = 'discover';
    case Fetch = 'fetch';
    case Validate = 'validate';
    case Extract = 'extract';
    case Normalize = 'normalize';
    case Dedupe = 'dedupe';
    case Chunk = 'chunk';
    case Embed = 'embed';
    case Persist = 'persist';
    case Publish = 'publish';
    case Verify = 'verify';
    case Purge = 'purge';
}
