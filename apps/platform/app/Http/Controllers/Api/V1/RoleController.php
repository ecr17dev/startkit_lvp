<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

#[Group('Roles and permissions', weight: 2)]
class RoleController extends Controller
{
    /**
     * List roles with their permissions.
     *
     * Requires the `roles.read` permission.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        return RoleResource::collection(Role::query()->with('permissions')->withCount('users')->orderBy('id')->get());
    }

    /**
     * Get a role.
     */
    public function show(Role $role): RoleResource
    {
        Gate::authorize('view', $role);

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    /**
     * Create a role.
     *
     * Requires `roles.manage`; you can only grant permissions you hold.
     */
    public function store(RoleRequest $request, CreateRole $action): JsonResponse
    {
        $role = $action->handle($this->actor($request), $request->validated());

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    /**
     * Update a role.
     *
     * Requires `roles.manage`. The owner role cannot be modified and system
     * roles cannot be renamed.
     */
    public function update(RoleRequest $request, Role $role, UpdateRole $action): RoleResource
    {
        return new RoleResource($action->handle($this->actor($request), $role, $request->validated()));
    }

    /**
     * Delete a role.
     *
     * Requires `roles.manage`. System roles cannot be deleted.
     */
    public function destroy(Request $request, Role $role, DeleteRole $action): JsonResponse
    {
        $action->handle($this->actor($request), $role);

        return response()->json(['message' => __('Role deleted.')]);
    }

    /**
     * List the permission catalog.
     *
     * Requires the `permissions.read` permission.
     *
     * @return array{data: list<array{group: string, permissions: list<array{name: string, description: string}>}>}
     */
    public function permissions(Request $request): array
    {
        abort_unless($this->actor($request)->can('permissions.read'), 403);

        return ['data' => PermissionCatalog::groups()];
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
