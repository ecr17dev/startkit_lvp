<?php

namespace Starter\Ai\Exceptions;

use RuntimeException;

/**
 * No usable model/connection is configured for the requested function.
 */
class ModelNotUsableException extends RuntimeException
{
    public static function none(string $function = 'text'): self
    {
        return new self((string) __('No AI model is available for :function. Add a connection and enable a model in Admin → AI.', ['function' => $function]));
    }
}
