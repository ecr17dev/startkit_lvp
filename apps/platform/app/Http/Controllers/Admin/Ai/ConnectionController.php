<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Actions\Ai\DeleteConnection;
use App\Actions\Ai\SaveConnection;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Catalog\CatalogManager;
use Starter\Ai\Connections\ConnectionTester;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Enums\Driver;
use Starter\Ai\Models\Connection;
use Throwable;

class ConnectionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ai/connections/Index', [
            'connections' => Connection::query()->withCount('models')->orderByDesc('is_default')->orderBy('label')->get()
                ->map(fn (Connection $connection) => AiPresenter::connection($connection))
                ->values(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/ai/connections/Form', [
            'connection' => null,
            ...$this->formData(),
        ]);
    }

    public function store(Request $request, SaveConnection $action): RedirectResponse
    {
        $connection = $action->handle($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection saved. Test it and discover its models.')]);

        return to_route('admin.ai.connections.index', ['highlight' => $connection->id]);
    }

    public function edit(Connection $connection): Response
    {
        return Inertia::render('admin/ai/connections/Form', [
            'connection' => AiPresenter::connection($connection),
            ...$this->formData(),
        ]);
    }

    public function update(Request $request, Connection $connection, SaveConnection $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all(), $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection updated.')]);

        return to_route('admin.ai.connections.index');
    }

    public function destroy(Request $request, Connection $connection, DeleteConnection $action): RedirectResponse
    {
        $action->handle($this->actor($request), $connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection deleted.')]);

        return to_route('admin.ai.connections.index');
    }

    /**
     * Test the credential and endpoint of the connection.
     */
    public function test(Request $request, Connection $connection, ConnectionTester $tester): RedirectResponse
    {
        $result = $tester->test($connection, $this->actor($request));

        Inertia::flash('toast', ['type' => $result['ok'] ? 'success' : 'error', 'message' => $result['message']]);

        return back();
    }

    /**
     * List the provider models into the catalog (new ones start disabled).
     */
    public function discover(Request $request, Connection $connection, CatalogManager $catalog): RedirectResponse
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        try {
            $stats = $catalog->discover($connection);
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':total models found (:created new). New models start disabled.', $stats)]);
        } catch (Throwable $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __(ErrorClassifier::classify($exception)->message()).' '.ErrorClassifier::safeMessage($exception)]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(): array
    {
        return [
            'drivers' => Driver::catalog(),
            'secretRefs' => array_values((array) config('starter.ai.secret_refs', [])),
        ];
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
