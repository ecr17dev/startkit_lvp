<?php

namespace Starter\Ai\Exceptions;

use RuntimeException;

/**
 * The proposal can no longer be decided: it expired, was already decided,
 * was superseded by a newer message or belongs to another user.
 */
class ProposalNotPendingException extends RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? (string) __('This change can no longer be confirmed: it expired, was already decided or was replaced by a newer message.'));
    }
}
