<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\SetUserStatus;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * The same Actions and Policies as the administration panel.
 */
#[Group('Users', weight: 1)]
class UserController extends Controller
{
    /**
     * List users.
     *
     * Requires the `users.read` permission.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            /** Search by name or email. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Filter by role name. */
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,disabled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($query) => $query->where('name', $role)))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return UserResource::collection($users);
    }

    /**
     * Create a user.
     *
     * Requires `users.create`; only roles the caller can grant are accepted.
     * Users log in with one-time codes (passwords are only accepted when the
     * deployment enables them).
     */
    public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
    {
        $user = $action->handle($this->actor($request), $request->validated());

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    /**
     * Get a user.
     */
    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    /**
     * Update a user.
     *
     * Requires `users.update`. Sending `roles` replaces the roles of the user
     * (not allowed on your own account; the last owner keeps the owner role).
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): UserResource
    {
        $action->handle($this->actor($request), $user, $request->validated());

        return new UserResource($user->refresh()->load('roles'));
    }

    /**
     * Disable or enable a user.
     *
     * Requires `users.disable`. Disabling revokes the sessions and tokens.
     */
    public function status(Request $request, User $user, SetUserStatus $action): UserResource
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $action->handle($this->actor($request), $user, (bool) $validated['active']);

        return new UserResource($user->load('roles'));
    }

    /**
     * Delete a user.
     *
     * Requires `users.delete`. Personal data is anonymized.
     */
    public function destroy(Request $request, User $user, DeleteUser $action): JsonResponse
    {
        $action->handle($this->actor($request), $user);

        return response()->json(['message' => __('User deleted.')]);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
