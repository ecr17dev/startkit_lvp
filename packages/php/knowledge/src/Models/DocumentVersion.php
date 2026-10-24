<?php

namespace Starter\Knowledge\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extracted, normalized content of a document at a moment. Reindexing
 * re-chunks this content without reading the origin again.
 *
 * @property int $id
 * @property int $document_id
 * @property int $number
 * @property string $content_hash
 * @property string $parser
 * @property string|null $mime_type
 * @property int $size
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string $content
 * @property list<array{page: int, start: int, end: int}>|null $pages
 * @property array<string, mixed>|null $metadata
 * @property list<string>|null $warnings
 * @property CarbonInterface|null $created_at
 * @property-read Document $document
 */
class DocumentVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'rag_document_versions';

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
            'number' => 'integer',
            'size' => 'integer',
            'pages' => 'array',
            'metadata' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
