<?php

namespace Starter\Administration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A customized system template or a custom template (key custom.*).
 *
 * @property int $id
 * @property string $key
 * @property string $locale
 * @property string|null $name
 * @property string $subject
 * @property string $body
 * @property string $format markdown|html
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class MailTemplate extends Model
{
    protected $fillable = ['key', 'locale', 'name', 'subject', 'body', 'format', 'updated_by'];

    public function isCustom(): bool
    {
        return str_starts_with($this->key, 'custom.');
    }
}
