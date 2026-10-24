<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Actions\Ai\SaveModel;
use App\Actions\Ai\SetModelPrice;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AiPresenter;
use App\Support\AiUsageReport;
use App\Support\Paginated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Catalog\ModelVerifier;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\ModelStatus;
use Starter\Ai\Enums\PriceComponent;
use Starter\Ai\Enums\PriceUnit;
use Starter\Ai\Models\AiModel;
use Starter\Ai\Models\AiRequest;
use Starter\Ai\Models\Connection;
use Starter\Ai\Models\ModelPrice;

/**
 * Model catalog: search, manual import, capabilities, verification and prices.
 */
class ModelController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'integer'],
            'function' => ['nullable', 'string', Rule::in(SaveModel::FUNCTIONS)],
            'capability' => ['nullable', Rule::enum(Capability::class)],
            'status' => ['nullable', Rule::enum(ModelStatus::class)],
            'enabled' => ['nullable', 'in:yes,no'],
            'sort' => ['nullable', 'in:name,input_price,context'],
        ]);

        $models = AiModel::query()
            ->with(['connection', 'capabilities'])
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query->whereLike('model', "%{$search}%")->orWhereLike('label', "%{$search}%")))
            ->when($filters['connection'] ?? null, fn ($query, int|string $id) => $query->where('connection_id', (int) $id))
            ->when($filters['function'] ?? null, fn ($query, string $function) => $query->whereJsonContains('functions', $function))
            ->when($filters['capability'] ?? null, fn ($query, string $capability) => $query->whereHas('capabilities', fn ($query) => $query->where('capability', $capability)->where('status', 'supported')))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['enabled'] ?? null, fn ($query, string $enabled) => $query->where('is_enabled', $enabled === 'yes'))
            ->when(($filters['sort'] ?? 'name') === 'input_price', fn ($query) => $query->orderByRaw("(select amount from ai_model_prices p where p.model_id = ai_models.id and p.component = 'input' and p.tier = 'standard' and p.effective_to is null order by effective_from desc limit 1) asc nulls last"))
            ->when(($filters['sort'] ?? 'name') === 'context', fn ($query) => $query->orderByDesc('context_window'))
            ->orderByDesc('is_enabled')
            ->orderBy('model')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/ai/models/Index', [
            'models' => [
                'data' => $models->getCollection()->map(fn (AiModel $model) => AiPresenter::model($model))->values(),
                'meta' => Paginated::meta($models),
            ],
            'connections' => Connection::query()->orderBy('label')->get(['id', 'label', 'driver'])->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'label' => $connection->label,
                'discovery' => $connection->driver->supportsDiscovery(),
            ]),
            'functions' => SaveModel::FUNCTIONS,
            'capabilities' => array_map(fn (Capability $capability) => ['value' => $capability->value, 'label' => $capability->label()], Capability::cases()),
            'filters' => (object) $filters,
        ]);
    }

    /**
     * Import a model manually (with its source).
     */
    public function store(Request $request, SaveModel $action): RedirectResponse
    {
        $validated = $request->validate(['connection_id' => ['required', 'integer', Rule::exists('ai_provider_connections', 'id')]]);

        $model = $action->handle($this->actor($request), $request->except('connection_id'), connection: Connection::query()->findOrFail((int) $validated['connection_id']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model added. Verify it with a minimal call.')]);

        return to_route('admin.ai.models.show', $model);
    }

    public function show(AiModel $model): Response
    {
        $model->load(['connection', 'capabilities']);

        $requests = AiRequest::query()->with(['user', 'connection'])->where('model_id', $model->id)->latest()->limit(10)->get();

        return Inertia::render('admin/ai/models/Show', [
            'model' => AiPresenter::model($model),
            'priceHistory' => ModelPrice::query()->with('source')->where('model_id', $model->id)->latest('effective_from')->limit(100)->get()
                ->map(fn (ModelPrice $price) => AiPresenter::price($price))->values(),
            'recentRequests' => $requests->map(fn (AiRequest $request) => AiUsageReport::request($request))->values(),
            'functions' => SaveModel::FUNCTIONS,
            'capabilities' => array_map(fn (Capability $capability) => ['value' => $capability->value, 'label' => $capability->label()], Capability::cases()),
            'components' => array_map(fn (PriceComponent $component) => ['value' => $component->value, 'unit' => $component->defaultUnit()->value], PriceComponent::cases()),
            'units' => array_map(fn (PriceUnit $unit) => $unit->value, PriceUnit::cases()),
        ]);
    }

    public function update(Request $request, AiModel $model, SaveModel $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->except('model'), $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Model updated.')]);

        return back();
    }

    /**
     * Verify the model with a minimal explicit call (recorded in the ledger).
     */
    public function verify(Request $request, AiModel $model, ModelVerifier $verifier): RedirectResponse
    {
        abort_unless($this->actor($request)->can('ai.models.manage'), 403);

        $result = $verifier->verify($model->load('connection'), $this->actor($request));

        Inertia::flash('toast', $result['ok']
            ? ['type' => 'success', 'message' => __(':model answered in :ms ms.', ['model' => $model->model, 'ms' => $result['latency_ms']])]
            : ['type' => 'error', 'message' => (string) $result['message']]);

        return back();
    }

    public function price(Request $request, AiModel $model, SetModelPrice $action): RedirectResponse
    {
        $result = $action->handle($this->actor($request), $model, $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($result) {
            'unchanged' => __('The price did not change.'),
            'closed' => __('The price no longer applies. The history was kept.'),
            default => __('Price saved. Recorded costs keep their original price.'),
        }]);

        return back();
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
