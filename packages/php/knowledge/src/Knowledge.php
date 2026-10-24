<?php

namespace Starter\Knowledge;

use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Administration\Settings\SettingsManager;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\ConnectionStatus;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Pipeline\RagJobs;
use Starter\Knowledge\Store\DiagnosticReport;
use Starter\Knowledge\Store\Provisioner;

/**
 * Activation state of the knowledge module. RAG is available only when
 * the installation enables the feature, an administrator chose a mode and
 * the diagnostics of its connection passed. Disabling stops new jobs and
 * retrieval but never deletes documents or tables.
 */
class Knowledge
{
    public function __construct(
        protected SettingsManager $settings,
        protected Provisioner $provisioner,
        protected RagJobs $jobs,
    ) {}

    public function featureEnabled(): bool
    {
        return (bool) config('starter.features.rag');
    }

    public function mode(): RagMode
    {
        if (! $this->featureEnabled()) {
            return RagMode::Disabled;
        }

        return RagMode::tryFrom((string) $this->settings->get('rag.mode', 'disabled')) ?? RagMode::Disabled;
    }

    public function connection(): ?RagConnection
    {
        $id = $this->settings->get('rag.connection_id');

        return is_numeric($id) ? RagConnection::query()->find((int) $id) : null;
    }

    public function available(): bool
    {
        return $this->mode() !== RagMode::Disabled && ($this->connection()?->isReady() ?? false);
    }

    /**
     * @throws RagUnavailableException
     */
    public function ensureAvailable(?Capability $capability = null): RagConnection
    {
        if (! $this->featureEnabled()) {
            throw new RagUnavailableException((string) __('Knowledge (RAG) is disabled in this installation.'));
        }

        $connection = $this->connection();

        if ($this->mode() === RagMode::Disabled || $connection === null || ! $connection->isReady()) {
            throw new RagUnavailableException((string) __('Knowledge (RAG) is not active. An administrator must activate it after the diagnostics.'));
        }

        if ($capability !== null && ! $connection->can($capability)) {
            throw new RagUnavailableException((string) __('The knowledge connection does not support this operation.'));
        }

        return $connection;
    }

    public function internalConnection(): RagConnection
    {
        return RagConnection::query()->firstOrCreate(['name' => 'internal'], [
            'label' => 'Internal PostgreSQL',
            'kind' => ConnectionKind::Internal,
            'status' => ConnectionStatus::Pending,
        ]);
    }

    /**
     * Connection a mode would use (internal only until external
     * connections are configured).
     */
    public function connectionFor(RagMode $mode, ?int $connectionId = null): ?RagConnection
    {
        return match ($mode) {
            RagMode::Internal => $this->internalConnection(),
            RagMode::ExternalPostgres, RagMode::ExternalApi => $connectionId !== null
                ? RagConnection::query()->where('kind', $mode->connectionKind()?->value)->find($connectionId)
                : null,
            RagMode::Disabled => null,
        };
    }

    /**
     * Check a connection without changing anything.
     */
    public function diagnose(RagConnection $connection): DiagnosticReport
    {
        $report = $this->provisioner->diagnose($connection);
        $this->record($connection, $report, false);

        return $report;
    }

    /**
     * Run the diagnostics, provision the schema and switch the mode only
     * when every check passes.
     */
    public function activate(RagMode $mode, ?int $connectionId = null, bool $createExtension = false, ?Authenticatable $actor = null): DiagnosticReport
    {
        if (! $this->featureEnabled()) {
            throw new RagUnavailableException((string) __('Knowledge (RAG) is disabled in this installation.'));
        }

        $connection = $this->connectionFor($mode, $connectionId)
            ?? throw new RagUnavailableException((string) __('Choose a configured knowledge connection.'));

        $report = $connection->kind->managed()
            ? $this->provisioner->diagnose($connection, $createExtension, provision: true)
            : app(Store\ExternalApi\ApiDiagnostics::class)->diagnose($connection);

        $this->record($connection, $report, true);

        if ($report->passed()) {
            // Vector tables of existing profiles (idempotent)...
            foreach ($connection->profiles()->get() as $profile) {
                $this->provisionProfile($profile);
            }

            $this->settings->set(['rag.mode' => $mode->value, 'rag.connection_id' => $connection->id]);
        }

        audit($report->passed() ? 'rag.activated' : 'rag.activation_failed', $connection, ['mode' => $mode->value], $actor);

        return $report;
    }

    public function deactivate(?Authenticatable $actor = null): void
    {
        $this->settings->set(['rag.mode' => RagMode::Disabled->value]);

        foreach (RagJob::query()->whereNull('parent_id')->whereIn('status', JobStatus::open())->get() as $job) {
            $this->jobs->cancel($job);
        }

        audit('rag.deactivated', null, [], $actor);
    }

    public function provisionProfile(EmbeddingProfile $profile): void
    {
        try {
            $this->provisioner->provisionProfile($profile);
        } catch (\Throwable $exception) {
            $profile->forceFill(['status' => EmbeddingProfile::FAILED, 'error' => $this->provisioner->sanitize($exception)])->save();
        }
    }

    protected function record(RagConnection $connection, DiagnosticReport $report, bool $activation): void
    {
        $connection->forceFill([
            'diagnostics' => $report->toArray(),
            'checked_at' => now(),
            'pgvector_version' => $report->pgvector() ?? $connection->pgvector_version,
        ]);

        if ($activation || ! $report->passed()) {
            $connection->forceFill([
                'status' => $report->passed() ? ConnectionStatus::Ready : ConnectionStatus::Failed,
                'capabilities' => $report->passed() ? ($connection->kind->managed() ? Capability::all() : $connection->capabilities) : $connection->capabilities,
            ]);
        }

        $connection->save();
    }
}
