<?php

namespace Starter\Knowledge\Ingestion\Fetch;

use RuntimeException;

/**
 * The URL points to a destination the reader must never contact.
 */
class BlockedUrlException extends RuntimeException {}
