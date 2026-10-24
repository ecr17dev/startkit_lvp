<?php

namespace App\Ai\Tools;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\UpdateProject;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class ProjectsUpdate extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Change fields of a project found with projects_list. Only the fields sent change.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id of the project.')->required(),
            'name' => $schema->string()->description('Name.'),
            'code' => $schema->string()->description('Code.'),
            'description' => $schema->string()->description('Description.'),
            'status' => $schema->string()->enum(['planned', 'active', 'on_hold', 'closed'])->description('Status.'),
            'starts_on' => $schema->string()->description('Start date (YYYY-MM-DD).'),
            'due_on' => $schema->string()->description('Due date (YYYY-MM-DD).'),
            'budget' => $schema->number()->description('Budget.'),
            'is_billable' => $schema->boolean()->description('Billable.'),
        ];
    }

    protected function rules(): array
    {
        return ['id' => ['required', 'integer'], ...array_map(fn (array $rules) => ['sometimes', ...$rules], CreateProject::rules())];
    }

    protected function preview(array $arguments): ProposalPreview
    {
        $project = $this->find((int) $arguments['id']);
        Gate::forUser($this->actor())->authorize('update', $project);
        $changes = [];

        foreach (array_diff_key($arguments, ['id' => true]) as $field => $value) {
            $changes[] = ['field' => $field, 'before' => $project->getAttribute($field), 'after' => $value];
        }

        return new ProposalPreview(
            title: __('Update the project :name', ['name' => (string) $project->name]),
            resourceType: 'projects',
            resourceId: (string) $project->id,
            resourceLabel: (string) $project->name,
            resourceVersion: sha1($project->updated_at->toIso8601String()),
            changes: $changes,
            url: route('projects.edit', $project, false),
        );
    }

    protected function execute(array $arguments): array
    {
        $project = app(UpdateProject::class)->handle($this->actor(), $this->find((int) $arguments['id']), array_diff_key($arguments, ['id' => true]));

        return ['message' => 'Project updated.', 'project' => ProjectsList::present($project)];
    }

    protected function find(int $id): Project
    {
        return Project::query()->find($id) ?? throw ValidationException::withMessages(['id' => "Project {$id} not found."]);
    }
}
