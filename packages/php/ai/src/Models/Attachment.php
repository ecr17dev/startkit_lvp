<?php

namespace Starter\Ai\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $ai_request_id
 * @property string|null $conversation_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime_type
 * @property int $size
 * @property string $kind
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Attachment extends Model
{
    use HasUuids;

    public const IMAGE = 'image';

    public const DOCUMENT = 'document';

    protected $table = 'ai_attachments';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
