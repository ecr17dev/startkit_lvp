<?php

namespace Starter\Ai\Enums;

/**
 * Nature of a cost. An estimate is never presented as an invoice and an
 * unknown cost is never zero.
 */
enum CostStatus: string
{
    // Waiting for the provider usage (running or interrupted calls)...
    case Pending = 'pending';
    // Provisional amount computed before the call...
    case Estimated = 'estimated';
    // Reported usage multiplied by the catalog prices...
    case Calculated = 'calculated';
    // Some units have no price: the cost is unknown...
    case Unknown = 'unknown';
    // Confirmed against the provider invoice...
    case Reconciled = 'reconciled';
}
