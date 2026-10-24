<?php

namespace App\Ai;

use App\Models\User;
use Laravel\Ai\Approvals\Decisions;
use Starter\Ai\Agents\ActionProposals;
use Starter\Ai\Agents\AgentManager;
use Starter\Ai\Exceptions\AgentNotAvailableException;
use Starter\Ai\Exceptions\ProposalNotPendingException;
use Starter\Ai\Models\ActionProposal;
use Starter\Ai\Models\AgentVersion;
use Starter\Ai\Models\AiRequest;

/**
 * Records the user's decision on a proposal and prepares the SDK resume:
 * the conversation, the agent version that prepared it and the decisions.
 */
class ProposalResumer
{
    public function __construct(
        protected ActionProposals $proposals,
        protected AgentManager $agents,
    ) {}

    /**
     * @return array{version: AgentVersion, decisions: Decisions, conversation_id: string}
     *
     * @throws ProposalNotPendingException|AgentNotAvailableException
     */
    public function decide(User $user, ActionProposal $proposal, bool $approve, string $surface): array
    {
        if ($proposal->user_id !== $user->id) {
            throw new ProposalNotPendingException;
        }

        $conversationId = $proposal->conversation_id
            ?? ($proposal->ai_request_id !== null ? AiRequest::query()->whereKey($proposal->ai_request_id)->value('conversation_id') : null);

        $version = $proposal->agentVersion()->with('agent')->first();

        if (! is_string($conversationId) || $version === null) {
            throw new ProposalNotPendingException;
        }

        if (! $this->agents->canUseVersion($version, $user, $surface)) {
            throw new AgentNotAvailableException;
        }

        $decided = $approve ? $this->proposals->approve($proposal, $user) : $this->proposals->reject($proposal, $user);

        if (! $decided) {
            throw new ProposalNotPendingException;
        }

        // The other calls paused in the same turn are rejected by the resume...
        $this->proposals->supersede($conversationId, $user);

        return [
            'version' => $version,
            'decisions' => AiService::decisions($proposal, $approve),
            'conversation_id' => $conversationId,
        ];
    }
}
