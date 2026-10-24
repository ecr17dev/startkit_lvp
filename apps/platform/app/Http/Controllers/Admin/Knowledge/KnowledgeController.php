<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Actions\Knowledge\DeleteKnowledge;
use App\Actions\Knowledge\SaveEmbeddingProfile;
use App\Http\Controllers\Controller;
use App\Support\KnowledgePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Enums\Capability;
use Starter\Knowledge\Enums\ConnectionKind;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Exceptions\RagUnavailableException;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiClient;
use Starter\Knowledge\Store\ExternalApi\RetrievalApiException;
use Starter\Knowledge\Support\Access;
use Starter\Knowledge\Support\Principals;

/**
 * Knowledge overview: activation and diagnostics, embedding profiles,
 * collections and recent jobs.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request, Knowledge $knowledge): Response
    {
        $user = $this->actor($request);
        Gate::authorize('rag.collections.read');

        $collections = Collection::query()->with(['profile', 'activeVersion.profile'])->orderBy('name')->get()
            ->filter(fn (Collection $collection) => Access::canSeeCollection($user, $collection))
            ->map(fn (Collection $collection) => KnowledgePresenter::collection($collection))
            ->values();

        return Inertia::render('admin/knowledge/Index', [
            'status' => KnowledgePresenter::status(),
            'internal' => KnowledgePresenter::connection($knowledge->internalConnection()),
            'modes' => array_map(fn (RagMode $mode) => ['value' => $mode->value, 'label' => __($mode->label())], RagMode::cases()),
            'connections' => RagConnection::query()->where('kind', '!=', 'internal')->orderBy('label')->get()->map(fn (RagConnection $connection) => KnowledgePresenter::connection($connection)),
            'profiles' => EmbeddingProfile::query()->orderBy('name')->get()->map(fn (EmbeddingProfile $profile) => KnowledgePresenter::profile($profile)),
            'embeddingModels' => KnowledgePresenter::embeddingModels(),
            'collections' => $collections,
            'jobs' => Access::canManage($user) || Gate::allows('rag.jobs.manage')
                ? KnowledgePresenter::jobs(RagJob::query()->whereNull('parent_id'), 15)
                : [],
            'options' => KnowledgePresenter::options(),
            'principals' => Principals::for($user),
            'remote' => fn () => $this->remote($knowledge),
        ]);
    }

    /**
     * Collections a retrieval API offers, when it is the active connection.
     *
     * @return array{isolated: bool, collections: list<array{id: string, name: string, description: string|null, documents: int|null}>|null, error: string|null}|null
     */
    protected function remote(Knowledge $knowledge): ?array
    {
        $connection = $knowledge->available() ? $knowledge->connection() : null;

        if ($connection === null || $connection->kind !== ConnectionKind::Api) {
            return null;
        }

        try {
            $collections = $connection->can(Capability::ListCollections) ? app(RetrievalApiClient::class)->collections($connection) : null;
            $error = null;
        } catch (RetrievalApiException $exception) {
            [$collections, $error] = [null, $exception->getMessage()];
        }

        return ['isolated' => (bool) $connection->setting('enforces_acl', false), 'collections' => $collections, 'error' => $error];
    }

    public function diagnose(Request $request, Knowledge $knowledge): RedirectResponse
    {
        Gate::authorize('settings.update');

        $connection = $knowledge->connection() ?? $knowledge->internalConnection();
        $report = $knowledge->diagnose($connection);

        Inertia::flash('toast', $report->passed()
            ? ['type' => 'success', 'message' => __('Diagnostics passed.')]
            : ['type' => 'error', 'message' => __('Diagnostics found problems.')]);

        return back();
    }

    public function activate(Request $request, Knowledge $knowledge): RedirectResponse
    {
        Gate::authorize('settings.update');

        $data = $request->validate([
            /** internal, external_pgsql or external_api. */
            'mode' => ['required', Rule::in([RagMode::Internal->value, RagMode::ExternalPostgres->value, RagMode::ExternalApi->value])],
            'connection_id' => ['nullable', 'integer', 'exists:rag_connections,id'],
            /** Try CREATE EXTENSION vector (requires a privileged role). */
            'create_extension' => ['boolean'],
        ]);

        try {
            $report = $knowledge->activate(RagMode::from($data['mode']), $data['connection_id'] ?? null, (bool) ($data['create_extension'] ?? false), $this->actor($request));
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['mode' => $exception->getMessage()]);
        }

        Inertia::flash('toast', $report->passed()
            ? ['type' => 'success', 'message' => __('Knowledge (RAG) is active.')]
            : ['type' => 'error', 'message' => __('RAG was not activated: review the diagnostics.')]);

        return back();
    }

    public function deactivate(Request $request, Knowledge $knowledge): RedirectResponse
    {
        Gate::authorize('settings.update');

        $knowledge->deactivate($this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Knowledge (RAG) disabled. Documents and tables were kept.')]);

        return back();
    }

    public function storeProfile(Request $request, SaveEmbeddingProfile $action): RedirectResponse
    {
        $profile = $this->guard(fn () => $action->handle($this->actor($request), $request->all()));

        Inertia::flash('toast', $profile->isReady()
            ? ['type' => 'success', 'message' => __('Embedding profile created.')]
            : ['type' => 'error', 'message' => __('The profile could not be provisioned: :error', ['error' => (string) $profile->error])]);

        return back();
    }

    public function updateProfile(Request $request, EmbeddingProfile $profile, SaveEmbeddingProfile $action): RedirectResponse
    {
        $this->guard(fn () => $action->handle($this->actor($request), $request->all(), $profile));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Embedding profile saved.')]);

        return back();
    }

    public function destroyProfile(Request $request, EmbeddingProfile $profile, DeleteKnowledge $action): RedirectResponse
    {
        $action->profile($this->actor($request), $profile);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Embedding profile deleted.')]);

        return back();
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function guard(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (RagUnavailableException $exception) {
            throw ValidationException::withMessages(['rag' => $exception->getMessage()]);
        }
    }
}
