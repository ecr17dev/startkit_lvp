<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\RevokeSessions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SessionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The browser sessions of the authenticated user.
 */
class SessionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Sessions', [
            'sessions' => SessionList::forUser($user->id, $request->session()->getId()),
            'tokens' => $user->tokens()->count(),
        ]);
    }

    public function destroy(Request $request, string $session, RevokeSessions $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->handle($user, $user, $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return back();
    }

    public function destroyOthers(Request $request, RevokeSessions $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $action->others($user, $user, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} There were no other sessions.|{1} One session was closed.|[2,*] :count sessions were closed.', $count)]);

        return back();
    }
}
