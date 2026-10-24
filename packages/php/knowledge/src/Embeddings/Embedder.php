<?php

namespace Starter\Knowledge\Embeddings;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Embeddings;
use RuntimeException;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Knowledge\Models\EmbeddingProfile;

/**
 * Generates embeddings with the model of a profile through the AI gateway,
 * so every call is budgeted and recorded in the ledger (feature
 * "embedding" while indexing, "rag" for queries).
 */
class Embedder
{
    public function __construct(protected AiGateway $gateway) {}

    /**
     * @param  list<string>  $texts
     * @param  array<string, mixed>  $metadata  ledger metadata (collection, job...)
     * @return array{vectors: list<list<float>>, request: AiRequest}
     */
    public function embed(EmbeddingProfile $profile, array $texts, Feature $feature, ?Authenticatable $user = null, array $metadata = []): array
    {
        $model = $this->model($profile);
        $ledger = null;

        $response = $this->gateway->run(
            $model,
            $feature,
            $user,
            function (string $provider, string $name, AiRequest $request) use ($texts, $profile, &$ledger) {
                $ledger = $request;

                return Embeddings::for($texts)->dimensions($profile->dimensions)->timeout(120)->generate($provider, $name);
            },
            [
                'input_chars' => array_sum(array_map('mb_strlen', $texts)),
                'max_output_tokens' => 0,
                'metadata' => array_filter(['embedding_profile_id' => $profile->id, ...$metadata], fn ($value) => $value !== null),
            ],
        );

        if (count($response->embeddings) !== count($texts)) {
            throw new RuntimeException((string) __('The embeddings model returned :got vectors for :expected inputs.', ['got' => count($response->embeddings), 'expected' => count($texts)]));
        }

        $vectors = [];

        foreach ($response->embeddings as $vector) {
            if (count($vector) !== $profile->dimensions) {
                throw new RuntimeException((string) __('The embeddings model returned :got dimensions; the profile expects :expected.', ['got' => count($vector), 'expected' => $profile->dimensions]));
            }

            $vectors[] = $profile->normalize ? $this->normalize($vector) : array_map('floatval', array_values($vector));
        }

        /** @var AiRequest $ledger */
        return ['vectors' => $vectors, 'request' => $ledger->refresh()];
    }

    public function model(EmbeddingProfile $profile): AiModel
    {
        $model = $profile->aiModel()->with('connection')->first();

        if ($model === null || ! $model->is_enabled || ! $model->connection->is_active || $model->model !== $profile->model) {
            throw ModelNotUsableException::none('embeddings');
        }

        return $model;
    }

    /**
     * @param  array<int, float|int>  $vector
     * @return list<float>
     */
    public function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn ($value) => $value * $value, $vector)));

        return $norm > 0
            ? array_values(array_map(fn ($value) => (float) $value / $norm, $vector))
            : array_values(array_map('floatval', $vector));
    }
}
