<?php

namespace Starter\Ai\Simulated;

use Illuminate\Support\Facades\DB;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Pricing\PriceBook;

/**
 * Installs the simulated connection and its models, so a fresh local
 * installation can chat, transcribe and run tests without credentials.
 * Its zero prices are real: no provider is called.
 */
class SimulatedCatalog
{
    public function __construct(
        protected PriceBook $prices,
        protected CatalogManager $catalog,
    ) {}

    public function install(bool $makeDefault = true): Connection
    {
        return DB::transaction(function () use ($makeDefault) {
            $connection = Connection::query()->firstOrCreate(['name' => 'simulated'], [
                'label' => 'Simulated (no network)',
                'driver' => Driver::Simulated,
                'credentials' => [],
                'is_active' => true,
            ]);

            $chat = $this->model($connection, 'simulated-chat', 'Simulated chat', ['text'], $makeDefault && ! AiModel::query()->where('is_default', true)->exists());

            foreach (['streaming', 'tools', 'structured_output', 'vision', 'documents'] as $capability) {
                $this->catalog->setCapability($chat, $capability, CapabilityStatus::Supported, 'simulated');
            }

            foreach ([PriceComponent::Input, PriceComponent::Output] as $component) {
                $this->prices->set($chat, $component, '0', PriceSource::simulated());
            }

            $transcriber = $this->model($connection, 'simulated-transcriber', 'Simulated transcriber', ['transcription'], false);
            $this->prices->set($transcriber, PriceComponent::AudioSeconds, '0', PriceSource::simulated(), PriceUnit::PerMinute);

            $embedding = $this->model($connection, 'simulated-embedding', 'Simulated embeddings (lexical)', ['embeddings'], false);
            $this->prices->set($embedding, PriceComponent::Input, '0', PriceSource::simulated());

            return $connection;
        });
    }

    /**
     * @param  list<string>  $functions
     */
    protected function model(Connection $connection, string $name, string $label, array $functions, bool $default): AiModel
    {
        return AiModel::query()->firstOrCreate(['connection_id' => $connection->id, 'model' => $name], [
            'label' => $label,
            'functions' => $functions,
            'status' => ModelStatus::Available,
            'is_enabled' => true,
            'is_default' => $default,
            'context_window' => 32000,
            'source' => 'simulated',
        ]);
    }
}
