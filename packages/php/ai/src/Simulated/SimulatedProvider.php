<?php

namespace Starter\Ai\Simulated;

use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;

/**
 * Local provider without network calls, for development, demos and end to
 * end tests when no real credentials exist. Its answers are deterministic
 * and always labelled as simulated.
 */
class SimulatedProvider extends OpenAiCompatibleProvider
{
    public const DRIVER = 'starter-simulated';

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new SimulatedTextGateway;
    }

    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new SimulatedTranscriptionGateway;
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new SimulatedEmbeddingGateway;
    }

    public function defaultEmbeddingsModel(): string
    {
        return 'simulated-embedding';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return 1024;
    }

    public function defaultTextModel(): string
    {
        return 'simulated-chat';
    }

    public function cheapestTextModel(): string
    {
        return 'simulated-chat';
    }

    public function smartestTextModel(): string
    {
        return 'simulated-chat';
    }

    public function defaultTranscriptionModel(): string
    {
        return 'simulated-transcriber';
    }
}
