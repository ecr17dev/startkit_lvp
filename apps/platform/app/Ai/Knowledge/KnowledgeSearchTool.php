<?php

namespace App\Ai\Knowledge;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Retrieval\RetrievalQuery;
use Starter\Knowledge\Retrieval\Retriever;

/**
 * Lets the model decide when to search the knowledge of its agent. The
 * collections and settings come from the published agent version, and the
 * access lists of the user apply inside the search.
 */
class KnowledgeSearchTool implements Tool
{
    /**
     * @param  list<Collection>  $collections
     * @param  array{method?: string|null, top_k?: int|null, min_score?: float|null, max_context_tokens?: int|null}  $settings
     */
    public function __construct(
        protected Retriever $retriever,
        protected Grounding $grounding,
        protected Authenticatable $user,
        protected array $collections,
        protected array $settings,
        protected ?int $agentVersionId,
        protected ?string $conversationId,
    ) {}

    public function name(): string
    {
        return 'knowledge_search';
    }

    public function description(): string
    {
        $names = implode(', ', array_map(fn (Collection $collection) => $collection->name, $this->collections));

        return "Search the organization's knowledge ({$names}) and return numbered passages. Use it for questions about internal policies, procedures or documentation, then cite the passages as [n].";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to look for, in natural language.')->max(500)->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $text = trim((string) $request->string('query'));

        if ($text === '') {
            return 'Provide a query.';
        }

        $query = RetrievalQuery::for($text, $this->user, $this->collections, $this->settings, 'tool');
        $query->agentVersionId = $this->agentVersionId;
        $query->conversationId = $this->conversationId;

        return $this->grounding->add($this->retriever->search($query));
    }
}
