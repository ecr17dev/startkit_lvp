<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Permission matrix: every role against every permission of the catalog.
 */
class PermissionController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->can('permissions.read'), 403);

        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('id')->get();

        return Inertia::render('admin/permissions/Index', [
            'roles' => RoleResource::collection($roles)->resolve(),
            'permissionGroups' => PermissionCatalog::groups(),
            'grantable' => $actor->permissionNames(),
            'editable' => $roles->filter(fn (Role $role) => $actor->can('updatePermissions', $role))->pluck('name')->values(),
        ]);
    }

    /**
     * Save the permissions of one role.
     */
    public function update(Request $request, Role $role, UpdateRole $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $action->handle($actor, $role, ['permissions' => $request->input('permissions', [])], 'updatePermissions');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions of :role updated.', ['role' => $role->name])]);

        return back();
    }
}
