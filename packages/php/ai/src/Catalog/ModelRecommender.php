<?php

namespace Starter\Ai\Catalog;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Starter\Ai\Agents\RecommendationAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\ModelCapability;
use Starter\Ai\Pricing\CostCalculator;
use Throwable;

/**
 * Model recommendations: verifiable requirements filter the catalog, a
 * deterministic score ranks the candidates and, when an evaluator model is
 * available, it compares only those candidates (structured output, ids
 * validated). Without an evaluator, or when its answer is invalid, the
 * deterministic ranking is used.
 */
class ModelRecommender
{
    public const MAX_CANDIDATES = 8;

    public function __construct(
        protected CostCalculator $calculator,
        protected AiGateway $gateway,
    ) {}

    /**
     * @param  array{function?: string, capabilities?: list<string>, max_input_price?: string|float|int|null, max_output_price?: string|float|int|null, min_context?: int|null}  $requirements
     * @return array{candidates: list<array<string, mixed>>, recommendations: list<array<string, mixed>>, source: string, summary: string|null, evaluator: array<string, mixed>|null, excluded: int}
     */
    public function recommend(string $useCase, array $requirements, ?AiModel $evaluator = null, ?Authenticatable $user = null): array
    {
        $all = AiModel::query()->with(['connection', 'capabilities'])->usable()->get();
        $candidates = $this->candidates($all, $requirements);
        $ranked = array_slice($candidates, 0, self::MAX_CANDIDATES);

        $result = [
            'candidates' => $ranked,
            'recommendations' => array_map(fn (array $candidate, int $index) => [...$candidate, 'rank' => $index + 1, 'source' => 'deterministic'], array_slice($ranked, 0, 3), array_keys(array_slice($ranked, 0, 3))),
            'source' => 'deterministic',
            'summary' => null,
            'evaluator' => null,
            'excluded' => $all->count() - count($candidates),
        ];

        if ($evaluator === null || $ranked === [] || trim($useCase) === '') {
            return $result;
        }

        $ledger = null;

        try {
            $response = $this->gateway->run(
                $evaluator->loadMissing('connection'),
                Feature::Recommendation,
                $user,
                function (string $provider, string $name, AiRequest $request) use ($useCase, $ranked, &$ledger) {
                    $ledger = $request;

                    return (new RecommendationAgent)->prompt($this->prompt($useCase, $ranked), provider: $provider, model: $name);
                },
                ['input_chars' => 4000 + mb_strlen($useCase), 'max_output_tokens' => 1200],
            );

            $structured = $response->toArray();
        } catch (Throwable $exception) {
            report($exception);
            $structured = null;
        }

        /** @var AiRequest|null $ledger */
        $ledger?->refresh();

        $result['evaluator'] = [
            'model' => $evaluator->displayName(),
            'request_id' => $ledger?->id,
            'cost' => $ledger?->cost !== null ? (string) $ledger->cost : null,
            'cost_status' => $ledger?->cost_status->value,
            'valid' => false,
        ];

        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];

        foreach ($ranked as $candidate) {
            $byId[(int) $candidate['id']] = $candidate;
        }

        /** @var array<int, array<string, mixed>> $recommendations */
        $recommendations = [];

        foreach ((array) ($structured['recommendations'] ?? []) as $item) {
            $id = is_array($item) && is_numeric($item['model_id'] ?? null) ? (int) $item['model_id'] : null;

            // Only candidates of the catalog are accepted, once each...
            if ($id === null || ! isset($byId[$id]) || isset($recommendations[$id])) {
                continue;
            }

            $recommendations[$id] = array_merge($byId[$id], [
                'rank' => count($recommendations) + 1,
                'source' => 'evaluator',
                'explanation' => mb_substr(trim((string) ($item['reasons'] ?? '')), 0, 1000),
            ]);
        }

        if ($recommendations !== []) {
            $result['recommendations'] = array_values($recommendations);
            $result['source'] = 'evaluator';
            $result['summary'] = mb_substr(trim((string) ($structured['summary'] ?? '')), 0, 2000) ?: null;
            $result['evaluator']['valid'] = true;
        }

