<?php

namespace Starter\Knowledge\Ingestion\Fetch;

/**
 * DNS resolution used by the safe fetcher (replaceable in tests).
 */
class HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses of the host
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }

        if ($addresses === []) {
            $addresses = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
