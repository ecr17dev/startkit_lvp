<?php

namespace Starter\Ai\Simulated;

use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse;

class SimulatedTranscriptionGateway implements TranscriptionGateway
{
    /**
     * Return a fixed, labelled transcription. The duration is estimated from
     * the size (about 16 KB per second of compressed audio).
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $seconds = round(max(1, strlen($audio->content()) / 16000), 1);

        return new TranscriptionResponse(
            (string) __('[Simulated transcription] This text replaces the recorded audio (:seconds s).', ['seconds' => $seconds]),
            collect(),
            new TranscriptionUsage(audioSeconds: $seconds),
            new Meta($provider->name(), $model),
        );
    }
}
