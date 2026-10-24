<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Starter\Knowledge\Models\RetrievalHit;
use Starter\Knowledge\Models\RetrievalRun;

/**
 * Human quality signals: feedback on a grounded answer (by the user who
 * received it) and relevance judgements of retrieved passages (in the lab,
 * by whoever ran the search).
 */
class RateKnowledge
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function answer(User $actor, string $messageId, array $input): int
    {
        /** @var array{rating: int, note?: string|null} $data */
        $data = Validator::make($input, [
            /** 1 = useful, -1 = not useful. */
            'rating' => ['required', 'integer', 'in:-1,1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $updated = RetrievalRun::query()->where('message_id', $messageId)->where('user_id', $actor->id)
            ->update(['feedback' => (int) $data['rating'], 'feedback_note' => $data['note'] ?? null, 'feedback_at' => now()]);

        abort_if($updated === 0, 404);

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function hit(User $actor, RetrievalHit $hit, array $input): RetrievalHit
    {
        Gate::forUser($actor)->authorize('rag.playground.use');
        abort_unless($hit->run !== null && $hit->run->user_id === $actor->id, 404);

        /** @var array{judgement: int} $data */
        $data = Validator::make($input, [
            /** 1 = relevant, -1 = not relevant, 0 = clear. */
            'judgement' => ['required', 'integer', 'in:-1,0,1'],
        ])->validate();

        $hit->forceFill([
            'judgement' => $data['judgement'] === 0 ? null : (int) $data['judgement'],
            'judged_by' => $data['judgement'] === 0 ? null : $actor->id,
            'judged_at' => $data['judgement'] === 0 ? null : now(),
        ])->save();

        return $hit;
    }
}
