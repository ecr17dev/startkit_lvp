<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Actions\Ai\SaveModel;
use App\Actions\Ai\SetModelPrice;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiPresenter;
use App\Support\Paginated;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Starter\Ai\Catalog\ModelVerifier;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\ModelPrice;

/**
 * Model catalog and prices. Requires `ai.models.manage`.
 */
#[Group('AI connections and models', weight: 4)]
class ModelController extends Controller
{
    /**
     * Search the catalog by name, function, capability or connection.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'connection_id' => ['nullable', 'integer'],
            /** text, embeddings, image, audio, transcription or reranking. */
            'function' => ['nullable', 'string', Rule::in(SaveModel::FUNCTIONS)],
            /** Only models whose capability is verified or declared as supported. */
            'capability' => ['nullable', Rule::enum(Capability::class)],
            'enabled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $models = AiModel::query()
            ->with(['connection', 'capabilities'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query->whereLike('model', "%{$search}%")->orWhereLike('label', "%{$search}%")))
            ->when($filters['connection_id'] ?? null, fn ($query, int|string $id) => $query->where('connection_id', (int) $id))
            ->when($filters['function'] ?? null, fn ($query, string $function) => $query->whereJsonContains('functions', $function))
            ->when($filters['capability'] ?? null, fn ($query, string $capability) => $query->whereHas('capabilities', fn ($query) => $query->where('capability', $capability)->where('status', 'supported')))
            ->when(array_key_exists('enabled', $filters) && $filters['enabled'] !== null, fn ($query) => $query->where('is_enabled', (bool) $filters['enabled']))
            ->orderBy('model')
            ->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $models->getCollection()->map(fn (AiModel $model) => AiPresenter::model($model))->values(),
            'meta' => Paginated::meta($models),
        ]);
    }

    /**
     * Import a model manually.
     */
    public function store(Request $request, SaveModel $action): JsonResponse
    {
        $validated = $request->validate(['connection_id' => ['required', 'integer', Rule::exists('ai_provider_connections', 'id')]]);
        $request->validate(SaveModel::rules(null, (int) $validated['connection_id']));

        $model = $action->handle($this->actor($request), $request->except('connection_id'), connection: Connection::query()->findOrFail((int) $validated['connection_id']));

        return response()->json(['data' => AiPresenter::model($model->load(['connection', 'capabilities']))], 201);
    }

    /**
     * Get a model with its capabilities and current prices.
     */
    public function show(Request $request, AiModel $model): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['data' => AiPresenter::model($model->load(['connection', 'capabilities']))]);
    }

    /**
     * Update a model (enable, default, functions, limits, capabilities).
     */
    public function update(Request $request, AiModel $model, SaveModel $action): JsonResponse
    {
        $request->validate(SaveModel::rules($model));

        return response()->json(['data' => AiPresenter::model($action->handle($this->actor($request), $request->all(), $model)->load(['connection', 'capabilities']))]);
    }

    /**
     * Verify a model with a minimal explicit call (recorded in the ledger).
     *
     * @return array{ok: bool, error_type: string|null, message: string|null, latency_ms: int|null}
     */
    public function verify(Request $request, AiModel $model, ModelVerifier $verifier): array
    {
        $this->authorizeManage($request);

        return $verifier->verify($model->load('connection'), $this->actor($request));
    }

    /**
     * Price history of a model (newest first).
     */
    public function prices(Request $request, AiModel $model): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => ModelPrice::query()->with('source')->where('model_id', $model->id)->latest('effective_from')->limit(200)->get()
                ->map(fn (ModelPrice $price) => AiPresenter::price($price))->values(),
        ]);
    }

    /**
     * Set a manual price. A new version is created; recorded costs keep their price.
     *
     * @return array{result: string}
     */
    public function setPrice(Request $request, AiModel $model, SetModelPrice $action): array
    {
        $request->validate(SetModelPrice::rules());

        return ['result' => $action->handle($this->actor($request), $model, $request->all())];
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
