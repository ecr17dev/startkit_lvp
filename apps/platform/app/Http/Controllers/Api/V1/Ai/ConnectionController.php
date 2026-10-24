<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Actions\Ai\DeleteConnection;
use App\Actions\Ai\SaveConnection;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Connections\ConnectionTester;
use Starter\Ai\Models\Connection;

/**
 * Provider connections. Requires `ai.providers.manage`. Secrets are write
 * only: responses contain masked values.
 */
#[Group('AI connections and models', weight: 4)]
class ConnectionController extends Controller
{
    /**
     * List connections.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => Connection::query()->withCount('models')->orderBy('label')->get()->map(fn (Connection $connection) => AiPresenter::connection($connection))->values(),
        ]);
    }

    /**
     * Create a connection.
     *
     * Use `credential_mode: env` with `secret_ref: env:OPENAI_API_KEY` to read
     * the key from the deployment instead of storing it.
     */
    public function store(Request $request, SaveConnection $action): JsonResponse
    {
        $request->validate(SaveConnection::rules());

        return response()->json(['data' => AiPresenter::connection($action->handle($this->actor($request), $request->all()))], 201);
    }

    /**
     * Get a connection.
     */
    public function show(Request $request, Connection $connection): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['data' => AiPresenter::connection($connection->loadCount('models'))]);
    }

    /**
     * Update a connection. Blank secrets keep the stored ones (rotation sets new values).
     */
    public function update(Request $request, Connection $connection, SaveConnection $action): JsonResponse
    {
        $request->validate(SaveConnection::rules($connection));

        return response()->json(['data' => AiPresenter::connection($action->handle($this->actor($request), $request->all(), $connection))]);
    }

    /**
     * Delete a connection and its catalog (the usage ledger is kept).
     */
    public function destroy(Request $request, Connection $connection, DeleteConnection $action): JsonResponse
    {
        $action->handle($this->actor($request), $connection);

        return response()->json(['message' => __('Connection deleted.')]);
    }

    /**
     * Test a connection.
     *
     * Distinguishes `authentication`, `quota`, `rate_limit`, `network` and
     * `model_not_found` failures.
     *
     * @return array{ok: bool, status: string, message: string, models: int|null}
     */
    public function test(Request $request, Connection $connection, ConnectionTester $tester): array
    {
        $this->authorizeManage($request);

        return $tester->test($connection, $this->actor($request));
    }

    /**
     * Discover the provider models into the catalog.
     *
     * New models start disabled and with unknown availability.
     *
     * @return array{created: int, updated: int, total: int}
     */
    public function discover(Request $request, Connection $connection, CatalogManager $catalog): array
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        return $catalog->discover($connection);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($this->actor($request)->can('ai.providers.manage'), 403);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
