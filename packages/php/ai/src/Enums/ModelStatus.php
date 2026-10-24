<?php

namespace Starter\Ai\Enums;

/**
 * Availability of a model for the credential of its connection. Appearing
 * in a catalog does not mean access: only a minimal call verifies it.
 */
enum ModelStatus: string
{
    case Unknown = 'unknown';
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Deprecated = 'deprecated';
}
