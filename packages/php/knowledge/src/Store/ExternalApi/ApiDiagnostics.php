<?php

namespace Starter\Knowledge\Store\ExternalApi;

use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\DiagnosticReport;

/**
 * Diagnostics of a retrieval API: address, contract version, declared
 * capabilities, access list isolation and a test search. Nothing is
 * written on the service.
 */
class ApiDiagnostics
{
    public function __construct(protected RetrievalApiClient $client) {}

    public function diagnose(RagConnection $connection): DiagnosticReport
    {
        $report = new DiagnosticReport;
        $url = (string) $connection->setting('base_url', '');

        if (! preg_match('#^https?://#i', $url)) {
            return $report->error('config', 'Address', __('Configure the base URL of the retrieval API.'));
        }

        if (str_starts_with(strtolower($url), 'https://')) {
            $report->ok('tls', 'TLS', __('The connection is encrypted (HTTPS).'));
        } elseif (app()->isProduction()) {
            return $report->error('tls', 'TLS', __('Retrieval APIs require HTTPS in production.'));
        } else {
            $report->warning('tls', 'TLS', __('The connection is not encrypted (acceptable for a local or private network).'));
        }

        try {
            $declared = $this->client->capabilities($connection);
        } catch (RetrievalApiException $exception) {
            return $report->error('connect', 'Connectivity', $exception->getMessage());
        }

        if ($declared['version'] !== RetrievalApiClient::VERSION) {
            return $report->error('contract', 'Contract', __('The service implements contract version ":version"; version 1 is required.', ['version' => $declared['version']]));
        }

        $report->ok('connect', 'Connectivity', __('The service answered the contract version 1.'));

        if (! in_array('query', $declared['capabilities'], true)) {
            return $report->error('capabilities', 'Capabilities', __('The service does not declare the query capability.'));
        }

        $report->setCapabilities($declared['capabilities']);
        $report->ok('capabilities', 'Capabilities', __('Declared: :list. Ingestion, deletions and jobs are managed by the service.', ['list' => implode(', ', $declared['capabilities'])]));

        // Private collections need the service to filter by principals...
        $configured = (bool) $connection->setting('enforces_acl', false);

        if ($configured && ! $declared['enforces_acl']) {
            $report->error('isolation', 'Access lists', __('The connection expects the service to enforce the access lists, but the service does not declare it.'));
        } elseif ($configured) {
            $report->ok('isolation', 'Access lists', __('The service filters with the principals of each user; private collections are allowed.'));
        } else {
            $report->warning('isolation', 'Access lists', __('Without guaranteed isolation only public collections can use this connection.'));
        }

        try {
            if (in_array('list_collections', $declared['capabilities'], true)) {
                $collections = $this->client->collections($connection);
                $report->ok('collections', 'Collections', trans_choice('{0} The service lists no collections.|{1} The service lists one collection.|[2,*] The service lists :count collections.', count($collections), ['count' => count($collections)]));
            }

            $this->client->search($connection, 'diagnostic probe', [], 1, ['diagnostic'], null);
            $report->ok('smoke', 'Search test', __('The service answered a test search.'));
        } catch (RetrievalApiException $exception) {
            $report->error('smoke', 'Search test', $exception->getMessage());
        }

        return $report;
    }
}
