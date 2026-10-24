<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Files\File;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Attachments\AttachmentStore;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Attachment;

/**
 * Validates chat attachments by content, size, quantity, the agent settings
 * and the model capabilities, then stores them for the SDK.
 */
class ChatAttachments
{
    public function __construct(protected AttachmentStore $store) {}

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{models: list<Attachment>, files: list<File>}
     *
     * @throws ValidationException
     */
    public function accept(array $files, User $user, AgentVersion $version, AiModel $model): array
    {
        if ($files === []) {
            return ['models' => [], 'files' => []];
        }

        $config = $version->settings();
        $types = $config->mimeTypes();

        if ($types === []) {
            throw ValidationException::withMessages(['files' => __('This agent does not accept attachments.')]);
        }

        Validator::make(['files' => $files], [
            'files' => ['array', 'max:'.$config->maxFiles()],
            // The MIME type is detected from the content, not the extension...
            'files.*' => ['file', 'max:'.$config->maxFileKilobytes(), 'mimetypes:'.implode(',', $this->detectable($types))],
        ], [
            'files.max' => __('You can attach up to :max files.'),
            'files.*.max' => __('Each file can weigh up to :max KB.'),
            'files.*.mimetypes' => __('This type of file is not accepted by the agent.'),
        ])->validate();

        $accepted = [];

        foreach ($files as $file) {
            $mime = (string) $file->getMimeType();
            $image = in_array($mime, AgentConfig::IMAGE_TYPES, true);
            $capability = $image ? Capability::Vision : Capability::Documents;

            if ($model->capabilityStatus($capability) !== CapabilityStatus::Supported) {
                throw ValidationException::withMessages(['files' => $image
                    ? __('The model of this agent does not accept images.')
                    : __('The model of this agent does not accept documents.')]);
            }

            $accepted[] = [$file, $mime];
        }

        $models = array_map(fn (array $item) => $this->store->store($item[0], $user, $item[1]), $accepted);

        return [
            'models' => $models,
            'files' => array_map(fn (Attachment $attachment) => $this->store->toSdk($attachment), $models),
        ];
    }

    /**
     * Markdown and CSV files are detected as plain text.
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    protected function detectable(array $types): array
    {
        if (array_intersect($types, ['text/markdown', 'text/csv']) !== []) {
            $types[] = 'text/plain';
        }

        return array_values(array_unique($types));
    }
}
