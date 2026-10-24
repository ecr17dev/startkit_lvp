<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\Metric;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\EmbeddingProfile;

/**
 * Creates a vector space on the active knowledge connection. Model,
 * dimensions and metric are immutable: a different space is a new profile
 * and collections move to it through a reindex.
 */
class SaveEmbeddingProfile
{
    public function __construct(protected Knowledge $knowledge) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $creating = true): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'model_id' => [$creating ? 'required' : 'prohibited', 'integer', Rule::exists('ai_models', 'id')],
            /** Vector dimensions requested from the model (must match its output). */
            'dimensions' => [$creating ? 'required' : 'prohibited', 'integer', 'between:2,16000'],
            'metric' => [$creating ? 'required' : 'prohibited', Rule::enum(Metric::class)],
            'normalize' => ['boolean'],
            'batch_size' => ['nullable', 'integer', 'between:1,512'],
            'max_input_tokens' => ['nullable', 'integer', 'between:128,32000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input, ?EmbeddingProfile $profile = null): EmbeddingProfile
    {
        Gate::forUser($actor)->authorize('rag.collections.manage');
        // Profiles belong to the profile's own connection; a retrieval API embeds by itself...
        $connection = $profile !== null ? $profile->connection : $this->knowledge->ensureAvailable();

        if (! $connection->kind->managed()) {
            throw new RagUnavailableException((string) __('A retrieval API embeds and indexes on its own: it has no embedding profiles.'));
        }

        $validator = Validator::make($input, self::rules($profile === null));

        $validator->after(function ($validator) use ($input, $profile) {
            if ($profile !== null) {
                return;
            }

            $model = AiModel::query()->with('connection')->find((int) ($input['model_id'] ?? 0));

            if ($model === null || ! in_array('embeddings', $model->functions ?? [], true) || ! $model->is_enabled || ! $model->connection->is_active) {
                $validator->errors()->add('model_id', __('Choose an enabled model with the embeddings function.'));
            }
        });

        /** @var array<string, mixed> $data */
        $data = $validator->validate();

        if ($profile !== null) {
            $profile->fill(array_filter([
                'name' => $data['name'],
                'batch_size' => $data['batch_size'] ?? null,
                'max_input_tokens' => $data['max_input_tokens'] ?? null,
            ], fn ($value) => $value !== null))->save();

            audit('rag.profiles.updated', $profile, ['name' => $profile->name], $actor);

            return $profile;
        }

        $model = AiModel::query()->with('connection')->findOrFail((int) $data['model_id']);

        $profile = EmbeddingProfile::query()->create([
            'connection_id' => $connection->id,
            'name' => $data['name'],
            'model_id' => $model->id,
            'provider' => $model->connection->driver->value,
            'model' => $model->model,
            'dimensions' => (int) $data['dimensions'],
            'metric' => $data['metric'],
            'normalize' => (bool) ($data['normalize'] ?? true),
            'batch_size' => (int) ($data['batch_size'] ?? 64),
            'max_input_tokens' => (int) ($data['max_input_tokens'] ?? 8000),
            'status' => EmbeddingProfile::PENDING,
            'created_by' => $actor->id,
        ]);

        $this->knowledge->provisionProfile($profile);

        audit('rag.profiles.created', $profile, ['name' => $profile->name, 'model' => $profile->model, 'dimensions' => $profile->dimensions], $actor);

        return $profile->refresh();
    }
}
