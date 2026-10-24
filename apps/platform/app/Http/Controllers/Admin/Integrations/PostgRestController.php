<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Http\Controllers\Controller;
use App\PostgRest\PostgRest;
use App\PostgRest\SchemaInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * PostgREST status: feature flag, address, signing secret, published
 * objects, roles and grants, and a test through PostgREST as the current
 * user (server to server, with a short signed JWT).
 */
class PostgRestController extends Controller
{
    public function index(PostgRest $postgrest, SchemaInstaller $installer): Response
    {
        Gate::authorize('settings.update');

        try {
            $status = $installer->status();
            $error = null;
        } catch (Throwable $exception) {
            [$status, $error] = [null, $exception->getMessage()];
        }

        return Inertia::render('admin/integrations/PostgRest', [
            'enabled' => $postgrest->enabled(),
            'url' => $postgrest->url(),
            'secretConfigured' => $postgrest->signer()->configured(),
            'ttl' => (int) config('starter.postgrest.ttl'),
            'directTokens' => (bool) config('starter.postgrest.direct_tokens'),
            'roles' => config('starter.postgrest.roles'),
            'install' => $status,
            'installError' => $error,
            'objects' => SchemaInstaller::OBJECTS,
        ]);
    }

    /**
     * Read the published objects through PostgREST as the current user.
     */
    public function test(Request $request, PostgRest $postgrest): RedirectResponse
    {
        Gate::authorize('settings.update');
        $actor = $this->actor($request);

        if (! $postgrest->enabled() || ! $postgrest->signer()->configured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('PostgREST is disabled or its signing secret is missing.')]);

            return back();
        }

        $started = microtime(true);

        try {
            $client = $postgrest->as($actor);
            $whoami = $client->post('rpc/whoami');
            $collections = $client->get('knowledge_collections', ['select' => 'id,slug,name,documents', 'limit' => 10]);

            $result = [
                'ok' => $whoami->successful() && $collections->successful(),
                'status' => [$whoami->status(), $collections->status()],
                'whoami' => $whoami->json(),
                'collections' => $collections->successful() ? $collections->json() : [],
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => mb_substr($exception->getMessage(), 0, 300)];
        }

        audit('postgrest.tested', null, ['ok' => $result['ok']], $actor);
        Inertia::flash('postgrest', $result);

        return back();
    }
}
