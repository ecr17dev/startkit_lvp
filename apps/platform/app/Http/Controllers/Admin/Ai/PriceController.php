<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Support\AiPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Ai\Jobs\SyncGatewayPrices;
use Starter\Ai\Models\ModelPrice;
use Starter\Ai\Models\PriceSource;
use Starter\Ai\Models\PriceSyncRun;

/**
 * Price sources, synchronization runs and the latest price changes.
 */
class PriceController extends Controller
{
    public function index(): Response
    {
        PriceSource::manual();
        PriceSource::openRouter();

        return Inertia::render('admin/ai/prices/Index', [
            'sources' => PriceSource::query()->withCount(['prices' => fn ($query) => $query->whereNull('effective_to')])->orderBy('id')->get()
                ->map(fn (PriceSource $source) => [
                    'id' => $source->id,
                    'key' => $source->key,
                    'name' => $source->name,
                    'kind' => $source->kind,
                    'url' => $source->url,
                    'current_prices' => $source->getAttribute('prices_count'),
                    'last_synced_at' => $source->last_synced_at?->toIso8601String(),
                    'syncable' => $source->key === PriceSource::OPENROUTER,
                ]),
            'runs' => PriceSyncRun::query()->with('source')->latest('started_at')->limit(20)->get()
                ->map(fn (PriceSyncRun $run) => [
                    'id' => $run->id,
                    'source' => $run->source->name,
                    'status' => $run->status,
                    'stats' => (object) ($run->stats ?? []),
                    'error' => $run->error,
                    'started_at' => $run->started_at->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                ]),
            'changes' => ModelPrice::query()->with(['source', 'model.connection'])->latest('effective_from')->limit(30)->get()
                ->map(fn (ModelPrice $price) => [
                    ...AiPresenter::price($price),
                    'model' => ['id' => $price->model->id, 'name' => $price->model->displayName(), 'connection' => $price->model->connection->label],
                ]),
        ]);
    }

    /**
     * Queue a synchronization of the gateway prices.
     */
    public function sync(Request $request): RedirectResponse
    {
        SyncGatewayPrices::dispatch($request->user()?->getAuthIdentifier());

        audit('ai.prices.sync_requested', null, ['source' => PriceSource::OPENROUTER]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Price synchronization queued.')]);

        return back();
    }
}
