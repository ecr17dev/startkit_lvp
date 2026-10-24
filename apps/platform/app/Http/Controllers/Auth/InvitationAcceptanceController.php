<?php

namespace App\Http\Controllers\Auth;

use App\Auth\PasswordlessAuthentication;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Identity\Identity;
use Starter\Identity\Models\Invitation;

/**
 * Accept an invitation: the invited person confirms the email with a
 * one-time code and the account is created with the invited role.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(protected PasswordlessAuthentication $auth) {}

    public function show(string $token): Response
    {
        $invitation = $this->find($token);

        return Inertia::render('auth/AcceptInvitation', [
            'token' => $token,
            'invitation' => $invitation ? [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'inviter' => $invitation->inviter?->getAttribute('name'),
                'expires_at' => $invitation->expires_at,
            ] : null,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);

        abort_if($invitation === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $challenge = $this->auth->requestInvitation($invitation, $validated['name'], $request);
        $this->auth->remember($request, $challenge, ['remember' => false]);

        return to_route('login.verify');
    }

    protected function find(string $token): ?Invitation
    {
        if (! Identity::registrationMode()->allowsInvitations()) {
            return null;
        }

        return Invitation::findPendingByToken($token);
    }
}
