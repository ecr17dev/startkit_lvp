<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeleteUser;
use App\Actions\Users\RevokeSessions;
use App\Actions\Users\SetUserStatus;
use App\Actions\Users\UpdateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\SessionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Identity\Authorization\Delegation;
use Starter\Identity\Identity;

class UserController extends Controller
{
    public function __construct(protected Delegation $delegation) {}

    /**
     * Display the users.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,disabled'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($query) => $query->where('name', $role)))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('is_active', $status === 'active'))
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/users/Index', [
            'users' => UserResource::collection($users),
            'roles' => Role::query()->orderBy('id')->pluck('name'),
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
            'roles' => $this->grantableRoles($request),
            'defaultRole' => settings('auth.default_role'),
            'passwordsEnabled' => Identity::passwordsEnabled(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request, CreateUser $action): RedirectResponse
    {
        $user = $action->handle($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.edit', $user);
    }

    /**
     * Show the user detail: profile, roles, sessions and activity.
     */
    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        $actor = $this->actor($request);
        $canManageSessions = $actor->can('manageSessions', $user);

        return Inertia::render('admin/users/Edit', [
            'user' => (new UserResource($user->load('roles')))->resolve(),
            'roles' => $this->grantableRoles($request),
            'permissions' => $user->permissionNames(),
            'sessions' => $canManageSessions ? SessionList::forUser($user->id) : [],
            'tokens' => $canManageSessions ? $user->tokens()->count() : 0,
            'activity' => $actor->can('audit.read') ? AuditLog::forSubject($user, 15) : null,
            'passwordsEnabled' => Identity::passwordsEnabled(),
            'isLastOwner' => $this->delegation->isLastOwner($user),
            'can' => [
                'update' => $actor->can('update', $user),
                'assignRoles' => $actor->can('assignRoles', $user),
                'disable' => $actor->can('disable', $user),
                'delete' => $actor->can('delete', $user),
                'manageSessions' => $canManageSessions,
            ],
        ]);
    }

    /**
     * Update the given user.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUser $action): RedirectResponse
    {
        $input = $request->only(['name', 'email', 'locale', 'email_verified', 'password']);

        if ($request->has('roles')) {
            $input['roles'] = $request->input('roles', []);
        }

        $action->handle($this->actor($request), $user, array_filter($input, fn ($value, $key) => $key !== 'password' || filled($value), ARRAY_FILTER_USE_BOTH));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return back();
    }

    /**
     * Disable or enable the user.
     */
    public function status(Request $request, User $user, SetUserStatus $action): RedirectResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $action->handle($this->actor($request), $user, (bool) $validated['active']);

        Inertia::flash('toast', ['type' => 'success', 'message' => $validated['active'] ? __('User enabled.') : __('User disabled. Their sessions and tokens were revoked.')]);

        return back();
    }

    /**
     * Delete (anonymize) the given user.
     */
    public function destroy(Request $request, User $user, DeleteUser $action): RedirectResponse
    {
        $action->handle($this->actor($request), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }

    /**
     * Revoke one session of the user.
     */
    public function destroySession(Request $request, User $user, string $session, RevokeSessions $action): RedirectResponse
    {
        $action->handle($this->actor($request), $user, $session);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return back();
    }

    /**
     * Revoke every session of the user.
     */
    public function destroySessions(Request $request, User $user, RevokeSessions $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $action->others($actor, $user, $actor->is($user) ? $request->session()->getId() : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sessions revoked.')]);

        return back();
    }

    /**
     * @return list<string>
     */
    protected function grantableRoles(Request $request): array
    {
        return array_values($this->delegation->grantableRoles($this->actor($request))->pluck('name')->all());
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
