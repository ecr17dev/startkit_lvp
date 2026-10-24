<?php

namespace Starter\Ai\Enums;

use Carbon\CarbonInterface;

enum BudgetPeriod: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';

    /**
     * Key of the period containing the date (UTC).
     */
    public function key(CarbonInterface $date): string
    {
        $date = $date->copy()->utc();

        return $this === self::Daily ? $date->format('Y-m-d') : $date->format('Y-m');
    }
}
