<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display the users.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'filters' => (object) $filters,
        ]);
    }

    /**
     * Show the form to create a user.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/Create', [
            'roles' => $this->assignableRoles($request),
            'defaultRole' => settings('auth.default_role'),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // Users without password can log in with an email code...
            'password' => $validated['password'] ?? Str::password(32),
            'is_active' => $validated['is_active'] ?? true,
            'locale' => $validated['locale'] ?? null,
        ]);

        if ($request->boolean('email_verified')) {
            $user->markEmailAsVerified();
        }

        $user->syncRoles($validated['roles'] ?? []);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.index');
    }

    /**
     * Show the form to edit a user.
     */
    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('admin/users/Edit', [
            'user' => (new UserResource($user->load('roles')))->resolve(),
            'roles' => $this->assignableRoles($request),
            'canDelete' => $request->user()?->can('delete', $user) ?? false,
        ]);
    }

    /**
     * Update the given user.
     */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $validated = $request->validated();
        $isSelf = $request->user()?->is($user);

        if ($isSelf && ! ($validated['is_active'] ?? true)) {
            throw ValidationException::withMessages(['is_active' => __('You cannot deactivate your own account.')]);
        }

        $roles = $validated['roles'] ?? [];

        if ($user->isSuperAdmin() && ! in_array(Permissions::SUPER_ADMIN, $roles, true) && $this->isLastSuperAdmin($user)) {
            throw ValidationException::withMessages(['roles' => __('At least one super administrator is required.')]);
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_active' => $validated['is_active'] ?? $user->is_active,
            'locale' => $validated['locale'] ?? null,
        ]);

        if (filled($validated['password'] ?? null)) {
            $user->password = $validated['password'];
        }

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($request->boolean('email_verified') && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $user->syncRoles($roles);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('admin.users.index');
    }

    /**
     * Delete the given user.
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        if ($user->isSuperAdmin() && $this->isLastSuperAdmin($user)) {
            throw ValidationException::withMessages(['user' => __('At least one super administrator is required.')]);
        }

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }

    /**
     * Get the roles the current user can assign.
     *
     * @return list<string>
     */
    protected function assignableRoles(Request $request): array
    {
        return array_values(Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->reject(fn (string $role) => $role === Permissions::SUPER_ADMIN && ! $request->user()?->isSuperAdmin())
            ->all());
    }

    protected function isLastSuperAdmin(User $user): bool
    {
        return User::role(Permissions::SUPER_ADMIN)->whereKeyNot($user->getKey())->doesntExist();
    }
}
