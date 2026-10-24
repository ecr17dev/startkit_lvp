<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\RagConnection;

#[Group('Knowledge (RAG)', weight: 60)]
class ConnectionController extends Controller
{
    /**
     * Knowledge connections (internal, external PostgreSQL and retrieval
     * APIs) with their capabilities and last diagnostics. Credentials are
     * never returned. Requires `settings.update`.
     */
    public function index(Knowledge $knowledge): JsonResponse
    {
        Gate::authorize('settings.update');

        $knowledge->internalConnection();
        $active = $knowledge->mode() !== RagMode::Disabled ? $knowledge->connection()?->id : null;

        return response()->json(['data' => RagConnection::query()->orderBy('id')->get()
            ->map(fn (RagConnection $connection) => KnowledgePresenter::connectionDetail($connection, $connection->id === $active))]);
    }

    /**
     * Run the diagnostics of a connection. Nothing is created or written
     * on an external database.
     */
    public function diagnose(RagConnection $connection, Knowledge $knowledge): JsonResponse
    {
        Gate::authorize('settings.update');

        $report = $knowledge->diagnose($connection);

        return response()->json(['data' => [
            ...$report->toArray(),
            'connection' => KnowledgePresenter::connection($connection->refresh()),
        ]]);
    }

    /**
     * Activate knowledge on a connection: diagnostics, schema provisioning
     * (managed and writable connections only) and the mode switch, only if
     * every check passes. Responds 422 with the report otherwise.
     */
    public function activate(Request $request, RagConnection $connection, Knowledge $knowledge): JsonResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            /** Try CREATE EXTENSION vector (requires a privileged role). */
            'create_extension' => ['boolean'],
        ]);

        $mode = match ($connection->kind) {
            ConnectionKind::Internal => RagMode::Internal,
            ConnectionKind::Postgres => RagMode::ExternalPostgres,
            ConnectionKind::Api => RagMode::ExternalApi,
        };

        $report = $knowledge->activate($mode, $connection->kind === ConnectionKind::Internal ? null : $connection->id, (bool) ($data['create_extension'] ?? false), $this->actor($request));

        return response()->json(['data' => [
            ...$report->toArray(),
            'status' => KnowledgePresenter::status(),
        ]], $report->passed() ? 200 : 422);
    }
}
