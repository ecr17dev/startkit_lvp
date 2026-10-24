<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AgentPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Starter\Ai\Agents\AgentConfig;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Agents\ToolRegistry;
use Starter\Ai\Connections\ErrorClassifier;
use Starter\Ai\Exceptions\BudgetExceededException;
use Starter\Ai\Exceptions\ModelNotUsableException;
use Starter\Ai\Models\Agent;
use Starter\Ai\Models\AgentVersion;
use Throwable;

/**
 * Configurable agents: draft, validation, test, publication and versions.
 * Requires `ai.agents.manage`.
 */
#[Group('AI agents', weight: 4)]
class AgentController extends Controller
{
    public function __construct(
        protected AgentManager $agents,
        protected ToolRegistry $tools,
    ) {}

    /**
     * List agents with their published version and draft.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => Agent::query()->with(['publishedVersion', 'draft'])->orderBy('name')->get()
                ->map(fn (Agent $agent) => AgentPresenter::agent($agent))->values(),
        ]);
    }

    /**
     * Show an agent: published settings, draft, validation and history.
     */
    public function show(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeManage($request);

        $agent->load(['publishedVersion', 'draft']);

        return response()->json([
            'data' => [
                ...AgentPresenter::agent($agent),
                'published' => $agent->publishedVersion !== null ? AgentPresenter::version($agent->publishedVersion) : null,
                'draft' => $agent->draft !== null ? [
                    ...AgentPresenter::version($agent->draft),
                    'check' => $this->agents->check($agent->draft->settings()),
                ] : null,
                'versions' => AgentPresenter::versions($agent),
            ],
        ]);
    }

    /**
     * Create an agent with a first draft (default settings when omitted).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            /** Lowercase letters, numbers and dashes. */
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('ai_agents', 'slug')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $agent = $this->agents->create($validated, [], $this->actor($request));

        return response()->json(['data' => AgentPresenter::agent($agent->load(['publishedVersion', 'draft']))], 201);
    }

    /**
     * Update the name, description and flags of an agent.
     */
    public function update(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $this->agents->update($agent, $validated, $this->actor($request));

        return response()->json(['data' => AgentPresenter::agent($agent->load(['publishedVersion', 'draft']))]);
    }

    /**
     * Save the draft settings. The complete settings object is required
     * (see the agent defaults in the published version).
     */
    public function saveDraft(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            ...AgentConfig::rules($this->tools->names()),
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $draft = $this->agents->saveDraft($agent, $validated['config'], $validated['notes'] ?? null, $this->actor($request));

        return response()->json(['data' => [...AgentPresenter::version($draft), 'check' => $this->agents->check($draft->settings())]]);
    }

    /**
     * Publish the draft. Validation errors (unusable model, unsupported
     * capabilities, unknown tools) block the publication.
     */
    public function publish(Request $request, Agent $agent): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => AgentPresenter::version($this->agents->publish($agent, $validated['notes'] ?? null, $this->actor($request)))]);
    }

    /**
     * Restore a previous version: its settings are published as a new version.
     */
    public function restore(Request $request, Agent $agent, AgentVersion $version): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['data' => AgentPresenter::version($this->agents->rollback($agent, $version, $this->actor($request)))]);
    }

    /**
     * Try the draft (or the published version). Recorded as a test in the
     * ledger, not remembered, and only read-only tools are offered.
     */
    public function test(Request $request, Agent $agent, AiService $ai): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $agent->load(['publishedVersion', 'draft']);
        $version = $agent->draft ?? $agent->publishedVersion;
        abort_if($version === null, 404);
        $version->setRelation('agent', $agent);

        try {
            ['response' => $response, 'request' => $ledger] = $ai->test($this->actor($request), $version, $validated['message']);
        } catch (BudgetExceededException|ModelNotUsableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => __(ErrorClassifier::classify($exception)->message())], 502);
        }

        return response()->json([
            'version' => $version->number,
            'text' => $response->text,
            'request_id' => $ledger->id,
            'model' => $ledger->model,
            'latency_ms' => $ledger->latency_ms,
            'cost' => $ledger->cost !== null ? (string) $ledger->cost : null,
            'cost_status' => $ledger->cost_status->value,
        ]);
    }

    protected function authorizeManage(Request $request): void
    {
        abort_unless($this->actor($request)->can('ai.agents.manage'), 403);
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
