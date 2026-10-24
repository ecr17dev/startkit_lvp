<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Ai\ProviderDriver;
use App\Ai\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiProviderRequest;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

use function Laravel\Ai\agent;

class AiProviderController extends Controller
{
    public function index(): Response
    {
        $spend = AiUsageLog::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->selectRaw('provider, sum(cost) as cost, count(*) as calls')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        return Inertia::render('admin/ai/providers/Index', [
            'providers' => AiProvider::query()
                ->withCount('models')
                ->orderByDesc('is_default')
                ->orderBy('label')
                ->get()
                ->map(fn (AiProvider $provider) => [
                    ...$this->present($provider),
                    'models_count' => $provider->models_count,
                    'month_cost' => (float) ($spend[$provider->name]->cost ?? 0),
                    'month_calls' => (int) ($spend[$provider->name]->calls ?? 0),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/ai/providers/Create', [
            'drivers' => ProviderDriver::options(),
        ]);
    }

    public function store(AiProviderRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $driver = ProviderDriver::from($validated['driver']);

        $provider = DB::transaction(function () use ($validated, $driver, $request) {
            $provider = AiProvider::query()->create([
                ...Arr::only($validated, ['label', 'name', 'driver', 'api_key', 'base_url', 'default_model']),
                'options' => array_filter($validated['options'] ?? [], 'filled'),
                'is_active' => $validated['is_active'] ?? true,
                'is_default' => ($validated['is_default'] ?? false) || AiProvider::query()->doesntExist(),
            ]);

            if ($request->boolean('import_models')) {
                foreach ($driver->suggestedModels() as $model) {
                    $provider->models()->create($model);
                }
            }

            if ($provider->default_model === null && ($first = $driver->suggestedModels()[0] ?? null)) {
                $provider->update(['default_model' => $first['model']]);
            }

            $this->ensureSingleDefault($provider);

            return $provider;
        });

        Artisan::call('queue:restart');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider created.')]);

        return to_route('admin.ai.providers.edit', $provider);
    }

    public function edit(AiProvider $provider): Response
    {
        return Inertia::render('admin/ai/providers/Edit', [
            'provider' => $this->present($provider),
            'models' => $provider->models()->orderBy('model')->get()->map(fn (AiModel $model) => [
                ...$model->only(['id', 'model', 'label', 'input_price', 'output_price', 'cache_read_price', 'cache_write_price', 'context_window', 'is_active']),
                'is_priced' => $model->isPriced(),
            ]),
            'drivers' => ProviderDriver::options(),
            'suggestedModels' => $provider->driver->suggestedModels(),
        ]);
    }

    public function update(AiProviderRequest $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validated();

        $options = $provider->options ?? [];

        foreach ($validated['options'] ?? [] as $key => $value) {
            // Blank secrets keep the stored value...
            if (in_array($key, AiProvider::SECRET_OPTIONS, true) && blank($value)) {
                continue;
            }

            $options[$key] = $value;
        }

        $provider->fill([
            ...Arr::only($validated, ['label', 'name', 'driver', 'base_url', 'default_model']),
            'options' => array_filter($options, 'filled'),
            'is_active' => $validated['is_active'] ?? $provider->is_active,
            'is_default' => $validated['is_default'] ?? $provider->is_default,
        ]);

        if (filled($validated['api_key'] ?? null)) {
            $provider->api_key = $validated['api_key'];
        } elseif ($request->boolean('remove_api_key')) {
            $provider->api_key = null;
        }

        $provider->save();
        $this->ensureSingleDefault($provider);

        Artisan::call('queue:restart');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider updated.')]);

        return back();
    }

    public function destroy(AiProvider $provider): RedirectResponse
    {
        $provider->delete();

        if (! AiProvider::query()->where('is_default', true)->exists()) {
            AiProvider::query()->active()->oldest('id')->first()?->update(['is_default' => true]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Provider deleted.')]);

        return to_route('admin.ai.providers.index');
    }

    /**
     * Send a tiny prompt to check the credentials of the provider.
     */
    public function test(AiProvider $provider): RedirectResponse
    {
        ProviderRegistry::flush();

        if (! $provider->is_active) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Activate the provider before testing it.')]);

            return back();
        }

        $startedAt = microtime(true);

        try {
            $response = agent('You are a connectivity check. Reply with the single word OK.')
                ->prompt('Reply with OK.', provider: $provider->name, model: $provider->default_model, timeout: 30);
        } catch (Throwable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Connection failed: :error', ['error' => str($e->getMessage())->limit(300)])]);

            return back();
        }

        $milliseconds = (int) round((microtime(true) - $startedAt) * 1000);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connection OK (:model, :ms ms): :reply', [
            'model' => $response->meta->model ?? $provider->default_model,
            'ms' => $milliseconds,
            'reply' => str($response->text)->limit(60),
        ])]);

        return back();
    }

    /**
     * Get the provider data that is safe to send to the browser.
     *
     * @return array<string, mixed>
     */
    protected function present(AiProvider $provider): array
    {
        $options = $provider->options ?? [];

        return [
            ...$provider->only(['id', 'name', 'label', 'base_url', 'default_model', 'is_active', 'is_default']),
            'driver' => $provider->driver->value,
            'driver_label' => $provider->driver->label(),
            'api_key_masked' => $provider->maskedApiKey(),
            'options' => [
                ...Arr::except($options, AiProvider::SECRET_OPTIONS),
                ...collect(AiProvider::SECRET_OPTIONS)->mapWithKeys(fn (string $key) => ["has_{$key}" => filled($options[$key] ?? null)])->all(),
            ],
        ];
    }

    /**
     * Keep a single default provider.
     */
    protected function ensureSingleDefault(AiProvider $provider): void
    {
        if ($provider->is_default) {
            AiProvider::query()->whereKeyNot($provider->getKey())->where('is_default', true)->update(['is_default' => false]);
            ProviderRegistry::flush();
        }
    }
}
