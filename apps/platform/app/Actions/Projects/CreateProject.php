<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Create a project. Shared by the panel, the API and the AI tools.
 */
class CreateProject
{
    /**
     * Validation rules, reused by the Form Request and UpdateProject.
     *
     * @return array<string, mixed>
     */
    public static function rules(?Project $record = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', Rule::unique('projects', 'code')->ignore($record?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(Project::STATUS_OPTIONS)],
            'starts_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'is_billable' => ['boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, array $input): Project
    {
        Gate::forUser($actor)->authorize('create', Project::class);

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, self::rules())->validate();

        $project = new Project($data);
        $project->created_by = $actor->id;
        $project->save();

        audit('projects.created', $project, ['name' => $project->name], $actor);

        return $project;
    }
}
