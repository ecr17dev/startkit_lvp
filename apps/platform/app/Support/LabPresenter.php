<?php

namespace App\Support;

use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\LabResult;
use Starter\Ai\Models\LabRun;

/**
 * Shapes lab runs for the panel and the API.
 */
class LabPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function run(LabRun $run, bool $withResults = false): array
    {
        $counts = $run->results()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $data = [
            'id' => $run->id,
            'name' => $run->name,
            'status' => $run->status,
            'prompts' => $run->prompts,
            'system_prompt' => $run->system_prompt,
            'max_tokens' => $run->max_tokens,
            'max_cost' => (string) $run->max_cost,
            'max_concurrency' => $run->max_concurrency,
            'spent' => (string) $run->spent,
            'reserved' => (string) $run->reserved,
            'counts' => [
                'total' => (int) $counts->sum(),
                'pending' => (int) ($counts[LabResult::PENDING] ?? 0) + (int) ($counts[LabResult::RUNNING] ?? 0),
                'succeeded' => (int) ($counts[LabResult::SUCCEEDED] ?? 0),
                'failed' => (int) ($counts[LabResult::FAILED] ?? 0),
                'skipped' => (int) ($counts[LabResult::SKIPPED] ?? 0),
            ],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at->toIso8601String(),
        ];

        if ($withResults) {
            $models = AiModel::query()->with('connection')->whereIn('id', $run->model_ids)->get()->keyBy('id');

            $data['models'] = array_map(fn (int $id) => [
                'id' => $id,
                'label' => $models->get($id)?->displayName() ?? "#{$id}",
                'connection' => $models->get($id)?->connection->label,
            ], $run->model_ids);

            $data['results'] = $run->results()->orderBy('prompt_index')->orderBy('model_id')->get()->map(fn (LabResult $result) => [
                'id' => $result->id,
                'model_id' => $result->model_id,
                'prompt_index' => $result->prompt_index,
                'status' => $result->status,
                'output' => $result->output,
                'error' => $result->error,
                'latency_ms' => $result->latency_ms,
                'ttft_ms' => $result->ttft_ms,
                'input_tokens' => $result->input_tokens,
                'output_tokens' => $result->output_tokens,
                'estimated_cost' => $result->estimated_cost !== null ? (string) $result->estimated_cost : null,
                'cost' => $result->cost !== null ? (string) $result->cost : null,
                'cost_status' => $result->cost_status,
                'request_id' => $result->ai_request_id,
            ])->values()->all();
        }

        return $data;
    }

    /**
     * Usable text models offered in the lab and recommender forms.
     *
     * @return list<array{id: int, label: string, connection: string, is_default: bool}>
     */
    public static function models(): array
    {
        return array_values(AiModel::query()->with('connection')->usable()->forFunction('text')->orderByDesc('is_default')->orderBy('model')->get()
            ->map(fn (AiModel $model) => [
                'id' => $model->id,
                'label' => $model->displayName(),
                'connection' => $model->connection->label,
                'is_default' => $model->is_default,
            ])->all());
    }
}
