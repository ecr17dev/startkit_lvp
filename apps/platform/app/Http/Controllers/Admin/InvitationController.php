<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Http\Resources\InvitationResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Starter\Identity\Authorization\Delegation;
use Starter\Identity\Identity;
use Starter\Identity\Models\Invitation;

class InvitationController extends Controller
{
    public function index(Request $request, Delegation $delegation): Response
    {
        Gate::authorize('viewAny', Invitation::class);

        /** @var User $actor */
        $actor = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,accepted,revoked,expired'],
        ]);

        $invitations = Invitation::query()
            ->with('inviter')
            ->when($filters['status'] ?? null, fn ($query, string $status) => match ($status) {
                'pending' => $query->pending(),
                'accepted' => $query->whereNotNull('accepted_at'),
                'revoked' => $query->whereNotNull('revoked_at'),
                default => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
            })
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/invitations/Index', [
            'invitations' => InvitationResource::collection($invitations),
            'roles' => $delegation->grantableRoles($actor)->pluck('name')->values(),
            'filters' => (object) $filters,
            'registrationMode' => Identity::registrationMode()->value,
            'canInvite' => $actor->can('create', Invitation::class) && Identity::registrationMode()->allowsInvitations(),
        ]);
    }

    public function store(InviteUserRequest $request, InviteUser $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $action->handle($actor, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $invitation->email])]);

        return back();
    }

    public function resend(Request $request, Invitation $invitation, ResendInvitation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent again.')]);

        return back();
    }

    public function destroy(Request $request, Invitation $invitation, RevokeInvitation $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return back();
    }
}
