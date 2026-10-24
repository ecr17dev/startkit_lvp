<?php

namespace Starter\Knowledge\Exceptions;

use RuntimeException;

/**
 * The job waits for an administrator (for example a confirmed budget).
 */
class JobPausedException extends RuntimeException {}
