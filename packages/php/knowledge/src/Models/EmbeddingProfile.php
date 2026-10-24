<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\Metric;

/**
 * A vector space: connection, model, dimensions, metric and version. A
 * query must use exactly the profile of the documents it searches; a
 * different model is a different space even with equal dimensions.
 *
 * @property int $id
 * @property int $connection_id
 * @property string $name
 * @property int|null $model_id
 * @property string $provider
 * @property string $model
 * @property int $dimensions
 * @property Metric $metric
 * @property bool $normalize
 * @property int $max_input_tokens
 * @property int $batch_size
 * @property array{table?: string, index?: string|null, pgvector?: string|null, exact?: bool}|null $storage
 * @property string $status
 * @property string|null $error
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read RagConnection $connection
 * @property-read AiModel|null $aiModel
 */
class EmbeddingProfile extends Model
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $table = 'rag_embedding_profiles';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'metric' => Metric::class,
            'normalize' => 'boolean',
            'max_input_tokens' => 'integer',
            'batch_size' => 'integer',
            'storage' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RagConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(RagConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }

    /**
     * Data plane table holding the vectors of this profile.
     */
    public function table(): string
    {
        return 'rag_embeddings_p'.$this->id;
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }
}
