<?php

namespace Starter\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Compares only the candidate models given in the prompt and explains its
 * choice. It never provides prices, capabilities or benchmarks: those are
 * shown from the catalog, and unknown model ids are discarded.
 */
#[MaxTokens(1200)]
#[Timeout(60)]
class RecommendationAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You help an administrator choose AI models. Compare only the candidates listed in the message, using the data given (prices, context window and capability statuses) and the described use case. '
            .'Recommend at most 3 candidates by their exact model_id, best first, with a short justification in the language of the use case. '
            .'Do not invent prices, capabilities or benchmark results; if the data is unknown, say it is unknown.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'recommendations' => $schema->array()->items($schema->object([
                'model_id' => $schema->integer()->required(),
                'reasons' => $schema->string()->required(),
            ]))->max(3)->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
