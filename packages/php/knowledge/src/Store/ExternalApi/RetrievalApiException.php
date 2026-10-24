<?php

namespace Starter\Knowledge\Store\ExternalApi;

use RuntimeException;

/**
 * The retrieval API failed or answered outside the contract (the message
 * never contains credentials).
 */
class RetrievalApiException extends RuntimeException {}
