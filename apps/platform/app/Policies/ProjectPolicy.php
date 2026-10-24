<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Permissions of the projects module (projects.*), registered by
 * App\Providers\Modules\ProjectsServiceProvider.
 */
class ProjectPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('projects.read');
    }

    public function view(User $actor, Project $project): bool
    {
        return $actor->can('projects.read');
    }

    public function create(User $actor): bool
    {
        return $actor->can('projects.create');
    }

    public function update(User $actor, Project $project): bool
    {
        return $actor->can('projects.update');
    }

    public function delete(User $actor, Project $project): bool
    {
        return $actor->can('projects.delete');
    }
}
