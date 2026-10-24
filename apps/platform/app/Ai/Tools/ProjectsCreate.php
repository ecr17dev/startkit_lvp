<?php

namespace App\Ai\Tools;

use App\Actions\Projects\CreateProject;
use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;
use Starter\Ai\Agents\ProposalPreview;

class ProjectsCreate extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Create a project. Uses the same validation and permissions as the panel.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Name.')->required(),
            'code' => $schema->string()->description('Code.')->required(),
            'description' => $schema->string()->description('Description.'),
            'status' => $schema->string()->enum(['planned', 'active', 'on_hold', 'closed'])->description('Status.')->required(),
            'starts_on' => $schema->string()->description('Start date (YYYY-MM-DD).'),
            'due_on' => $schema->string()->description('Due date (YYYY-MM-DD).'),
            'budget' => $schema->number()->description('Budget.'),
            'is_billable' => $schema->boolean()->description('Billable.'),
        ];
    }

    protected function rules(): array
    {
        return CreateProject::rules();
    }

    protected function preview(array $arguments): ProposalPreview
    {
        Gate::forUser($this->actor())->authorize('create', Project::class);

        return new ProposalPreview(
            title: __('Create the project :name', ['name' => (string) ($arguments['name'] ?? '')]),
            resourceType: 'projects',
            changes: array_map(fn (string $field) => ['field' => $field, 'before' => null, 'after' => $arguments[$field]], array_keys($arguments)),
        );
    }

    protected function execute(array $arguments): array
    {
        $project = app(CreateProject::class)->handle($this->actor(), $arguments);

        return ['message' => 'Project created.', 'project' => ProjectsList::present($project->refresh())];
    }
}
