<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Pricing\CostCalculator;
use Starter\Ai\Pricing\PriceBook;
use Starter\Ai\Support\Usage;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    public function test_costs_are_exact_decimals_per_million_tokens(): void
    {
        $model = $this->aiModel(['input' => '2.5', 'output' => '10', 'cache_read' => '0.25']);

        $result = app(CostCalculator::class)->calculate($model, new Usage(inputTokens: 1_000_000, outputTokens: 3, cacheReadTokens: 400_000));

        // 600k uncached × 2.5 + 400k cached × 0.25 + 3 × 10 / 1M...
        $this->assertSame(CostStatus::Calculated, $result->status);
        $this->assertSame('1.6000300000', $result->amount);
        $this->assertCount(3, $result->prices);
        $this->assertSame(['input' => 600_000, 'cache_read' => 400_000, 'output' => 3], $result->units);
    }

    public function test_missing_prices_make_the_cost_unknown_never_zero(): void
    {
        $model = $this->aiModel(['input' => '2']);

        $result = app(CostCalculator::class)->calculate($model, new Usage(inputTokens: 100, outputTokens: 50));

        $this->assertNull($result->amount);
        $this->assertSame(CostStatus::Unknown, $result->status);
        $this->assertSame(['output'], $result->missing);

        $unpriced = app(CostCalculator::class)->calculate(null, new Usage(inputTokens: 10));
        $this->assertNull($unpriced->amount);
    }

    public function test_reasoning_and_request_prices_apply_only_when_defined(): void
    {
        $model = $this->aiModel(['input' => '1', 'output' => '4', 'reasoning' => '8']);
        app(PriceBook::class)->set($model, PriceComponent::Request, '0.01', PriceSource::manual(), PriceUnit::PerRequest);

        $result = app(CostCalculator::class)->calculate($model, new Usage(inputTokens: 1_000_000, outputTokens: 1_000_000, reasoningTokens: 250_000));

        // 1 + 750k × 4 + 250k × 8 + 0.01...
        $this->assertSame('6.0100000000', $result->amount);
    }

    public function test_nothing_consumed_costs_nothing(): void
    {
        $model = $this->aiModel([]);

        $this->assertSame('0', app(CostCalculator::class)->calculate($model, new Usage)->amount);
    }

    public function test_price_changes_create_versions_without_altering_history(): void
    {
        $model = $this->aiModel(['input' => '2', 'output' => '10']);
        $before = now();

        $this->travel(1)->hours();

        $book = app(PriceBook::class);
        $this->assertSame(PriceBook::UNCHANGED, $book->set($model, PriceComponent::Input, '2.000', PriceSource::manual()));
        $this->assertSame(PriceBook::UPDATED, $book->set($model, PriceComponent::Input, '3', PriceSource::manual()));

        $this->assertSame(2, ModelPrice::query()->where('model_id', $model->id)->where('component', 'input')->count());
        $this->assertNotNull(ModelPrice::query()->where('model_id', $model->id)->where('component', 'input')->where('amount', 2)->value('effective_to'));

        $usage = new Usage(inputTokens: 1_000_000);
        $this->assertSame('2.0000000000', app(CostCalculator::class)->calculate($model, $usage, $before)->amount);
        $this->assertSame('3.0000000000', app(CostCalculator::class)->calculate($model, $usage)->amount);
    }
}
