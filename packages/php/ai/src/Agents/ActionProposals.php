<?php

namespace Starter\Ai\Agents;

use Illuminate\Contracts\Auth\Authenticatable;
use Starter\Ai\Enums\ProposalStatus;
use Starter\Ai\Models\ActionProposal;
use Throwable;

/**
 * Server-side proposals for tool calls that need a human confirmation.
 *
 * - A proposal is created when the SDK pauses a tool call (one per call).
 * - Confirming or rejecting is an atomic transition from "pending", only
 *   for its actor and before it expires.
 * - The tool claims an approved proposal once ("executing") and records
 *   the result; arguments and the resource state must still match.
 */
class ActionProposals
{
    /**
     * Get or create the proposal of a paused tool call.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function propose(ToolContext $context, ToolDefinition $definition, string $toolCallId, array $arguments, ProposalPreview $preview): ActionProposal
    {
        return ActionProposal::query()->firstOrCreate([
            'user_id' => $context->actor->getAuthIdentifier(),
            'tool_call_id' => $toolCallId,
        ], [
            'agent_version_id' => $context->version->id,
            'ai_request_id' => $context->requestId,
            'conversation_id' => $context->conversationId,
            'tool' => $definition->name,
            'kind' => $definition->kind,
            'resource_type' => $preview->resourceType,
            'resource_id' => $preview->resourceId,
            'resource_version' => $preview->resourceVersion,
            'arguments' => $arguments,
            'arguments_hash' => self::hash($arguments),
            'preview' => $preview->toArray(),
            'status' => ProposalStatus::Pending,
            'expires_at' => now()->addMinutes(self::ttl()),
        ]);
    }

    public function find(ToolContext $context, string $toolCallId): ?ActionProposal
    {
        return ActionProposal::query()
            ->where('user_id', $context->actor->getAuthIdentifier())
            ->where('tool_call_id', $toolCallId)
            ->first();
    }

    /**
     * Confirm a pending proposal of the user. Returns false when it is not
     * pending anymore, expired or belongs to someone else.
     */
    public function approve(ActionProposal $proposal, Authenticatable $user): bool
    {
        return $this->decide($proposal, $user, ProposalStatus::Approved, 'ai.proposal.approved');
    }

    public function reject(ActionProposal $proposal, Authenticatable $user): bool
    {
        return $this->decide($proposal, $user, ProposalStatus::Rejected, 'ai.proposal.rejected');
    }

    /**
     * Claim an approved proposal for execution (single use).
     */
    public function claim(ActionProposal $proposal): bool
    {
        return ActionProposal::query()
            ->whereKey($proposal->id)
            ->where('status', ProposalStatus::Approved->value)
            ->update(['status' => ProposalStatus::Executing->value, 'updated_at' => now()]) === 1;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function executed(ActionProposal $proposal, array $result): void
    {
        $proposal->forceFill([
            'status' => ProposalStatus::Executed,
            'executed_at' => now(),
            'result' => $result,
        ])->save();
    }

    public function failed(ActionProposal $proposal, Throwable|string $error): void
    {
        $proposal->forceFill([
            'status' => ProposalStatus::Failed,
            'executed_at' => now(),
            'error' => mb_substr(is_string($error) ? $error : $error->getMessage(), 0, 1000),
        ])->save();

        audit('ai.proposal.failed', null, ['proposal' => $proposal->id, 'tool' => $proposal->tool, 'error' => $proposal->error], null);
    }

    /**
     * Pending proposals of a conversation are superseded by a new message:
     * the SDK settles the abandoned tool calls as not executed.
     */
    public function supersede(string $conversationId, Authenticatable $user): int
    {
        return ActionProposal::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', ProposalStatus::Pending->value)
            ->update(['status' => ProposalStatus::Superseded->value, 'decided_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Record the conversation of the proposals created by a ledger request
     * (a new conversation gets its id only after the first answer).
     */
    public function attachConversation(string $requestId, string $conversationId): void
    {
        ActionProposal::query()
            ->where('ai_request_id', $requestId)
            ->whereNull('conversation_id')
            ->update(['conversation_id' => $conversationId]);
    }

    public function expireStale(): int
    {
        return ActionProposal::query()
            ->whereIn('status', [ProposalStatus::Pending->value, ProposalStatus::Approved->value])
            ->where('expires_at', '<', now())
            ->update(['status' => ProposalStatus::Expired->value, 'updated_at' => now()]);
    }

    /**
     * Stable hash of normalized arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function hash(array $arguments): string
    {
        return hash('sha256', (string) json_encode(self::normalize($arguments)));
    }

    public static function ttl(): int
    {
        return max(1, (int) config('starter.ai.proposal_ttl_minutes', 10));
    }

    protected function decide(ActionProposal $proposal, Authenticatable $user, ProposalStatus $status, string $event): bool
    {
        $updated = ActionProposal::query()
            ->whereKey($proposal->id)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', ProposalStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->update(['status' => $status->value, 'decided_at' => now(), 'updated_at' => now()]) === 1;

        if ($updated) {
            $proposal->refresh();

            audit($event, null, [
                'proposal' => $proposal->id,
                'tool' => $proposal->tool,
                'target' => $proposal->preview['target'],
                'agent_version_id' => $proposal->agent_version_id,
                'ai_request_id' => $proposal->ai_request_id,
            ], $user);
        }

        return $updated;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected static function normalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => is_array($item) ? self::normalize($item) : $item, $value);
    }
}
