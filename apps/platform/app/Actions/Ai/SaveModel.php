<?php

namespace App\Actions\Ai;

use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;

/**
 * Import a model manually (with its source) or update a catalog entry.
 * Capabilities set here are recorded as manual decisions.
 */
class SaveModel
{
    public const FUNCTIONS = ['text', 'embeddings', 'image', 'audio', 'transcription', 'reranking'];

    /**
     * @return array<string, mixed>
     */
    public static function rules(?AiModel $model = null, ?int $connectionId = null): array
    {
        return [
            'model' => [$model ? 'prohibited' : 'required', 'string', 'max:255',
                Rule::unique('ai_models', 'model')->where('connection_id', $connectionId ?? $model?->connection_id)->ignore($model?->id)],
            'label' => ['nullable', 'string', 'max:255'],
            'functions' => ['nullable', 'array'],
            'functions.*' => ['string', Rule::in(self::FUNCTIONS)],
            'input_modalities' => ['nullable', 'array'],
            'input_modalities.*' => ['string', Rule::in(['text', 'image', 'file', 'audio', 'video'])],
            'context_window' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'max_output_tokens' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'capabilities' => ['sometimes', 'array', function (string $attribute, mixed $value, Closure $fail) {
                foreach (array_keys((array) $value) as $capability) {
                    if (Capability::tryFrom((string) $capability) === null) {
                        $fail(__('Unknown capability: :name', ['name' => (string) $capability]));
                    }
                }
            }],
            'capabilities.*' => [Rule::enum(CapabilityStatus::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?AiModel $model = null, ?Connection $connection = null): AiModel
    {
        Gate::forUser($actor)->authorize('ai.models.manage');

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, self::rules($model, $connection?->id))->validate();

        return DB::transaction(function () use ($actor, $data, $model, $connection) {
            $isNew = $model === null;
            $model ??= new AiModel([
                'connection_id' => $connection?->id,
                'model' => $data['model'],
                'source' => 'manual',
                'is_enabled' => true,
            ]);

            $model->fill(Arr::only($data, ['label', 'functions', 'input_modalities', 'context_window', 'max_output_tokens', 'source_url', 'is_enabled', 'is_default']));
            $model->save();

            if ($model->is_default) {
                AiModel::query()->whereKeyNot($model->id)->update(['is_default' => false]);
            }

            foreach ((array) ($data['capabilities'] ?? []) as $capability => $status) {
                app(CatalogManager::class)->setCapability($model, (string) $capability, CapabilityStatus::from((string) $status), 'manual');
            }

            audit($isNew ? 'ai.models.created' : 'ai.models.updated', $model, array_filter([
                'model' => $model->model,
                'changed' => $isNew ? null : array_keys($model->getChanges()),
                'capabilities' => $data['capabilities'] ?? null,
            ]), $actor);

            return $model;
        });
    }
}
