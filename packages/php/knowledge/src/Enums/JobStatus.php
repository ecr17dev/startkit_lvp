<?php

namespace Starter\Knowledge\Enums;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Retrying = 'retrying';
    case Paused = 'paused';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function finished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    /**
     * @return list<string>
     */
    public static function open(): array
    {
        return [self::Queued->value, self::Running->value, self::Retrying->value, self::Paused->value];
    }
}
