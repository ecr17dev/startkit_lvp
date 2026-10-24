<?php

namespace App\Actions\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Catalog\ModelRecommender;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Models\AiModel;

class RecommendModels
{
    public function __construct(protected ModelRecommender $recommender) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            /** What the model will be used for. */
            'use_case' => ['required', 'string', 'max:4000'],
            'function' => ['nullable', Rule::in(SaveModel::FUNCTIONS)],
            /** Capabilities the model must support (declared or verified). */
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', Rule::enum(Capability::class)],
            /** Maximum input price in USD per million tokens. */
            'max_input_price' => ['nullable', 'numeric', 'min:0'],
            /** Maximum output price in USD per million tokens. */
            'max_output_price' => ['nullable', 'numeric', 'min:0'],
            'min_context' => ['nullable', 'integer', 'min:1'],
            /** Model that compares the candidates; null uses only the deterministic ranking. */
            'evaluator_model_id' => ['nullable', 'integer', Rule::exists('ai_models', 'id')->where('is_enabled', true)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('ai.models.manage');

        $data = Validator::make($input, self::rules())->validate();

        $evaluator = isset($data['evaluator_model_id'])
            ? AiModel::query()->with('connection')->usable()->forFunction('text')->find((int) $data['evaluator_model_id'])
            : null;

        return $this->recommender->recommend((string) $data['use_case'], array_filter([
            'function' => $data['function'] ?? 'text',
            'capabilities' => array_values($data['capabilities'] ?? []),
            'max_input_price' => $data['max_input_price'] ?? null,
            'max_output_price' => $data['max_output_price'] ?? null,
            'min_context' => isset($data['min_context']) ? (int) $data['min_context'] : null,
        ], fn (mixed $value) => $value !== null), $evaluator, $actor);
    }
}
