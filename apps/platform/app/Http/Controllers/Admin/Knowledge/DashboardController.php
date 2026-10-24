<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Http\Controllers\Controller;
use App\Support\KnowledgeAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Support\Access;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('rag.analytics.read');
        $user = $this->actor($request);

        $data = $request->validate([
            'days' => ['nullable', 'integer', 'in:7,30,90'],
            'collection' => ['nullable', 'integer'],
        ]);

        $visible = Collection::query()->orderBy('name')->get()->filter(fn (Collection $collection) => Access::canSeeCollection($user, $collection));
        $ids = array_values(array_map('intval', $visible->pluck('id')->all()));

        if (isset($data['collection'])) {
            $ids = array_values(array_intersect($ids, [(int) $data['collection']]));
        }

        return Inertia::render('admin/knowledge/Dashboard', [
            'filters' => ['days' => (int) ($data['days'] ?? 30), 'collection' => $data['collection'] ?? null],
            'collections' => $visible->map(fn (Collection $collection) => ['id' => $collection->id, 'name' => $collection->name])->values(),
            'report' => KnowledgeAnalytics::report($ids === [] ? [0] : $ids, (int) ($data['days'] ?? 30)),
        ]);
    }
}
