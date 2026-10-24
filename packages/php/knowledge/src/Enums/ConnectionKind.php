<?php

namespace Starter\Knowledge\Enums;

enum ConnectionKind: string
{
    case Internal = 'internal';
    case Postgres = 'pgsql';
    case Api = 'api';

    /**
     * Whether the starter owns the schema (pipeline and full control).
     */
    public function managed(): bool
    {
        return $this !== self::Api;
    }
}
