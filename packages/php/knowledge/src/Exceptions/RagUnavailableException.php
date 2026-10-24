<?php

namespace Starter\Knowledge\Exceptions;

use RuntimeException;

/**
 * RAG is disabled for the installation or its connection is not ready.
 */
class RagUnavailableException extends RuntimeException {}
