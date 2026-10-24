<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Http\Requests\ProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects panel (Inertia). Every change goes through the module
 * Actions: Policies, validation and audit are the same as in the API and
 * the AI tools.
 */
class ProjectController extends Controller
{
    /**
     * Columns the index can be sorted by (?sort=&direction=).
     */
    protected const SORTABLE = ['name', 'code', 'status', 'starts_on', 'due_on', 'updated_at'];

    /**
     * Display the projects.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Project::STATUS_OPTIONS)],
            ...TableSort::rules(self::SORTABLE),
        ]);

        $query = Project::query()
            ->with('creator:id,name')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%")
            ))
            ->when($filters['status'] ?? null, fn ($query, string $value) => $query->where('status', $value));

        $projects = TableSort::apply($query, $filters, self::SORTABLE)
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('projects/Index', [
            'projects' => ProjectResource::collection($projects),
            'filters' => (object) $filters,
            'options' => $this->options(),
            'can' => [
                'create' => $request->user()?->can('create', Project::class) ?? false,
                'update' => $request->user()?->can('projects.update') ?? false,
                'delete' => $request->user()?->can('projects.delete') ?? false,
            ],
        ]);
    }

    /**
     * Show the form to create a project.
     */
    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('projects/Create', ['options' => $this->options()]);
    }

    /**
     * Store a newly created project.
     */
    public function store(ProjectRequest $request, CreateProject $action): RedirectResponse
    {
        $action->handle($this->actor($request), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.index');
    }

    /**
     * Show the form to edit a project.
     */
    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('projects/Edit', [
            'project' => (new ProjectResource($project->load('creator:id,name')))->resolve(),
            'options' => $this->options(),
            'canDelete' => $request->user()?->can('delete', $project) ?? false,
        ]);
    }

    /**
     * Update the given project.
     */
    public function update(ProjectRequest $request, Project $project, UpdateProject $action): RedirectResponse
    {
        $action->handle($this->actor($request), $project, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.index');
    }

    /**
     * Delete the given project.
     */
    public function destroy(Request $request, Project $project, DeleteProject $action): RedirectResponse
    {
        $action->handle($this->actor($request), $project);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('projects.index');
    }

    /**
     * Values of the choice fields with their translated labels.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    protected function options(): array
    {
        $options = fn (array $values) => array_values(array_map(fn (string $value) => ['value' => $value, 'label' => (string) __(Str::headline($value))], $values));

        return [
            'status' => $options(Project::STATUS_OPTIONS),
        ];
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
