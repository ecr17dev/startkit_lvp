<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Connections\ConnectionResolver;
use Starter\Ai\Enums\BudgetPeriod;
use Starter\Ai\Enums\BudgetScope;
use Starter\Ai\Enums\CostStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\RequestStatus;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Ledger\UsageLedger;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Budget;
use Starter\Ai\Models\BudgetReservation;
use Starter\Ai\Models\BudgetSpend;
use Starter\Ai\Models\CostSnapshot;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Notifications\BudgetThresholdReached;
use Starter\Ai\Pricing\PriceBook;
use Tests\Concerns\CreatesAiCatalog;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use CreatesAiCatalog, RefreshDatabase;

    protected function ping(?User $user = null, ?AiModel $model = null): AiRequest
    {
        $model ??= $this->aiModel();
        $captured = null;

        app(AiGateway::class)->run($model, Feature::Test, $user, function (string $provider, string $name, AiRequest $request) use (&$captured) {
            $captured = $request;

            return (new PingAgent)->prompt('ping', provider: $provider, model: $name);
        }, ['input_chars' => 400, 'max_output_tokens' => 100]);

        return $captured->refresh();
    }

    public function test_calls_are_recorded_with_usage_cost_and_price_snapshots(): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 1000, 500)]);
        $user = User::factory()->create();

        $request = $this->ping($user);

        $this->assertSame(RequestStatus::Succeeded, $request->status);
        $this->assertSame(Feature::Test, $request->feature);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame(1000, $request->input_tokens);
        $this->assertSame(500, $request->output_tokens);
        $this->assertSame(1, $request->attempts_count);
        // 1000 × 2 / 1M + 500 × 10 / 1M...
        $this->assertSame('0.0070000000', (string) $request->cost);
        $this->assertSame(CostStatus::Calculated, $request->cost_status);
        $this->assertNotNull($request->estimated_cost);

        $this->assertSame(['estimate', 'calculated'], $request->costSnapshots()->orderBy('id')->pluck('kind')->all());
        $snapshot = $request->costSnapshots()->where('kind', CostSnapshot::CALCULATED)->sole();
        $this->assertEquals(2, (float) (collect($snapshot->prices)->firstWhere('component', 'input')['amount'] ?? 0));
    }

    public function test_unknown_prices_are_recorded_as_unknown_cost(): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 100, 10)]);

        $request = $this->ping(model: $this->aiModel([]));

        $this->assertNull($request->cost);
        $this->assertSame(CostStatus::Unknown, $request->cost_status);
    }

    public function test_price_changes_never_recalculate_recorded_costs(): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 1_000_000, 0)]);
        $model = $this->aiModel(['input' => '2', 'output' => '10']);

        $request = $this->ping(model: $model);
        app(PriceBook::class)->set($model, PriceComponent::Input, '50', PriceSource::manual());

        $this->assertSame('2.0000000000', (string) $request->refresh()->cost);
    }

    public function test_failed_calls_are_recorded_and_release_the_budget(): void
    {
        $model = $this->aiModel();
        $budget = Budget::query()->create(['name' => 'Global', 'scope' => BudgetScope::Global, 'period' => BudgetPeriod::Monthly, 'limit_amount' => 10]);
        PingAgent::fake(fn () => throw new RuntimeException('provider exploded'));

        try {
            app(AiGateway::class)->run($model, Feature::Test, null, fn (string $provider, string $name) => (new PingAgent)->prompt('ping', provider: $provider, model: $name));
            $this->fail('The exception should propagate.');
        } catch (RuntimeException) {
        }

        $request = AiRequest::query()->sole();
        $this->assertSame(RequestStatus::Failed, $request->status);
        $this->assertNotNull($request->error_message);

        $spend = BudgetSpend::query()->where('budget_id', $budget->id)->sole();
        $this->assertSame(0.0, (float) $spend->reserved);
        $this->assertSame(0.0, (float) $spend->spent);
    }

    public function test_hard_budgets_block_calls_before_they_run(): void
    {
        PingAgent::fake(['OK']);
        Budget::query()->create(['name' => 'Tiny', 'scope' => BudgetScope::Global, 'period' => BudgetPeriod::Daily, 'limit_amount' => '0.000001', 'hard_limit' => true]);

        $this->expectException(BudgetExceededException::class);

        try {
            $this->ping();
        } finally {
            $this->assertSame(RequestStatus::Blocked, AiRequest::query()->sole()->status);
            PingAgent::assertNeverPrompted();
        }
    }

    public function test_budgets_settle_the_real_cost_and_alert_once_per_threshold(): void
    {
        Notification::fake();
        $owner = $this->userWithRole('owner');
        $budget = Budget::query()->create(['name' => 'Team', 'scope' => BudgetScope::Global, 'period' => BudgetPeriod::Monthly, 'limit_amount' => '0.01', 'hard_limit' => false, 'alert_thresholds' => [50, 100]]);

        PingAgent::fake([$this->fakeTextResponse('OK', 1000, 500), $this->fakeTextResponse('OK', 1000, 500)]);
        $model = $this->aiModel();

        $this->ping(model: $model);
        $this->ping(model: $model);

        $spend = BudgetSpend::query()->where('budget_id', $budget->id)->sole();
        $this->assertSame('0.0140000000', (string) $spend->spent);
        $this->assertSame(0.0, (float) $spend->reserved);
        $this->assertSame([50, 100], $spend->alerts()->orderBy('threshold')->pluck('threshold')->all());

        Notification::assertSentToTimes($owner, BudgetThresholdReached::class, 2);
    }

    public function test_budgets_can_refuse_calls_with_unknown_cost(): void
    {
        PingAgent::fake(['OK']);
        Budget::query()->create(['name' => 'Strict', 'scope' => BudgetScope::Feature, 'scope_value' => 'test', 'period' => BudgetPeriod::Monthly, 'limit_amount' => 100, 'block_unknown_cost' => true]);

        $this->expectException(BudgetExceededException::class);

        $this->ping(model: $this->aiModel([]));
    }

    public function test_user_budgets_only_apply_to_their_user(): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 10, 10)]);
        $user = User::factory()->create();
        $other = User::factory()->create();
        Budget::query()->create(['name' => 'Other user', 'scope' => BudgetScope::User, 'scope_value' => (string) $other->id, 'period' => BudgetPeriod::Monthly, 'limit_amount' => '0']);

        $request = $this->ping($user);

        $this->assertSame(RequestStatus::Succeeded, $request->status);
    }

    public function test_direct_sdk_calls_are_recorded_as_implicit_requests(): void
    {
        PingAgent::fake([$this->fakeTextResponse('OK', 20, 5)]);
        $model = $this->aiModel();

        $provider = app(ConnectionResolver::class)->providerName($model->connection);
        (new PingAgent)->prompt('ping', provider: $provider, model: 'gpt-test');

        $request = AiRequest::query()->sole();
        $this->assertSame(Feature::Test, $request->feature);
        $this->assertSame($model->id, $request->model_id);
        $this->assertSame(RequestStatus::Succeeded, $request->status);
        $this->assertSame(20, $request->input_tokens);
        $this->assertNull(config('ai.providers.'.$provider), 'The global configuration is never mutated.');
    }

    public function test_direct_sdk_calls_count_toward_budgets(): void
    {
        $budget = Budget::query()->create(['name' => 'Global', 'scope' => BudgetScope::Global, 'period' => BudgetPeriod::Monthly, 'limit_amount' => '1']);
        PingAgent::fake([$this->fakeTextResponse('OK', 1000, 500)]);
        $model = $this->aiModel();

        (new PingAgent)->prompt('ping', provider: app(ConnectionResolver::class)->providerName($model->connection), model: 'gpt-test');

        // They cannot be blocked beforehand, but their cost is never left out...
        $spend = BudgetSpend::query()->where('budget_id', $budget->id)->sole();
        $this->assertSame('0.0070000000', (string) $spend->spent);
        $this->assertSame(0.0, (float) $spend->reserved);
        $this->assertSame(0, BudgetReservation::query()->count());
    }

    public function test_streamed_calls_are_closed_when_the_stream_completes(): void
    {
        PingAgent::fake([$this->fakeTextResponse('Hello world', 30, 12)]);
        $model = $this->aiModel();

        $stream = app(AiGateway::class)->stream($model, Feature::Chat, null, fn (string $provider, string $name) => (new PingAgent)->stream('hi', provider: $provider, model: $name));

        $this->assertSame(RequestStatus::Running, AiRequest::query()->sole()->status);

        foreach ($stream as $event) {
            //
        }

        $request = AiRequest::query()->sole();
        $this->assertSame(RequestStatus::Succeeded, $request->status);
        $this->assertSame(Feature::Chat, $request->feature);
        $this->assertSame(1, $request->attempts_count);
    }

    public function test_requests_that_never_finish_are_interrupted_with_their_estimate(): void
    {
        $model = $this->aiModel();
        $budget = Budget::query()->create(['name' => 'Global', 'scope' => BudgetScope::Global, 'period' => BudgetPeriod::Monthly, 'limit_amount' => 10, 'hard_limit' => true]);

        app(AiGateway::class)->stream($model, Feature::Chat, null, fn () => null, ['input_chars' => 4000, 'max_output_tokens' => 1000]);

        $this->travel(20)->minutes();
        $this->artisan('starter:ai-maintain')->assertSuccessful();

        $request = AiRequest::query()->sole();
        $this->assertSame(RequestStatus::Interrupted, $request->status);
        $this->assertSame(CostStatus::Estimated, $request->cost_status);
        $this->assertNull($request->cost);

        $spend = BudgetSpend::query()->where('budget_id', $budget->id)->sole();
        $this->assertSame((string) $request->estimated_cost, (string) $spend->spent);
        $this->assertSame(0.0, (float) $spend->reserved);
    }

    public function test_idempotency_keys_prevent_duplicated_requests(): void
    {
        $ledger = app(UsageLedger::class);

        $first = $ledger->start(['feature' => Feature::Embedding, 'idempotency_key' => 'job-42']);
        $second = $ledger->start(['feature' => Feature::Embedding, 'idempotency_key' => 'job-42']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiRequest::query()->count());
    }
}
