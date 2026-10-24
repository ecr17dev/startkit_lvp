<?php

namespace App\Http\Controllers\Api\V1\Rag;

use App\Actions\Knowledge\SearchKnowledge;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Starter\Knowledge\Exceptions\RagUnavailableException;

#[Group('Knowledge (RAG)', weight: 60)]
class SearchController extends Controller
{
    /**
     * Search the authorized knowledge.
     *
     * Returns the ranked passages (only from collections and documents your
     * access lists allow), the method, latency and the cost of embedding the
     * query (null when its price is unknown). Scores are rankings within a
     * method (cosine similarity, text rank or reciprocal rank fusion), not
     * probabilities. Requires `rag.playground.use`.
     */
    public function __invoke(Request $request, SearchKnowledge $action): JsonResponse
    {
        try {
            $result = $action->handle($this->actor($request), $request->all(), 'api');
        } catch (RagUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['data' => $result->toArray()]);
    }
}
