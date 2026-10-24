<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

#[Group('Projects', weight: 20)]
class ProjectController extends Controller
{
    /**
     * List projects.
     *
     * Requires the `projects.read` permission (token ability `read`).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $filters = $request->validate([
            /** Search by name, code. */
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Project::STATUS_OPTIONS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ProjectResource::collection(Project::query()
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%")
            ))
            ->when($filters['status'] ?? null, fn ($query, string $value) => $query->where('status', $value))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString());
    }

    /**
     * Get a project.
     *
     * Requires the `projects.read` permission.
     */
    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->load('creator:id,name'));
    }

    /**
     * Create a project.
     *
     * Requires `projects.create` (token ability `write`). The change is audited.
     */
    public function store(ProjectRequest $request, CreateProject $action): JsonResponse
    {
        $project = $action->handle($this->actor($request), $request->validated());

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    /**
     * Update a project.
     *
     * Requires `projects.update`. Only the fields sent change.
     */
    public function update(ProjectRequest $request, Project $project, UpdateProject $action): ProjectResource
    {
        return new ProjectResource($action->handle($this->actor($request), $project, $request->validated()));
    }

    /**
     * Delete a project.
     *
     * Requires `projects.delete`.
     */
    public function destroy(Request $request, Project $project, DeleteProject $action): JsonResponse
    {
        $action->handle($this->actor($request), $project);

        return response()->json(['message' => __('Project deleted.')]);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
