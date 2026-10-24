<?php

namespace Starter\Ai\Catalog;

use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Ai\Agents\PingAgent;
use Starter\Ai\AiGateway;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Enums\ErrorType;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Models\AiModel;
use Throwable;

/**
 * Verifies a model with a minimal explicit call (recorded in the ledger
 * with the "test" feature, so its cost is never hidden).
 */
class ModelVerifier
{
    public function __construct(protected AiGateway $gateway) {}

    /**
     * @return array{ok: bool, error_type: string|null, message: string|null, latency_ms: int|null}
     */
    public function verify(AiModel $model, ?Authenticatable $actor = null): array
    {
        $started = microtime(true);

        try {
            $this->gateway->run(
                $model,
                Feature::Test,
                $actor,
                fn (string $provider, string $name) => (new PingAgent)->prompt('ping', provider: $provider, model: $name),
                ['input_chars' => 60, 'max_output_tokens' => 16, 'agent' => PingAgent::class],
            );
        } catch (Throwable $exception) {
            $type = ErrorClassifier::classify($exception);

            $model->forceFill([
                'status' => in_array($type, [ErrorType::ModelNotFound, ErrorType::Authentication], true) ? ModelStatus::Unavailable : $model->status,
                'last_error' => $type->value.': '.ErrorClassifier::safeMessage($exception),
            ])->save();

            return ['ok' => false, 'error_type' => $type->value, 'message' => __($type->message()), 'latency_ms' => null];
        }

        $model->forceFill([
            'status' => ModelStatus::Available,
            'verified_at' => now(),
            'last_error' => null,
        ])->save();

        return ['ok' => true, 'error_type' => null, 'message' => null, 'latency_ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}
