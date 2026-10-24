<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\SaveRagConnection;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Store\ExternalDatabases;

/**
 * Where knowledge lives: the internal PostgreSQL, external PostgreSQL
 * databases with pgvector and retrieval APIs. Diagnosing never changes an
 * external database; activating is the explicit administrative step.
 */
class ConnectionController extends Controller
{
    public function index(Knowledge $knowledge): Response
    {
        Gate::authorize('settings.update');

        $active = $knowledge->mode()->value !== 'disabled' ? $knowledge->connection()?->id : null;
        $knowledge->internalConnection();

        return Inertia::render('admin/knowledge/Connections', [
            'status' => KnowledgePresenter::status(),
            'connections' => RagConnection::query()->orderByRaw("CASE WHEN kind = 'internal' THEN 0 ELSE 1 END")->orderBy('label')->get()
                ->map(fn (RagConnection $connection) => KnowledgePresenter::connectionDetail($connection, $connection->id === $active)),
            'sslModes' => ExternalDatabases::SSL_MODES,
        ]);
    }

    public function store(Request $request, SaveRagConnection $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved. Run its diagnostics before activating it.')]);

        return back();
    }

    public function update(Request $request, RagConnection $connection, SaveRagConnection $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all(), $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved.')]);

        return back();
    }

    public function destroy(Request $request, RagConnection $connection, SaveRagConnection $action): RedirectResponse
    {
        $action->delete($this->actor($request), $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return back();
    }

    public function diagnose(RagConnection $connection, Knowledge $knowledge): RedirectResponse
    {
        Gate::authorize('settings.update');

        $report = $knowledge->diagnose($connection);

        Inertia::flash('toast', $report->passed()
            ? ['type' => 'success', 'message' => __('Diagnostics passed.')]
            : ['type' => 'error', 'message' => __('Diagnostics found problems.')]);

        return back();
    }
}
