<?php

namespace App\Actions\Knowledge;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Starter\Knowledge\Enums\SearchMethod;
use Starter\Knowledge\Jobs\RunEvaluation;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\EvaluationItem;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Models\EvaluationSet;
use Starter\Knowledge\Support\Access;

/**
 * Evaluation sets (questions with the documents that should be found) and
 * runs comparing retrieval settings. Runs search as their author, so the
 * access lists keep applying.
 */
class ManageEvaluations
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function saveSet(User $actor, array $input, ?EvaluationSet $set = null): EvaluationSet
    {
        Gate::forUser($actor)->authorize('rag.playground.use');

        /** @var array{name: string, description?: string|null, collection_ids: list<int>} $data */
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'collection_ids' => ['required', 'array', 'min:1', 'max:20'],
            'collection_ids.*' => ['integer', 'distinct', Rule::exists('rag_collections', 'id')],
        ])->validate();

        $visible = Collection::query()->whereIn('id', $data['collection_ids'])->get()->filter(fn (Collection $collection) => $collection->isVisibleTo($actor));

        if ($visible->count() !== count($data['collection_ids'])) {
            throw ValidationException::withMessages(['collection_ids' => __('You can only evaluate collections you can access.')]);
        }

        $set ??= new EvaluationSet(['created_by' => $actor->id]);
        $set->fill([...$data, 'collection_ids' => array_map('intval', $data['collection_ids'])])->save();

        audit('rag.evaluations.saved', null, ['set' => $set->id, 'name' => $set->name], $actor);

        return $set;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveItem(User $actor, EvaluationSet $set, array $input, ?EvaluationItem $item = null): EvaluationItem
    {
        Gate::forUser($actor)->authorize('rag.playground.use');

        /** @var array{question: string, expected_document_ids: list<int>, expected_answer?: string|null} $data */
        $data = Validator::make($input, [
            'question' => ['required', 'string', 'max:2000'],
            'expected_document_ids' => ['required', 'array', 'min:1', 'max:20'],
            'expected_document_ids.*' => ['integer', 'distinct'],
            'expected_answer' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $documents = Document::query()->with('collection', 'source')->whereIn('id', $data['expected_document_ids'])->whereIn('collection_id', $set->collection_ids)->alive()->get();

        if ($documents->count() !== count($data['expected_document_ids']) || $documents->contains(fn (Document $document) => ! Access::canReadDocument($actor, $document))) {
            throw ValidationException::withMessages(['expected_document_ids' => __('Choose documents of the set collections that you can read.')]);
        }

        $item ??= new EvaluationItem(['set_id' => $set->id]);
        $item->fill([...$data, 'expected_document_ids' => array_map('intval', $data['expected_document_ids'])])->save();

        return $item;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function run(User $actor, EvaluationSet $set, array $input): EvaluationRun
    {
        Gate::forUser($actor)->authorize('rag.playground.use');

        /** @var array{label?: string|null, method?: string|null, top_k?: int|null, min_score?: float|null, max_context_tokens?: int|null} $data */
        $data = Validator::make($input, [
            'label' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', Rule::enum(SearchMethod::class)],
            'top_k' => ['nullable', 'integer', 'between:1,50'],
            'min_score' => ['nullable', 'numeric', 'between:0,1'],
            'max_context_tokens' => ['nullable', 'integer', 'between:100,32000'],
        ])->validate();

        if (! $set->items()->exists()) {
            throw ValidationException::withMessages(['set' => __('Add questions to the set before running it.')]);
        }

        $run = $set->runs()->create([
            'label' => $data['label'] ?? null,
            'settings' => array_filter([
                'method' => $data['method'] ?? null,
                'top_k' => isset($data['top_k']) ? (int) $data['top_k'] : null,
                'min_score' => isset($data['min_score']) ? (float) $data['min_score'] : null,
                'max_context_tokens' => isset($data['max_context_tokens']) ? (int) $data['max_context_tokens'] : null,
            ], fn ($value) => $value !== null),
            'status' => EvaluationRun::QUEUED,
            'created_by' => $actor->id,
        ]);

        RunEvaluation::dispatch($run->id)->afterCommit();

        audit('rag.evaluations.run', null, ['set' => $set->id, 'run' => $run->id], $actor);

        return $run->refresh();
    }
}
