<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use App\Support\Paginated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Administration\Models\AuditEvent;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('audit.read');

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
            'channel' => ['nullable', 'in:web,api,cli,chat,system'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $events = AuditEvent::query()
            ->with('actor')
            ->when($filters['action'] ?? null, fn ($query, string $action) => $query->whereLike('action', $action.'%'))
            ->when($filters['actor'] ?? null, fn ($query, int|string $actor) => $query->where('actor_id', (int) $actor))
            ->when($filters['channel'] ?? null, fn ($query, string $channel) => $query->where('channel', $channel))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/audit/Index', [
            'events' => [
                'data' => collect($events->items())->map(fn (AuditEvent $event) => AuditLog::present($event))->all(),
                'meta' => Paginated::meta($events),
            ],
            'actions' => AuditEvent::query()->distinct()->orderBy('action')->limit(200)->pluck('action'),
            'filters' => (object) $filters,
        ]);
    }
}
