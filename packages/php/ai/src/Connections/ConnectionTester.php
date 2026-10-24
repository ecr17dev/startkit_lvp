<?php

namespace Starter\Ai\Connections;

use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Ai\Catalog\ModelDiscovery;
use Starter\Ai\Catalog\ModelVerifier;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Throwable;

/**
 * Tests a connection: lists the provider models when the API allows it,
 * otherwise verifies the default model with a minimal call.
 */
class ConnectionTester
{
    public function __construct(
        protected ModelDiscovery $discovery,
        protected ModelVerifier $verifier,
    ) {}

    /**
     * @return array{ok: bool, status: string, message: string, models: int|null}
     */
    public function test(Connection $connection, ?Authenticatable $actor = null): array
    {
        if ($connection->driver->supportsDiscovery()) {
            try {
                $count = count($this->discovery->discover($connection));
                $result = ['ok' => true, 'status' => 'ok', 'message' => (string) __('Connected. The provider lists :count models.', ['count' => $count]), 'models' => $count];
            } catch (Throwable $exception) {
                $type = ErrorClassifier::classify($exception);
                $result = ['ok' => false, 'status' => $type->value, 'message' => (string) __($type->message()), 'models' => null];
            }
        } else {
            $model = AiModel::query()->where('connection_id', $connection->id)->orderByDesc('is_default')->first();

            if ($model === null) {
                $result = ['ok' => false, 'status' => 'untested', 'message' => (string) __('Add a model to this connection to verify it.'), 'models' => null];
            } else {
                $verification = $this->verifier->verify($model->setRelation('connection', $connection), $actor);
                $result = [
                    'ok' => $verification['ok'],
                    'status' => $verification['ok'] ? 'ok' : (string) $verification['error_type'],
                    'message' => $verification['ok'] ? (string) __('Connected. :model answered.', ['model' => $model->model]) : (string) $verification['message'],
                    'models' => null,
                ];
            }
        }

        $connection->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['status'],
            'last_test_message' => mb_substr($result['message'], 0, 500),
        ])->save();

        audit('ai.connections.tested', $connection, ['status' => $result['status']]);

        return $result;
    }
}
