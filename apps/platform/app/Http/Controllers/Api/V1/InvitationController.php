<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\InviteUser;
use App\Actions\Invitations\ResendInvitation;
use App\Actions\Invitations\RevokeInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Http\Resources\InvitationResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Starter\Identity\Models\Invitation;

#[Group('Invitations', weight: 3)]
class InvitationController extends Controller
{
    /**
     * List invitations.
     *
     * Requires `users.invite` or `users.read`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invitation::class);

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return InvitationResource::collection(Invitation::query()->with('inviter')->latest('id')->paginate($validated['per_page'] ?? 15));
    }

    /**
     * Invite a user.
     *
     * Requires `users.invite`; the role must be one you can grant. The link
     * is emailed and expires after STARTER_INVITATION_TTL_DAYS days.
     */
    public function store(InviteUserRequest $request, InviteUser $action): JsonResponse
    {
        $invitation = $action->handle($this->actor($request), $request->validated());

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    /**
     * Resend an invitation with a new link.
     */
    public function resend(Request $request, Invitation $invitation, ResendInvitation $action): InvitationResource
    {
        return new InvitationResource($action->handle($this->actor($request), $invitation));
    }

    /**
     * Revoke an invitation.
     */
    public function destroy(Request $request, Invitation $invitation, RevokeInvitation $action): InvitationResource
    {
        return new InvitationResource($action->handle($this->actor($request), $invitation));
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
