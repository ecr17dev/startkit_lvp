<?php

namespace Tests\Feature\Knowledge;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Starter\Knowledge\Enums\ConnectionStatus;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\RetrievalRun;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

/**
 * External retrieval API (contract v1): diagnostics and capabilities,
 * remote collections searched with the user principals, isolation rules,
 * grounded answers with remote passages, and the reference server.
 */
class RetrievalApiTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected const BASE = 'https://search.example.test/v1';

    protected User $owner;

    protected int $searchStatus = 200;

    /**
     * @var array<string, mixed>
     */
    protected array $declared = ['version' => '1', 'capabilities' => ['query', 'list_collections', 'list_documents', 'ingest'], 'enforces_acl' => true];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['starter.features.rag' => true]);
        $this->owner = $this->userWithRole('owner');
        $this->fakeService();
    }

    protected function fakeService(): void
    {
        Http::fake([
            self::BASE.'/capabilities' => fn () => Http::response($this->declared),
            self::BASE.'/collections' => Http::response(['data' => [
                ['id' => 'policies', 'name' => 'Policies', 'documents' => 2],
                ['id' => 'faq', 'name' => 'FAQ'],
            ]]),
            self::BASE.'/collections/policies/documents*' => Http::response(['data' => [['id' => 'travel', 'title' => 'Travel policy', 'uri' => 'https://intranet.example/travel']], 'next_page' => null]),
            self::BASE.'/search' => function (Request $request) {
                if ($this->searchStatus !== 200) {
                    return Http::response(['message' => 'down'], $this->searchStatus);
                }

                $hits = [];

                // The service filters by the principals it receives...
                if (in_array('role:reader', $request['principals'], true) || in_array('diagnostic', $request['principals'], true)) {
                    $hits[] = ['id' => 'c-1', 'document_id' => 'travel', 'collection' => 'policies', 'title' => 'Travel policy', 'text' => 'The national daily allowance is 45,000 pesos.', 'uri' => 'https://intranet.example/travel', 'location' => 'Allowances · page 2', 'score' => 0.91, 'score_type' => 'cosine'];
                }

                // ...and a hit carrying a stricter access list is dropped again here.
                $hits[] = ['id' => 'c-2', 'document_id' => 'board', 'collection' => 'policies', 'title' => 'Board minutes', 'text' => 'Confidential.', 'score' => 0.5, 'acl' => ['role:owner']];

                return Http::response(['data' => ['hits' => $hits]]);
            },
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function connection(array $config = []): RagConnection
    {
        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.store'), [
            'kind' => 'api',
            'label' => 'Corporate search',
            'config' => ['base_url' => self::BASE, 'timeout' => 5, 'enforces_acl' => true, ...$config],
            'credentials' => ['token' => 'service-token-123'],
        ])->assertSessionHasNoErrors();

        return RagConnection::query()->where('kind', 'api')->sole();
    }

    protected function activate(RagConnection $connection): void
    {
        $this->actingAs($this->owner)->post(route('admin.knowledge.activate'), ['mode' => 'external_api', 'connection_id' => $connection->id])->assertSessionHasNoErrors();
        $this->assertSame(ConnectionStatus::Ready, $connection->refresh()->status, json_encode($connection->diagnostics) ?: '');
    }

    /**
     * @param  list<string>  $acl
     */
    protected function remoteCollection(string $externalId = 'policies', array $acl = ['role:reader']): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('admin.knowledge.collections.store'), [
            'name' => 'Corporate policies',
            'external_id' => $externalId,
            'acl' => $acl,
            'language' => 'es',
            'search' => ['method' => 'hybrid', 'top_k' => 4, 'max_context_tokens' => 2000],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function events(TestResponse $response): array
    {
        $events = [];

        foreach (explode("\n\n", $response->streamedContent()) as $chunk) {
            $data = trim((string) preg_replace('/^data: /', '', trim($chunk)));

            if ($data !== '' && $data !== '[DONE]') {
                $events[] = json_decode($data, true);
            }
        }

        return $events;
    }

    public function test_a_retrieval_api_is_diagnosed_and_activated_with_its_declared_capabilities(): void
    {
        $connection = $this->connection();
        $this->assertSame(ConnectionStatus::Pending, $connection->status);

        $this->activate($connection);

        // Only what the adapter implements, never the declared ingestion...
        $this->assertSame(['query', 'list_collections', 'list_documents'], $connection->capabilities);
        $this->assertSame('external_api', app(Knowledge::class)->mode()->value);
        $checks = collect($connection->diagnostics['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['isolation']['status']);
        $this->assertSame('ok', $checks['smoke']['status']);
        $this->assertSame('ok', $checks['tls']['status']);

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/capabilities' && $request->hasHeader('Authorization', 'Bearer service-token-123'));
        $this->assertStringNotContainsString('service-token-123', json_encode($connection->diagnostics) ?: '');

        // Profiles do not exist for a retrieval API...
        $this->actingAs($this->owner)->post(route('admin.knowledge.profiles.store'), ['name' => 'X', 'dimensions' => 256])->assertSessionHasErrors('rag');
    }

    public function test_a_contract_mismatch_is_reported(): void
    {
        $this->declared = ['version' => '2', 'capabilities' => ['query']];
        $connection = $this->connection();

        $this->actingAs($this->owner)->post(route('admin.knowledge.activate'), ['mode' => 'external_api', 'connection_id' => $connection->id]);

        $this->assertSame(ConnectionStatus::Failed, $connection->refresh()->status);
        $this->assertSame('error', collect($connection->diagnostics['checks'])->firstWhere('key', 'contract')['status']);
        $this->assertSame('disabled', app(Knowledge::class)->mode()->value);
    }

    public function test_remote_collections_are_searched_with_the_user_principals(): void
    {
        $this->activate($this->connection());

        $this->actingAs($this->owner)->get(route('admin.knowledge.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('remote.collections.0.id', 'policies')->where('remote.isolated', true));

        $this->remoteCollection('unknown')->assertSessionHasErrors('external_id');
        $this->remoteCollection()->assertSessionHasNoErrors();
        $collection = Collection::query()->where('external_id', 'policies')->sole();
        $this->assertNull($collection->embedding_profile_id);
        $this->assertTrue($collection->isRemote());

        // Ingestion is managed by the service...
        $this->actingAs($this->owner)->post(route('admin.knowledge.sources.store', $collection), ['type' => 'url', 'name' => 'Web', 'config' => ['urls' => ['https://example.com']]])
            ->assertSessionHasErrors('rag');

        $reader = $this->userWithRole('reader');
        $this->actingAs($reader)->post(route('admin.knowledge.lab.search'), ['query' => '¿Cuál es el viático diario?'])->assertSessionHasNoErrors();

        $result = data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), 'search.results.0');
        $this->assertCount(1, $result['hits']);
        $this->assertSame('c-1', $result['hits'][0]['external_id']);
        $this->assertSame('Travel policy', $result['hits'][0]['title']);
        $this->assertSame('Allowances · page 2', $result['hits'][0]['location']);
        $this->assertArrayNotHasKey('document_id', $result['hits'][0]);
        $this->assertNull($result['cost']);

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/search'
            && $request['collections'] === ['policies']
            && in_array('role:reader', $request['principals'], true)
            && $request['user']['id'] === (string) $reader->id);

        $run = RetrievalRun::query()->where('surface', 'lab')->latest()->first();
        $this->assertNotNull($run);
        $this->assertNull($run->cost);
        $hit = $run->hits()->sole();
        $this->assertNull($hit->document_id);
        $this->assertSame('c-1', $hit->location['external_id']);

        // The collection access list applies here first: the service is not even called...
        Http::fake();
        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.lab.search'), ['query' => 'viático'])->assertSessionHasNoErrors();
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/search'));
    }

    public function test_without_guaranteed_isolation_only_public_collections_are_allowed(): void
    {
        $this->declared['enforces_acl'] = false;
        $connection = $this->connection(['enforces_acl' => false]);
        $this->activate($connection);
        $this->assertSame('warning', collect($connection->diagnostics['checks'])->firstWhere('key', 'isolation')['status']);

        $this->remoteCollection()->assertSessionHasErrors('acl');
        $this->remoteCollection(acl: ['everyone'])->assertSessionHasNoErrors();

        // A connection that claims isolation the service does not declare fails...
        $this->actingAs($this->owner)->put(route('admin.knowledge.connections.update', $connection), [
            'label' => 'Corporate search',
            'config' => ['base_url' => self::BASE, 'enforces_acl' => true],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.diagnose', $connection));
        $this->assertSame('error', collect($connection->refresh()->diagnostics['checks'])->firstWhere('key', 'isolation')['status']);
    }

    public function test_grounded_answers_cite_remote_passages(): void
    {
        $this->activate($this->connection());
        $this->remoteCollection()->assertSessionHasNoErrors();
        $collection = Collection::query()->where('external_id', 'policies')->sole();

        $this->simulatedModel();
        $this->aiAgent(['rag' => ['mode' => 'always', 'collections' => [$collection->id], 'top_k' => 3]]);

        $events = $this->events($this->actingAs($this->userWithRole('reader'))->post(route('ai.chat.stream'), ['message' => '¿Cuál es el viático diario nacional?'])->assertOk());
        $knowledge = collect($events)->firstWhere('type', 'knowledge')['knowledge'];

        $this->assertSame('answered', $knowledge['outcome']);
        $this->assertSame('Travel policy', $knowledge['citations'][0]['title']);
        $this->assertSame('https://intranet.example/travel', $knowledge['citations'][0]['uri']);
        $this->assertNull($knowledge['citations'][0]['document_id']);
    }

    public function test_service_failures_leave_no_results_and_no_secrets(): void
    {
        $this->activate($this->connection());
        $this->remoteCollection()->assertSessionHasNoErrors();

        $this->searchStatus = 503;

        $this->actingAs($this->userWithRole('reader'))->post(route('admin.knowledge.lab.search'), ['query' => 'viático'])->assertSessionHasNoErrors();
        $result = data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), 'search.results.0');

        $this->assertSame([], $result['hits']);
        $this->assertStringContainsString('503', $result['diagnostics']['errors'][0]);
        $this->assertStringNotContainsString('service-token-123', json_encode($result) ?: '');
    }

    public function test_the_collection_page_lists_remote_documents(): void
    {
        $this->activate($this->connection());
        $this->remoteCollection()->assertSessionHasNoErrors();
        $collection = Collection::query()->where('external_id', 'policies')->sole();

        $this->actingAs($this->userWithRole('reader'))->get(route('admin.knowledge.collections.show', $collection))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('collection.remote', true)
                ->where('remote.documents.0.title', 'Travel policy')
                ->where('remote.available', true));

        // Deleting only removes the registration here...
        $this->actingAs($this->owner)->delete(route('admin.knowledge.collections.destroy', $collection), ['confirmation' => $collection->slug])->assertSessionHasNoErrors();
        $this->assertModelMissing($collection);
    }

    public function test_the_reference_server_implements_the_contract(): void
    {
        $collection = $this->knowledgeCollection();
        $this->actingAs($this->owner)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload',
            'name' => 'Políticas',
            'files' => [$this->knowledgeFile('viajes.md', "# Política de viajes\n\n## Viáticos\n\nEl viático diario nacional es de 45.000 pesos y se rinde en cinco días hábiles con boletas.")],
        ])->assertSessionHasNoErrors();

        $service = $this->userWithRole('reader');

        Sanctum::actingAs($service, ['ai']);
        $this->getJson(route('retrieval.v1.capabilities'))->assertForbidden();

        Sanctum::actingAs($service, ['read']);
        $this->getJson(route('retrieval.v1.capabilities'))->assertOk()->assertJsonPath('version', '1')->assertJsonPath('enforces_acl', true);
        $this->getJson(route('retrieval.v1.collections'))->assertOk()->assertJsonPath('data.0.id', 'handbook')->assertJsonPath('data.0.documents', 1);
        $this->getJson(route('retrieval.v1.documents', 'handbook'))->assertOk()->assertJsonPath('data.0.title', 'Política de viajes');
        $this->getJson(route('retrieval.v1.documents', 'missing'))->assertNotFound();

        $this->postJson(route('retrieval.v1.search'), ['query' => 'viático diario', 'collections' => ['handbook'], 'top_k' => 3, 'method' => 'text', 'principals' => ['user:99', 'everyone']])
            ->assertOk()
            ->assertJsonPath('data.hits.0.collection', 'handbook')
            ->assertJsonPath('data.hits.0.title', 'Política de viajes')
            ->assertJsonPath('usage.cost', '0');

        // The end user principals only narrow the token user's access...
        $this->postJson(route('retrieval.v1.search'), ['query' => 'viático diario', 'method' => 'text', 'principals' => ['role:nobody']])->assertOk()->assertJsonCount(0, 'data.hits');
        $this->postJson(route('retrieval.v1.search'), ['query' => 'viático diario', 'method' => 'text', 'collections' => ['unknown']])->assertOk()->assertJsonCount(0, 'data.hits');
        $this->assertSame('retrieval_api', RetrievalRun::query()->latest()->value('surface'));
    }
}
