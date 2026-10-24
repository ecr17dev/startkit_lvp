<?php

namespace App\Ai\Tools;

use App\Actions\Projects\DeleteProject;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class ProjectsDelete extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Delete a project found with projects_list. Requires the user\'s confirmation.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id of the project.')->required(),
        ];
    }

    protected function rules(): array
    {
        return ['id' => ['required', 'integer']];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $project = $this->find((int) $arguments['id']);
        Gate::forUser($this->actor())->authorize('delete', $project);

        return new ProposalPreview(
            title: __('Delete the project :name', ['name' => (string) $project->name]),
            resourceType: 'projects',
            resourceId: (string) $project->id,
            resourceLabel: (string) $project->name,
            resourceVersion: sha1($project->updated_at->toIso8601String()),
            changes: [['field' => 'name', 'before' => $project->name, 'after' => null]],
            effects: [__('This cannot be undone.')],
        );
    }

    protected function execute(array $arguments): array
    {
        $project = $this->find((int) $arguments['id']);
        app(DeleteProject::class)->handle($this->actor(), $project);

        return ['message' => 'Project deleted.'];
    }

    protected function find(int $id): Project
    {
        return Project::query()->find($id) ?? throw ValidationException::withMessages(['id' => "Project {$id} not found."]);
    }
}
