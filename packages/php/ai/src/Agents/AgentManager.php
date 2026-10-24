<?php

namespace Starter\Ai\Agents;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Starter\Ai\Enums\AgentVersionStatus;
use Starter\Ai\Enums\Capability;
use Starter\Ai\Enums\CapabilityStatus;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiModel;

/**
 * Lifecycle of agents: draft, validation, publication, history and
 * rollback, plus resolution of the published version a user may use.
 * Every change is audited.
 */
class AgentManager
{
    public function __construct(protected ToolRegistry $tools) {}

    /**
     * Create an agent with a first draft.
     *
     * @param  array{slug: string, name: string, description?: string|null, is_active?: bool, is_default?: bool}  $attributes
     * @param  array<string, mixed>  $config
     */
    public function create(array $attributes, array $config, ?Authenticatable $actor = null): Agent
    {
        return DB::transaction(function () use ($attributes, $config, $actor) {
            $agent = Agent::query()->create([
                ...$attributes,
                'created_by' => $actor?->getAuthIdentifier(),
            ]);

            $agent->versions()->create([
                'number' => 1,
                'status' => AgentVersionStatus::Draft,
                'config' => AgentConfig::from($config)->toArray(),
                'created_by' => $actor?->getAuthIdentifier(),
            ]);

            audit('ai.agent.created', $agent, ['slug' => $agent->slug], $actor);

            return $agent;
        });
    }

    /**
     * Update the name, description and flags of an agent.
     *
     * @param  array{name?: string, description?: string|null, is_active?: bool, is_default?: bool}  $attributes
     */
    public function update(Agent $agent, array $attributes, ?Authenticatable $actor = null): Agent
    {
        return DB::transaction(function () use ($agent, $attributes, $actor) {
            if (($attributes['is_default'] ?? false) === true) {
                Agent::query()->whereKeyNot($agent->id)->where('is_default', true)->update(['is_default' => false]);
            }

            $agent->fill($attributes);
            $changes = $agent->getDirty();
            $agent->save();

            if ($changes !== []) {
                audit('ai.agent.updated', $agent, ['changes' => array_keys($changes)], $actor);
            }

            return $agent;
        });
    }

