<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Agents\ToolDefinition;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;

/**
 * Shapes agents and their versions for the panel and the API.
 */
class AgentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function agent(Agent $agent): array
    {
        $published = $agent->publishedVersion;
        $config = $published?->settings();

        return [
            'id' => $agent->id,
            'slug' => $agent->slug,
            'name' => $agent->name,
            'description' => $agent->description,
            'is_active' => $agent->is_active,
            'is_default' => $agent->is_default,
            'avatar' => $config?->get('identity.avatar'),
            'published_version' => $published !== null ? [
                'id' => $published->id,
                'number' => $published->number,
                'published_at' => $published->published_at?->toIso8601String(),
            ] : null,
            'draft' => $agent->draft !== null ? [
                'id' => $agent->draft->id,
                'number' => $agent->draft->number,
                'updated_at' => $agent->draft->updated_at->toIso8601String(),
            ] : null,
            'tools' => $config?->tools() ?? [],
            'surfaces' => $config?->surfaces() ?? [],
            'roles' => $config?->roles() ?? [],
        ];
    }

    /**
     * Versions, newest first, with the setting groups changed from the
     * previous version.
     *
     * @return list<array<string, mixed>>
     */
    public static function versions(Agent $agent): array
    {
        $versions = $agent->versions()->orderBy('number')->get();
        $users = User::query()->whereIn('id', $versions->pluck('created_by')->merge($versions->pluck('published_by'))->filter()->unique())->pluck('name', 'id');

        $rows = [];
        $previous = null;

        foreach ($versions as $version) {
            $config = $version->settings()->toArray();

            $rows[] = [
                'id' => $version->id,
                'number' => $version->number,
                'status' => $version->status->value,
                'notes' => $version->notes,
                'created_by' => $users[$version->created_by] ?? null,
                'published_by' => $users[$version->published_by] ?? null,
                'published_at' => $version->published_at?->toIso8601String(),
                'created_at' => $version->created_at->toIso8601String(),
                'changes' => $previous === null ? [] : array_keys(array_filter(
                    $config,
                    fn (mixed $value, string $group) => $value != ($previous[$group] ?? null),
                    ARRAY_FILTER_USE_BOTH,
                )),
            ];

            $previous = $config;
        }

        return array_reverse($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(AgentVersion $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'status' => $version->status->value,
            'notes' => $version->notes,
            'published_at' => $version->published_at?->toIso8601String(),
            'config' => $version->settings()->toArray(),
        ];
    }

    /**
     * Choices offered by the agent form.
     *
     * @return array<string, mixed>
     */
    public static function options(ToolRegistry $tools): array
    {
        $models = AiModel::query()->with(['connection', 'capabilities'])->usable()->orderByDesc('is_default')->orderBy('model')->get();

        return [
            'models' => $models->filter(fn (AiModel $model) => $model->supportsFunction('text'))->values()->map(fn (AiModel $model) => [
                'id' => $model->id,
                'label' => $model->displayName(),
                'connection' => $model->connection->label,
                'is_default' => $model->is_default,
                'capabilities' => [
                    'tools' => $model->capabilityStatus(Capability::Tools)->value,
                    'vision' => $model->capabilityStatus(Capability::Vision)->value,
                    'documents' => $model->capabilityStatus(Capability::Documents)->value,
                ],
            ]),
            'transcription_models' => $models->filter(fn (AiModel $model) => $model->supportsFunction('transcription'))->values()->map(fn (AiModel $model) => [
                'id' => $model->id,
                'label' => $model->displayName(),
                'connection' => $model->connection->label,
            ]),
            'tools' => array_values(array_map(fn (ToolDefinition $tool) => $tool->toArray(), $tools->all())),
            'roles' => Role::query()->orderBy('id')->pluck('name'),
            'variables' => AgentConfig::VARIABLES,
            'languages' => AgentConfig::LANGUAGES,
            'tones' => AgentConfig::TONES,
            'styles' => AgentConfig::STYLES,
            'surfaces' => AgentConfig::SURFACES,
            'image_types' => AgentConfig::IMAGE_TYPES,
            'document_types' => AgentConfig::DOCUMENT_TYPES,
        ];
    }
}
