<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

#[Group('Roles', weight: 2)]
class RoleController extends Controller
{
    /**
     * List roles with their permissions.
     *
     * Requires the `roles.view` permission.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Role::class);

        return RoleResource::collection(Role::query()->with('permissions')->withCount('users')->orderBy('id')->get());
    }
}
