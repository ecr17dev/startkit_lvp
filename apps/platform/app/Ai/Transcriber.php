<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Transcription;
use Starter\Ai\AiGateway;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;

/**
 * Transcribes a completed recording with the transcription model of the
 * agent (independent of its chat model). The cost is recorded on its own
 * ledger request and the audio is not kept after the request.
 */
class Transcriber
{
    /**
     * Largest upload accepted by speech-to-text APIs such as Whisper.
     */
    public const MAX_KILOBYTES = 25 * 1024;

    public const MIME_TYPES = [
        'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'audio/mpeg', 'audio/mp4',
        'video/mp4', 'audio/x-m4a', 'audio/aac', 'audio/wav', 'audio/x-wav', 'audio/wave',
    ];

    public function __construct(protected AiGateway $gateway) {}

    /**
     * Maximum upload size for the configured duration (generous bitrate).
     */
    public static function maxKilobytes(AgentVersion $version): int
    {
        $seconds = (int) $version->settings()->get('microphone.max_seconds', 120);

        return min(self::MAX_KILOBYTES, max(512, $seconds * 48));
    }

    /**
     * @return array{text: string, request: AiRequest}
     *
     * @throws AgentNotAvailableException|ModelNotUsableException
     */
    public function transcribe(User $user, AgentVersion $version, UploadedFile $audio, ?float $duration = null): array
    {
        $config = $version->settings();

        if (! $config->microphoneEnabled()) {
            throw new AgentNotAvailableException((string) __('The microphone is not enabled for this agent.'));
        }

        $model = AiModel::query()->with('connection')->usable()->forFunction('transcription')->find($config->transcriptionModelId())
            ?? throw ModelNotUsableException::none('transcription');

        $language = $config->get('microphone.language');
        $ledger = null;

        $response = $this->gateway->run(
            $model,
            Feature::Transcription,
            $user,
            function (string $provider, string $name, AiRequest $request) use ($audio, $language, &$ledger) {
                $ledger = $request;
                $pending = Transcription::fromUpload($audio)->timeout(120);

                if (is_string($language) && $language !== '') {
                    $pending->language($language);
                }

                return $pending->generate($provider, $name);
            },
            [
                'input_chars' => 0,
                'agent' => $version->agent->slug,
                'agent_version_id' => $version->id,
                'metadata' => array_filter(['duration' => $duration, 'size' => $audio->getSize()]),
            ],
        );

        /** @var AiRequest $ledger */
        return ['text' => trim($response->text), 'request' => $ledger->refresh()];
    }
}
