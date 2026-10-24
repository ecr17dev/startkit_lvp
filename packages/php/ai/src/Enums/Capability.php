<?php

namespace Starter\Ai\Enums;

enum Capability: string
{
    case Streaming = 'streaming';
    case Tools = 'tools';
    case StructuredOutput = 'structured_output';
    case Vision = 'vision';
    case Documents = 'documents';
    case AudioInput = 'audio_input';
    case Reasoning = 'reasoning';
    case PromptCaching = 'prompt_caching';
    case Temperature = 'temperature';
    case WebSearch = 'web_search';

    public function label(): string
    {
        return match ($this) {
            self::Streaming => 'Streaming',
            self::Tools => 'Tools',
            self::StructuredOutput => 'Structured output',
            self::Vision => 'Images',
            self::Documents => 'Documents',
            self::AudioInput => 'Audio input',
            self::Reasoning => 'Reasoning',
            self::PromptCaching => 'Prompt caching',
            self::Temperature => 'Temperature',
            self::WebSearch => 'Web search',
        };
    }
}
