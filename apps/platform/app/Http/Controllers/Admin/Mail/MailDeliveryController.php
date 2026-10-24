<?php

namespace App\Http\Controllers\Admin\Mail;

use App\Actions\Mail\RetryMailDelivery;
use App\Http\Controllers\Controller;
use App\Models\MailAutomation;
use App\Models\MailDelivery;
use App\Models\User;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MailDeliveryController extends Controller
{
    protected const SORTABLE = ['created_at', 'email', 'status', 'send_at', 'sent_at'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:all,pending,sent,failed,skipped'],
            'automation' => ['nullable', 'string', 'max:20'],
            ...TableSort::rules(self::SORTABLE),
        ]);

        $query = MailDelivery::query()
            ->with('automation:id,name')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->whereLike('email', "%{$search}%")
                ->orWhereLike('subject', "%{$search}%")))
            ->when(($filters['status'] ?? 'all') !== 'all' ? $filters['status'] : null, fn ($query, string $status) => $query->where('status', $status))
            ->when(ctype_digit((string) ($filters['automation'] ?? '')) ? (int) $filters['automation'] : null, fn ($query, int $automation) => $query->where('automation_id', $automation));

        $deliveries = TableSort::apply($query, $filters, self::SORTABLE, default: 'created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (MailDelivery $delivery) => [
                'id' => $delivery->id,
                'email' => $delivery->email,
                'subject' => $delivery->subject,
                'template_key' => $delivery->template_key,
                'automation' => $delivery->automation?->name,
                'status' => $delivery->status,
                'error' => $delivery->error,
                'send_at' => $delivery->send_at?->toIso8601String(),
                'sent_at' => $delivery->sent_at?->toIso8601String(),
                'created_at' => $delivery->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/mail/Deliveries', [
            'deliveries' => $deliveries,
            'automations' => MailAutomation::query()->orderBy('name')->get(['id', 'name']),
            'filters' => (object) $filters,
        ]);
    }

    public function retry(Request $request, MailDelivery $delivery, RetryMailDelivery $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Send queued again.')]);

        return back();
    }
}
