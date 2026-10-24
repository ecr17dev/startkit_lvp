<?php

namespace Starter\Ai\Enums;

/**
 * An unverified capability is unknown, never assumed.
 */
enum CapabilityStatus: string
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';
}