        return $result;
    }

    /**
     * Candidates meeting the requirements, best deterministic score first.
     *
     * @param  Collection<int, AiModel>  $models
     * @param  array{function?: string, capabilities?: list<string>, max_input_price?: string|float|int|null, max_output_price?: string|float|int|null, min_context?: int|null}  $requirements
     * @return list<array<string, mixed>>
     */
    public function candidates(Collection $models, array $requirements): array
    {
        $function = $requirements['function'] ?? 'text';
        $required = $requirements['capabilities'] ?? [];
        $maxInput = $requirements['max_input_price'] ?? null;
        $maxOutput = $requirements['max_output_price'] ?? null;
        $minContext = $requirements['min_context'] ?? null;

        $rows = [];

        foreach ($models as $model) {
            if (! $model->supportsFunction($function)) {
                continue;
            }

            $capabilities = $this->capabilities($model);

            foreach ($required as $capability) {
                if (($capabilities[$capability]['status'] ?? 'unknown') !== CapabilityStatus::Supported->value) {
                    continue 2;
                }
            }

            $prices = $this->calculator->pricesFor($model);
            $input = $prices->get(PriceComponent::Input->value)?->amount;
            $output = $prices->get(PriceComponent::Output->value)?->amount;

            // A price limit excludes models whose price is unknown...
            if (($maxInput !== null && ($input === null || BigDecimal::of((string) $input)->isGreaterThan((string) $maxInput)))
                || ($maxOutput !== null && ($output === null || BigDecimal::of((string) $output)->isGreaterThan((string) $maxOutput)))) {
                continue;
            }

            if ($minContext !== null && ($model->context_window === null || $model->context_window < $minContext)) {
                continue;
            }

            $rows[] = [
                'id' => $model->id,
                'label' => $model->displayName(),
                'model' => $model->model,
                'connection' => $model->connection->label,
                'status' => $model->status->value,
                'context_window' => $model->context_window,
                'input_price' => $input !== null ? (string) $input : null,
                'output_price' => $output !== null ? (string) $output : null,
                'capabilities' => $capabilities,
            ];
        }

        return $this->score($rows, $required);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $required
     * @return list<array<string, mixed>>
     */
    protected function score(array $rows, array $required): array
    {
        $blended = array_filter(array_map(fn (array $row) => $this->blendedPrice($row), $rows), fn (?float $price) => $price !== null);
        $min = $blended === [] ? 0.0 : min($blended);
        $max = $blended === [] ? 0.0 : max($blended);
        $wanted = $required !== [] ? $required : ['tools', 'vision', 'structured_output', 'streaming'];

        foreach ($rows as &$row) {
            $reasons = [];
            $price = $this->blendedPrice($row);

            if ($price === null) {
                $priceScore = 0.0;
                $reasons[] = (string) __('Price unknown: its cost would not be calculated.');
            } else {
                $priceScore = $max > $min ? 40 * (1 - ($price - $min) / ($max - $min)) : 40.0;
                $reasons[] = (string) __('Blended price :price USD per million tokens (75% input, 25% output).', ['price' => rtrim(rtrim(number_format($price, 4, '.', ''), '0'), '.')]);
            }

            $capabilityScore = 0.0;

            foreach ($wanted as $capability) {
                $status = $row['capabilities'][$capability] ?? ['status' => 'unknown', 'verified' => false];
                $capabilityScore += $status['status'] === 'supported' ? ($status['verified'] ? 1.0 : 0.7) : 0.0;
            }

            $capabilityScore = 30 * $capabilityScore / count($wanted);
            $verified = array_filter($wanted, fn (string $capability) => ($row['capabilities'][$capability]['verified'] ?? false) === true);

            if ($verified !== []) {
                $reasons[] = (string) __('Verified capabilities: :list.', ['list' => implode(', ', $verified)]);
            }

            $contextScore = $row['context_window'] !== null ? 15 * min(1, $row['context_window'] / 200000) : 0.0;

            if ($row['context_window'] !== null) {
                $reasons[] = (string) __('Context window of :tokens tokens.', ['tokens' => number_format((int) $row['context_window'], 0, ',', '.')]);
            }

            $availability = $row['status'] === ModelStatus::Available->value ? 15.0 : 7.0;

            if ($row['status'] !== ModelStatus::Available->value) {
                $reasons[] = (string) __('Access not verified with a minimal call yet.');
            }

            $row['score'] = (int) round($priceScore + $capabilityScore + $contextScore + $availability);
            $row['reasons'] = $reasons;
        }

        unset($row);

        usort($rows, fn (array $a, array $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function blendedPrice(array $row): ?float
    {
        if ($row['input_price'] === null || $row['output_price'] === null) {
            return null;
        }

        return 0.75 * (float) $row['input_price'] + 0.25 * (float) $row['output_price'];
    }

    /**
     * @return array<string, array{status: string, verified: bool}>
     */
    protected function capabilities(AiModel $model): array
    {
        $statuses = [];

        foreach (Capability::cases() as $capability) {
            /** @var ModelCapability|null $record */
            $record = $model->capabilities->firstWhere('capability', $capability->value);

            $statuses[$capability->value] = [
                'status' => ($record->status ?? CapabilityStatus::Unknown)->value,
                'verified' => $record?->verified_at !== null,
            ];
        }

        return $statuses;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    protected function prompt(string $useCase, array $candidates): string
    {
        $data = array_map(fn (array $candidate) => [
            'model_id' => $candidate['id'],
            'name' => $candidate['label'],
            'connection' => $candidate['connection'],
            'input_price_usd_per_million' => $candidate['input_price'] ?? 'unknown',
            'output_price_usd_per_million' => $candidate['output_price'] ?? 'unknown',
            'context_window' => $candidate['context_window'] ?? 'unknown',
            'capabilities' => array_map(fn (array $status) => $status['status'], $candidate['capabilities']),
        ], $candidates);

        return "Use case:\n".trim($useCase)."\n\nCandidates (JSON):\n".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
