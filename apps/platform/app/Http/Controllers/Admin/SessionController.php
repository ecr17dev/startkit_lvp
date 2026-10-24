<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\RevokeSessions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Paginated;
use App\Support\SessionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Active browser sessions of every user.
 */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $sessions = DB::table((string) config('session.table', 'sessions'))
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->whereNotNull('sessions.user_id')
            ->where('sessions.last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
            ->orderByDesc('sessions.last_activity')
            ->select(['sessions.id', 'sessions.user_id', 'sessions.ip_address', 'sessions.user_agent', 'sessions.last_activity', 'users.name', 'users.email'])
            ->paginate(25)
            ->withQueryString();

        $currentId = $request->session()->getId();

        return Inertia::render('admin/sessions/Index', [
            'sessions' => [
                'data' => $sessions->getCollection()->map(fn (\stdClass $session) => [
                    'id' => RevokeSessions::publicId((string) $session->id),
                    'user' => ['id' => (int) $session->user_id, 'name' => $session->name, 'email' => $session->email],
                    'ip_address' => $session->ip_address,
                    ...SessionList::describe((string) $session->user_agent),
                    'last_active_at' => Carbon::createFromTimestamp((int) $session->last_activity)->toIso8601String(),
                    'current' => hash_equals((string) $session->id, $currentId),
                ])->all(),
                'meta' => Paginated::meta($sessions),
            ],
            'canRevoke' => $request->user()?->can('users.update') ?? false,
        ]);
    }

    public function destroy(Request $request, User $user, string $session, RevokeSessions $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $user, $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return back();
    }
}
