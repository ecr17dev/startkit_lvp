<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

#[Group('Users', weight: 1)]
class UserController extends Controller
{
    /**
     * List users.
     *
     * Requires the `users.view` permission.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            /** Search by name or email. */
            'search' => ['nullable', 'string', 'max:100'],
            /** Filter by role name. */
            'role' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($validated['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return UserResource::collection($users);
    }

    /**
     * Create a user.
     *
     * Requires the `users.create` permission. When `password` is omitted the
     * user can log in with an email code.
     */
    public function store(UserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'] ?? Str::password(32),
            'is_active' => $validated['is_active'] ?? true,
            'locale' => $validated['locale'] ?? null,
        ]);

        if ($request->boolean('email_verified')) {
            $user->markEmailAsVerified();
        }

        $user->syncRoles($validated['roles'] ?? []);

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
     * Requires the `users.update` permission.
     */
    public function update(UserRequest $request, User $user): UserResource
    {
        Gate::authorize('update', $user);

        $validated = $request->validated();

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? $user->is_active,
            'locale' => $validated['locale'] ?? $user->locale,
        ]);

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        $user->save();

        if ($request->has('roles')) {
            $user->syncRoles($validated['roles'] ?? []);
        }

        return new UserResource($user->load('roles'));
    }

    /**
     * Delete a user.
     *
     * Requires the `users.delete` permission.
     */
    public function destroy(User $user): JsonResponse
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return response()->json(['message' => __('User deleted.')]);
    }
}
