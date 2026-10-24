<?php

namespace App\Support;

use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\ModelCapability;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Pricing\CostCalculator;

/**
 * Shapes AI catalog records for the panel and the API. Secrets are masked.
 */
class AiPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function connection(Connection $connection): array
    {
        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'label' => $connection->label,
            'driver' => $connection->driver->value,
            'driver_label' => $connection->driver->label(),
            'base_url' => $connection->base_url,
            'endpoint' => $connection->endpoint(),
            'credential_mode' => filled($connection->secret_ref) ? 'env' : 'stored',
            'secret_ref' => $connection->secret_ref,
            'masked_credentials' => collect($connection->driver->credentials())
                ->mapWithKeys(fn (string $key) => [$key => $connection->maskedCredential($key)])
                ->all(),
            'has_api_key' => $connection->credential('api_key') !== null,
            'options' => (object) ($connection->options ?? []),
            'is_active' => $connection->is_active,
            'is_default' => $connection->is_default,
            'models_count' => $connection->getAttribute('models_count'),
            'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
            'last_test_status' => $connection->last_test_status,
            'last_test_message' => $connection->last_test_message,
            'credentials_rotated_at' => $connection->credentials_rotated_at?->toIso8601String(),
            'discovery' => $connection->driver->supportsDiscovery(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function model(AiModel $model, bool $withPrices = true): array
    {
        $capabilities = [];

        foreach (Capability::cases() as $capability) {
            /** @var ModelCapability|null $record */
            $record = $model->relationLoaded('capabilities') ? $model->capabilities->firstWhere('capability', $capability->value) : null;

            $capabilities[$capability->value] = [
                'status' => ($record->status ?? CapabilityStatus::Unknown)->value,
                'source' => $record?->source,
                'verified_at' => $record?->verified_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $model->id,
            'model' => $model->model,
            'label' => $model->label,
            'name' => $model->displayName(),
            'provider' => $model->provider,
            'connection' => $model->relationLoaded('connection') ? ['id' => $model->connection->id, 'label' => $model->connection->label, 'driver' => $model->connection->driver->value] : null,
            'functions' => $model->functions,
            'input_modalities' => $model->input_modalities,
            'output_modalities' => $model->output_modalities,
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'status' => $model->status->value,
            'source' => $model->source,
            'source_url' => $model->source_url,
            'discovered_at' => $model->discovered_at?->toIso8601String(),
            'verified_at' => $model->verified_at?->toIso8601String(),
            'last_error' => $model->last_error,
            'is_enabled' => $model->is_enabled,
            'is_default' => $model->is_default,
            'capabilities' => $capabilities,
            'prices' => $withPrices ? self::currentPrices($model) : null,
        ];
    }

    /**
     * @return array<string, array{amount: string, unit: string, source: string|null, effective_from: string}>
     */
    public static function currentPrices(AiModel $model): array
    {
        return app(CostCalculator::class)->pricesFor($model)
            ->map(fn (ModelPrice $price) => [
                'amount' => (string) $price->amount,
                'unit' => $price->unit->value,
                'source' => $price->source->key,
                'effective_from' => $price->effective_from->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function price(ModelPrice $price): array
    {
        return [
            'id' => $price->id,
            'component' => $price->component->value,
            'unit' => $price->unit->value,
            'amount' => (string) $price->amount,
            'currency' => $price->currency,
            'tier' => $price->tier,
            'source' => $price->relationLoaded('source') ? ['key' => $price->source->key, 'name' => $price->source->name, 'kind' => $price->source->kind] : null,
            'effective_from' => $price->effective_from->toIso8601String(),
            'effective_to' => $price->effective_to?->toIso8601String(),
            'retrieved_at' => $price->retrieved_at?->toIso8601String(),
            'notes' => $price->notes,
        ];
    }
}
