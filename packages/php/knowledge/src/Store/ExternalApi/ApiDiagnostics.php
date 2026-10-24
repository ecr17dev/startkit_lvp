<?php

namespace Starter\Knowledge\Store\ExternalApi;

use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\DiagnosticReport;

/**
 * Diagnostics of an external retrieval API (stage 7 adds the adapter).
 */
class ApiDiagnostics
{
    public function diagnose(RagConnection $connection): DiagnosticReport
    {
        return (new DiagnosticReport)->error('adapter', 'Retrieval API', (string) __('External retrieval APIs are not configured in this installation yet.'));
    }
}
