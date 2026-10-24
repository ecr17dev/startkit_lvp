<?php

namespace Starter\Ai\Attachments;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Models\Attachment;

/**
 * Stores chat attachments on a private disk and hands them to the SDK as
 * stored files (conversations keep a path, not the content). Files are
 * deleted after the retention period.
 */
class AttachmentStore
{
    /**
     * Store an upload whose MIME type was detected from its content.
     */
    public function store(UploadedFile $file, Authenticatable $user, string $mimeType): Attachment
    {
        $disk = self::disk();
        $extension = $file->guessExtension() ?: 'bin';
        $path = 'ai/attachments/'.$user->getAuthIdentifier().'/'.Str::uuid7().'.'.$extension;

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return Attachment::query()->create([
            'user_id' => $user->getAuthIdentifier(),
            'disk' => $disk,
            'path' => $path,
            'name' => Str::limit($file->getClientOriginalName() ?: basename($path), 200, ''),
            'mime_type' => $mimeType,
            'size' => (int) $file->getSize(),
            'kind' => in_array($mimeType, AgentConfig::IMAGE_TYPES, true) ? Attachment::IMAGE : Attachment::DOCUMENT,
            'expires_at' => ($days = self::retentionDays()) > 0 ? now()->addDays($days) : null,
        ]);
    }

    public function toSdk(Attachment $attachment): File
    {
        $file = $attachment->kind === Attachment::IMAGE
            ? Image::fromStorage($attachment->path, $attachment->disk)
            : Document::fromStorage($attachment->path, $attachment->disk);

        return $file->as($attachment->name)->withMimeType($attachment->mime_type);
    }

    /**
     * Link attachments to the ledger request and the conversation.
     *
     * @param  list<Attachment>  $attachments
     */
    public function link(array $attachments, string $requestId, ?string $conversationId): void
    {
        if ($attachments === []) {
            return;
        }

        Attachment::query()
            ->whereIn('id', array_map(fn (Attachment $attachment) => $attachment->id, $attachments))
            ->update(['ai_request_id' => $requestId, 'conversation_id' => $conversationId]);
    }

    /**
     * Delete files whose retention ended.
     */
    public function prune(): int
    {
        $count = 0;

        Attachment::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($attachments) use (&$count) {
                foreach ($attachments as $attachment) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
                    $attachment->delete();
                    $count++;
                }
            }, 'id');

        return $count;
    }

    public static function disk(): string
    {
        return (string) config('starter.ai.attachments.disk', 'local');
    }

    public static function retentionDays(): int
    {
        return (int) config('starter.ai.attachments.retention_days', 30);
    }
}
