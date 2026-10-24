<?php

namespace Tests\Concerns;

use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Pricing\PriceBook;

trait CreatesAiCatalog
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function aiConnection(array $attributes = []): Connection
    {
        return Connection::query()->create([
            'name' => 'openai-main',
            'label' => 'OpenAI main',
            'driver' => Driver::OpenAI,
            'credentials' => ['api_key' => 'sk-test-secret-1234'],
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, string>  $prices  component => USD per million tokens
     * @param  array<string, mixed>  $attributes
     */
    protected function aiModel(array $prices = ['input' => '2', 'output' => '10'], array $attributes = [], ?Connection $connection = null): AiModel
    {
        $model = AiModel::query()->create([
            'connection_id' => ($connection ?? $this->aiConnection())->id,
            'model' => 'gpt-test',
            'functions' => ['text'],
            'status' => ModelStatus::Available,
            'is_enabled' => true,
            'is_default' => true,
            ...$attributes,
        ]);

        foreach ($prices as $component => $amount) {
            app(PriceBook::class)->set($model, PriceComponent::from($component), $amount, PriceSource::manual());
        }

        return $model->load('connection');
    }

    protected function fakeTextResponse(string $text, int $input, int $output, int $cacheRead = 0, int $reasoning = 0, string $model = 'gpt-test'): TextResponse
    {
        return new TextResponse($text, new TextUsage($input, $output, $cacheRead, 0, $reasoning), new Meta('openai', $model));
    }
}
