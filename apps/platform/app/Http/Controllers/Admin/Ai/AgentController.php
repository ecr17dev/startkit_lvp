<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Ai\AiService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AgentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
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
 * Configurable agents: draft, validation, test, publication, history and
 * rollback. Routes require the ai.agents.manage permission.
 */
class AgentController extends Controller
{
    public function __construct(
        protected AgentManager $agents,
        protected ToolRegistry $tools,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/ai/agents/Index', [
            'agents' => Agent::query()
                ->with(['publishedVersion', 'draft'])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Agent $agent) => AgentPresenter::agent($agent)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('ai_agents', 'slug')],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $agent = $this->agents->create($validated, [], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent created as a draft. Publish it to make it available.')]);

        return to_route('admin.ai.agents.edit', $agent);
    }

    public function edit(Agent $agent): Response
    {
        $agent->load(['publishedVersion', 'draft']);
        $editable = $agent->draft ?? $agent->publishedVersion;
        $config = $editable?->settings() ?? AgentConfig::from([]);

        return Inertia::render('admin/ai/agents/Edit', [
            'agent' => AgentPresenter::agent($agent),
            'config' => $config->toArray(),
            'notes' => $agent->draft?->notes,
            'check' => $this->agents->check($config),
            'versions' => AgentPresenter::versions($agent),
            'options' => AgentPresenter::options($this->tools),
        ]);
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);

        $this->agents->update($agent, $validated, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent updated.')]);

        return back();
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        abort_if($agent->is_default, 422, __('The default agent cannot be deleted.'));

        $agent->delete();

        audit('ai.agent.deleted', $agent, ['slug' => $agent->slug], $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Agent deleted.')]);

        return to_route('admin.ai.agents.index');
    }

    /**
     * Save the settings in the draft (created from the published version).
     */
    public function saveDraft(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            ...AgentConfig::rules($this->tools->names()),
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->agents->saveDraft($agent, $validated['config'], $validated['notes'] ?? null, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

        return back();
    }

    public function discardDraft(Request $request, Agent $agent): RedirectResponse
    {
        $this->agents->discardDraft($agent, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft discarded.')]);

        return back();
    }

    public function publish(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        $version = $this->agents->publish($agent, $validated['notes'] ?? null, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version :number published.', ['number' => $version->number])]);

        return back();
    }

    public function rollback(Request $request, Agent $agent, AgentVersion $version): RedirectResponse
    {
        $restored = $this->agents->rollback($agent, $version, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Version :old restored as version :new.', ['old' => $version->number, 'new' => $restored->number])]);

        return back();
    }

    /**
     * Try the draft (or the published version) with a message. Recorded in
     * the ledger as a test; only read-only tools are offered.
     */
    public function test(Request $request, Agent $agent, AiService $ai): RedirectResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:4000']]);

        $agent->load(['publishedVersion', 'draft']);
        $version = $agent->draft ?? $agent->publishedVersion;
        abort_if($version === null, 404);
        $version->setRelation('agent', $agent);

        try {
            ['response' => $response, 'request' => $ledger, 'tools' => $tools] = $ai->test($this->actor($request), $version, $validated['message']);

            Inertia::flash('agentTest', [
                'ok' => true,
                'version' => $version->number,
                'text' => $response->text,
                'provider' => $ledger->provider,
                'model' => $ledger->model,
                'latency_ms' => $ledger->latency_ms,
                'input_tokens' => $ledger->input_tokens,
                'output_tokens' => $ledger->output_tokens,
                'cost' => $ledger->cost !== null ? (string) $ledger->cost : null,
                'cost_status' => $ledger->cost_status->value,
                'tools' => $tools,
            ]);
        } catch (BudgetExceededException|ModelNotUsableException $exception) {
            Inertia::flash('agentTest', ['ok' => false, 'version' => $version->number, 'error' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            Inertia::flash('agentTest', ['ok' => false, 'version' => $version->number, 'error' => __(ErrorClassifier::classify($exception)->message())]);
        }

        return back();
    }

    protected function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
