<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\InteractsWithDirectory;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Starter\Ai\Agents\OperatorTool;

class ProjectsList extends OperatorTool
{
    use InteractsWithDirectory;

    public function description(): string
    {
        return 'Search projects by name, code. Returns their ids and fields.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->max(100)->description('Text to search.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum results (default 20).'),
        ];
    }

    protected function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    protected function execute(array $arguments): array
    {
        Gate::forUser($this->actor())->authorize('viewAny', Project::class);

        $projects = Project::query()
            ->when($arguments['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%")
            ))
            ->latest('id')
            ->limit((int) ($arguments['limit'] ?? 20))
            ->get();

        return ['projects' => $projects->map(fn (Project $project) => self::present($project))->all()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'description' => $project->description,
            'status' => $project->status,
            'starts_on' => $project->starts_on?->toDateString(),
            'due_on' => $project->due_on?->toDateString(),
            'budget' => $project->budget,
            'is_billable' => $project->is_billable,
            'updated_at' => $project->updated_at->toIso8601String(),
            'url' => route('projects.edit', $project, false),
        ];
    }
}