    /**
     * Get the draft of an agent, starting one from the published settings.
     */
    public function draft(Agent $agent, ?Authenticatable $actor = null): AgentVersion
    {
        $existing = $agent->draft()->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($agent, $actor) {
            $published = $agent->publishedVersion()->first();

            return $agent->versions()->create([
                'number' => $this->nextNumber($agent),
                'status' => AgentVersionStatus::Draft,
                'config' => AgentConfig::from($published->config ?? [])->toArray(),
                'created_by' => $actor?->getAuthIdentifier(),
            ]);
        });
    }

    /**
     * Save validated settings in the draft.
     *
     * @param  array<string, mixed>  $config
     */
    public function saveDraft(Agent $agent, array $config, ?string $notes = null, ?Authenticatable $actor = null): AgentVersion
    {
        $draft = $this->draft($agent, $actor);

        $draft->forceFill([
            'config' => AgentConfig::from($config)->toArray(),
            'notes' => $notes,
        ])->save();

        audit('ai.agent.draft_saved', $agent, ['version' => $draft->number], $actor);

        return $draft;
    }

    /**
     * Discard the draft of an agent, if any.
     */
    public function discardDraft(Agent $agent, ?Authenticatable $actor = null): void
    {
        $draft = $agent->draft()->first();

        if ($draft === null) {
            return;
        }

        // The first version of an agent that was never published stays as its draft...
        if ($agent->published_version_id === null) {
            throw ValidationException::withMessages(['draft' => __('The only version of an unpublished agent cannot be discarded.')]);
        }

        $draft->delete();

        audit('ai.agent.draft_discarded', $agent, ['version' => $draft->number], $actor);
    }

    /**
     * Check settings against the catalog: errors block publication,
     * warnings are shown to the administrator.
     *
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(AgentConfig $config): array
    {
        $errors = [];
        $warnings = [];

        $model = $config->modelId() !== null
            ? AiModel::query()->with(['connection', 'capabilities'])->find($config->modelId())
            : $this->defaultTextModel();

        if ($model === null && $config->modelId() !== null) {
            $errors[] = __('The selected model no longer exists.');
        } elseif ($model === null) {
            // The agent follows the catalog default, which may be configured later...
            $warnings[] = __('There is no default text model yet: the agent will answer once a model is enabled in the catalog.');
        } elseif (! $this->isUsable($model)) {
            $errors[] = __('The model :model is not usable (disabled, unavailable or with an inactive connection).', ['model' => $model->displayName()]);
        } elseif (! $model->supportsFunction('text')) {
            $errors[] = __('The model :model does not generate text.', ['model' => $model->displayName()]);
        }

        foreach ($config->fallbackModelIds() as $fallbackId) {
            $fallback = AiModel::query()->with('connection')->find($fallbackId);

            if ($fallback === null || ! $this->isUsable($fallback) || ! $fallback->supportsFunction('text')) {
                $warnings[] = __('The fallback model #:id is not usable and will be skipped.', ['id' => $fallbackId]);
            }
        }

        if ($model !== null) {
            $requirements = [
                [$config->tools() !== [], Capability::Tools, __('tools')],
                [$config->acceptsImages(), Capability::Vision, __('images')],
                [$config->acceptsDocuments(), Capability::Documents, __('documents')],
            ];

            foreach ($requirements as [$enabled, $capability, $label]) {
                if (! $enabled) {
                    continue;
                }

                match ($model->capabilityStatus($capability)) {
                    CapabilityStatus::Unsupported => $errors[] = __('The model does not support :feature.', ['feature' => $label]),
                    CapabilityStatus::Unknown => $warnings[] = __('The model has not declared support for :feature: declare or verify the capability in the catalog.', ['feature' => $label]),
                    CapabilityStatus::Supported => null,
                };
            }
        }

        if ($config->microphoneEnabled()) {
            $transcriber = $config->transcriptionModelId() !== null ? AiModel::query()->with('connection')->find($config->transcriptionModelId()) : null;

            if ($transcriber === null) {
                $errors[] = __('The microphone requires a transcription model.');
            } elseif (! $this->isUsable($transcriber) || ! $transcriber->supportsFunction('transcription')) {
                $errors[] = __('The transcription model :model is not usable for transcription.', ['model' => $transcriber->displayName()]);
            }
        }

        foreach (array_diff($config->tools(), $this->tools->names()) as $missing) {
            $errors[] = __('The tool :tool is not registered.', ['tool' => $missing]);
        }

        if (($unknown = $config->unknownVariables()) !== []) {
            $warnings[] = __('These placeholders are not allowed variables and stay literal: :variables.', ['variables' => implode(', ', $unknown)]);
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Publish the draft: it serves new conversations and the previous
     * published version is archived.
     *
     * @throws ValidationException
     */
    public function publish(Agent $agent, ?string $notes = null, ?Authenticatable $actor = null): AgentVersion
    {
        $draft = $agent->draft()->first() ?? throw ValidationException::withMessages(['draft' => __('There is no draft to publish.')]);

        $errors = $this->check($draft->settings())['errors'];

        if ($errors !== []) {
            throw ValidationException::withMessages(['draft' => $errors]);
        }

        return DB::transaction(function () use ($agent, $draft, $notes, $actor) {
            $this->archivePublished($agent);

            $draft->forceFill([
                'status' => AgentVersionStatus::Published,
                'notes' => $notes ?? $draft->notes,
                'published_by' => $actor?->getAuthIdentifier(),
                'published_at' => now(),
            ])->save();

            $agent->forceFill(['published_version_id' => $draft->id])->save();

            audit('ai.agent.published', $agent, ['version' => $draft->number], $actor);

            return $draft;
        });
    }

    /**
     * Publish again the settings of a previous version, as a new version so
     * the history stays linear.
     *
     * @throws ValidationException
     */
    public function rollback(Agent $agent, AgentVersion $target, ?Authenticatable $actor = null): AgentVersion
    {
        if ($target->agent_id !== $agent->id || $target->isDraft()) {
            throw ValidationException::withMessages(['version' => __('Only published or archived versions of this agent can be restored.')]);
        }

        $errors = $this->check($target->settings())['errors'];

        if ($errors !== []) {
            throw ValidationException::withMessages(['version' => $errors]);
        }

        return DB::transaction(function () use ($agent, $target, $actor) {
            $this->archivePublished($agent);

            $version = $agent->versions()->create([
                'number' => $this->nextNumber($agent),
                'status' => AgentVersionStatus::Published,
                'config' => $target->config,
                'notes' => __('Restored from version :number.', ['number' => $target->number]),
                'created_by' => $actor?->getAuthIdentifier(),
                'published_by' => $actor?->getAuthIdentifier(),
                'published_at' => now(),
            ]);

            $agent->forceFill(['published_version_id' => $version->id])->save();

            audit('ai.agent.rolled_back', $agent, ['version' => $version->number, 'restored' => $target->number], $actor);

            return $version;
        });
    }

    /**
     * Determine whether the user may use the agent on the given surface.
     */
    public function canUse(Agent $agent, ?Authenticatable $user, string $surface): bool
    {
        $version = $agent->publishedVersion;

        if (! $agent->is_active || $version === null) {
            return false;
        }

        $config = $version->settings();

        if (! in_array($surface, $config->surfaces(), true)) {
            return false;
        }

        $roles = $config->roles();

        if ($roles === []) {
            return true;
        }

        return $user !== null && method_exists($user, 'getRoleNames') && array_intersect($roles, $user->getRoleNames()->all()) !== [];
    }

    /**
     * Published agents the user may use on a surface, default first.
     *
     * @return Collection<int, Agent>
     */
    public function availableFor(?Authenticatable $user, string $surface): Collection
    {
        return Agent::query()
            ->published()
            ->with('publishedVersion')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->filter(fn (Agent $agent) => $this->canUse($agent, $user, $surface))
            ->values();
    }

    /**
     * Resolve the published version to use: the requested agent (id or
     * slug) or the default one available to the user.
     *
     * @throws AgentNotAvailableException
     */
    public function resolve(?Authenticatable $user, int|string|null $agent, string $surface): AgentVersion
    {
        if ($agent === null) {
            $resolved = $this->availableFor($user, $surface)->first();
        } else {
            $resolved = Agent::query()
                ->with('publishedVersion')
                ->where(fn ($query) => is_int($agent) || ctype_digit((string) $agent)
                    ? $query->whereKey((int) $agent)
                    : $query->where('slug', $agent))
                ->first();

            $resolved = $resolved !== null && $this->canUse($resolved, $user, $surface) ? $resolved : null;
        }

        if ($resolved?->publishedVersion === null) {
            throw new AgentNotAvailableException;
        }

        $version = $resolved->publishedVersion;
        $version->setRelation('agent', $resolved);

        return $version;
    }

    protected function archivePublished(Agent $agent): void
    {
        $agent->versions()
            ->where('status', AgentVersionStatus::Published->value)
            ->update(['status' => AgentVersionStatus::Archived->value, 'updated_at' => now()]);
    }

    protected function nextNumber(Agent $agent): int
    {
        // Lock the agent row: version numbers are serialized per agent...
        Agent::query()->whereKey($agent->id)->lockForUpdate()->first();

        return (int) $agent->versions()->max('number') + 1;
    }

    protected function isUsable(AiModel $model): bool
    {
        return AiModel::query()->usable()->whereKey($model->id)->exists();
    }

    protected function defaultTextModel(): ?AiModel
    {
        return AiModel::query()
            ->with(['connection', 'capabilities'])
            ->usable()
            ->forFunction('text')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
