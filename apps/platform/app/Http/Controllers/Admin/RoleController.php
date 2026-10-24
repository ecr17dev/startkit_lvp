<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
            'protectedRoles' => Permissions::protectedRoles(),
            'superAdminRole' => Permissions::SUPER_ADMIN,
            'totalPermissions' => count(Permissions::all()),
        ]);
    }

    /**
     * Show the form to create a role.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('admin/roles/Create', [
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(RoleRequest $request): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $this->ensurePermissionsExist();

        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->validated('permissions', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('admin.roles.index');
    }

    /**
     * Show the form to edit a role.
     */
    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        return Inertia::render('admin/roles/Edit', [
            'role' => (new RoleResource($role->load('permissions')))->resolve(),
            'permissionGroups' => $this->permissionGroups(),
            'isProtected' => in_array($role->name, Permissions::protectedRoles(), true),
        ]);
    }

    /**
     * Update the given role.
     */
    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        Gate::authorize('update', $role);

        $name = $request->validated('name');

        if ($name !== $role->name && in_array($role->name, Permissions::protectedRoles(), true)) {
            throw ValidationException::withMessages(['name' => __('System roles cannot be renamed.')]);
        }

        $this->ensurePermissionsExist();

        $role->update(['name' => $name]);
        $role->syncPermissions($request->validated('permissions', []));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('admin.roles.index');
    }

    /**
     * Delete the given role.
     */
    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $role->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('admin.roles.index');
    }

    /**
     * Get the permissions grouped by module for the form.
     *
     * @return list<array{group: string, permissions: list<array{name: string, description: string}>}>
     */
    protected function permissionGroups(): array
    {
        $groups = [];

        foreach (Permissions::grouped() as $group => $permissions) {
            $items = [];

            foreach ($permissions as $name => $description) {
                $translated = __($description);
                $items[] = ['name' => $name, 'description' => is_string($translated) ? $translated : $description];
            }

            $groups[] = ['group' => $group, 'permissions' => $items];
        }

        return $groups;
    }

    /**
     * Create the catalog permissions missing in the database (e.g. new ones added in code).
     */
    protected function ensurePermissionsExist(): void
    {
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
