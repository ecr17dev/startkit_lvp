<?php

namespace Starter\Knowledge\Enums;

/**
 * Where the knowledge lives (never whether documents are public).
 */
enum RagMode: string
{
    case Disabled = 'disabled';
    case Internal = 'internal';
    case ExternalPostgres = 'external_pgsql';
    case ExternalApi = 'external_api';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::Internal => 'Internal (this PostgreSQL + pgvector)',
            self::ExternalPostgres => 'External PostgreSQL + pgvector',
            self::ExternalApi => 'External retrieval API',
        };
    }

    public function connectionKind(): ?ConnectionKind
    {
        return match ($this) {
            self::Disabled => null,
            self::Internal => ConnectionKind::Internal,
            self::ExternalPostgres => ConnectionKind::Postgres,
            self::ExternalApi => ConnectionKind::Api,
        };
    }
}
