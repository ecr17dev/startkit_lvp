<?php

namespace Starter\Ai\Catalog;

use Illuminate\Support\Facades\DB;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\ModelCapability;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Models\PriceSyncRun;
use Starter\Ai\Pricing\PriceBook;
use Throwable;

/**
 * Keeps the model catalog of a connection in sync with what its provider
 * reports, preserving the exact identifiers and every manual decision
 * (enabled flag, labels, verified status).
 */
class CatalogManager
{
    public function __construct(
        protected ModelDiscovery $discovery,
        protected PriceBook $prices,
    ) {}

    /**
     * @return array{created: int, updated: int, total: int}
     */
    public function discover(Connection $connection): array
    {
        $models = $this->discovery->discover($connection);
        $source = $connection->driver === Driver::OpenRouter ? 'catalog:openrouter' : 'provider_api';
        $stats = ['created' => 0, 'updated' => 0, 'total' => count($models)];

        DB::transaction(function () use ($connection, $models, $source, &$stats) {
            foreach ($models as $discovered) {
                $model = AiModel::query()->firstOrNew(['connection_id' => $connection->id, 'model' => $discovered->id]);
                $isNew = ! $model->exists;

                $model->fill(array_filter([
                    'label' => $model->label ?? $discovered->label,
                    'provider' => $discovered->provider,
                    'functions' => $model->functions ?? $discovered->functions,
                    'input_modalities' => $discovered->inputModalities,
                    'output_modalities' => $discovered->outputModalities,
                    'context_window' => $discovered->contextWindow,
                    'max_output_tokens' => $discovered->maxOutputTokens,
                ], fn ($value) => $value !== null));

                $model->forceFill([
                    'source' => $isNew ? $source : $model->source,
                    'discovered_at' => now(),
                ]);

                if ($isNew) {
                    $model->forceFill(['status' => ModelStatus::Unknown, 'is_enabled' => false]);
                }

                $model->save();
                $stats[$isNew ? 'created' : 'updated']++;

                foreach ($discovered->capabilities as $capability => $status) {
                    $this->setCapability($model, $capability, CapabilityStatus::from($status), $source, verified: false);
                }
            }
        });

        audit('ai.models.discovered', $connection, $stats);

        return $stats;
    }

    /**
     * Sync the gateway prices of the OpenRouter connections.
     */
    public function syncOpenRouterPrices(?int $triggeredBy = null): PriceSyncRun
    {
        $source = PriceSource::openRouter();
        $run = PriceSyncRun::query()->create([
            'price_source_id' => $source->id,
            'status' => 'running',
            'triggered_by' => $triggeredBy,
            'started_at' => now(),
        ]);

        $stats = ['models' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0];

        try {
            $connections = Connection::query()->where('driver', Driver::OpenRouter->value)->get();

            foreach ($connections as $connection) {
                $catalog = collect(app(OpenRouterCatalog::class)->models($connection))->keyBy('id');

                AiModel::query()->where('connection_id', $connection->id)->each(function (AiModel $model) use ($catalog, $source, &$stats) {
                    $entry = $catalog->get($model->model);

                    if ($entry === null) {
                        return;
                    }

                    $stats['models']++;

                    foreach (PriceComponent::cases() as $component) {
                        if (isset($entry->prices[$component->value])) {
                            $result = $this->prices->set($model, $component, $entry->prices[$component->value], $source, retrievedAt: now(), notes: 'OpenRouter models API');
                            $stats[$result]++;
                        } elseif ($this->prices->closeFromSource($model, $component, $source)) {
                            $stats['removed']++;
                        }
                    }
                });
            }

            $run->forceFill(['status' => 'succeeded', 'stats' => $stats, 'finished_at' => now()])->save();
            $source->forceFill(['last_synced_at' => now()])->save();
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'stats' => $stats, 'error' => mb_substr($exception->getMessage(), 0, 1000), 'finished_at' => now()])->save();
        }

        return $run;
    }

    public function setCapability(AiModel $model, string $capability, CapabilityStatus $status, string $source, bool $verified = true): void
    {
        $record = ModelCapability::query()->firstOrNew(['model_id' => $model->id, 'capability' => $capability]);

        // Manual or verified values are not overwritten by catalogs...
        if ($record->exists && ! $verified && ($record->verified_at !== null || $record->source === 'manual')) {
            return;
        }

        $record->forceFill([
            'status' => $status,
            'source' => $source,
            'verified_at' => $verified ? now() : null,
        ])->save();
    }
}
