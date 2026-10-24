<?php

namespace Starter\Ai\Exceptions;

use RuntimeException;
use Starter\Ai\Models\Budget;

/**
 * A hard budget would be exceeded by the estimated cost of the call.
 */
class BudgetExceededException extends RuntimeException
{
    public function __construct(public readonly ?Budget $budget = null, string $message = '')
    {
        parent::__construct($message !== '' ? $message : (string) __('The AI budget ":name" has been reached.', ['name' => $budget->name ?? '']));
    }
}
