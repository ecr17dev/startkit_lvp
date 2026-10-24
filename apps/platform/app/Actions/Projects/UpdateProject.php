<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Update a project (only the fields sent). The audit records what changed.
 */
class UpdateProject
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Project $project, array $input): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        $rules = array_map(fn (array $rules) => ['sometimes', ...$rules], CreateProject::rules($project));

        /** @var array<string, mixed> $data */
        $data = Validator::make($input, $rules)->validate();

        $project->fill($data);
        $changes = array_keys($project->getDirty());
        $project->save();

        if ($changes !== []) {
            audit('projects.updated', $project, ['fields' => $changes], $actor);
        }

        return $project;
    }
}
