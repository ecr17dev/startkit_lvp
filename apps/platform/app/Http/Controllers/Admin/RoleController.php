<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Starter\Identity\Permissions;

class RoleController extends Controller
{
    /**
     * Display the roles.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('id')->get();

        return Inertia::render('admin/roles/Index', [
            'roles' => RoleResource::collection($roles)->resolve(),
            'totalPermissions' => count(Permissions::all()),
            'can' => [
                'create' => $request->user()?->can('create', Role::class) ?? false,
                'viewMatrix' => $request->user()?->can('permissions.read') ?? false,
            ],
        ]);
    }

    /**
     * Show the form to create a role.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('admin/roles/Create', [
            'permissionGroups' => PermissionCatalog::groups(),
            'grantable' => $this->actor($request)->permissionNames(),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(RoleRequest $request, CreateRole $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->all());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('admin.roles.index');
    }

    /**
     * Show the form to edit a role.
     */
    public function edit(Request $request, Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('admin/roles/Edit', [
            'role' => (new RoleResource($role->load('permissions')->loadCount('users')))->resolve(),
            'permissionGroups' => PermissionCatalog::groups(),
            'grantable' => $this->actor($request)->permissionNames(),
            'canDelete' => $request->user()?->can('delete', $role) ?? false,
        ]);
    }

    /**
     * Update the given role.
     */
    public function update(RoleRequest $request, Role $role, UpdateRole $action): RedirectResponse
    {
        $action->handle($this->actor($request), $role, $request->only(['name', 'permissions']) + ['permissions' => []]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('admin.roles.index');
    }

    /**
     * Delete the given role.
     */
    public function destroy(Request $request, Role $role, DeleteRole $action): RedirectResponse
    {
        $action->handle($this->actor($request), $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('admin.roles.index');
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
