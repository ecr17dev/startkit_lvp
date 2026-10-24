<?php

namespace Tests\Feature\Knowledge;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\ConnectionStatus;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;
use Starter\Knowledge\Models\Source;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

/**
 * External PostgreSQL + pgvector: a dedicated schema of the test server
 * reached through its own runtime connection (another session, outside
 * the test transaction), exactly as a remote database would be.
 */
class ExternalPostgresTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected const SCHEMA = 'rag_external_test';

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['starter.features.rag' => true]);
        $this->dropSchema();
        $this->owner = $this->userWithRole('owner');
    }

    protected function tearDown(): void
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $database */
        $database = config('database.connections.pgsql');

        // Once the test transaction ended (it may hold locks on the schema)...
        parent::tearDown();

        $pdo = new \PDO("pgsql:host={$database['host']};port={$database['port']};dbname={$database['database']}", $database['username'], $database['password']);
        $pdo->exec('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
    }

    protected function dropSchema(): void
    {
        // Own session: the external schema is not covered by the test transaction...
        config(['database.connections.rag_cleanup' => config('database.connections.pgsql')]);
        DB::connection('rag_cleanup')->statement('DROP SCHEMA IF EXISTS '.self::SCHEMA.' CASCADE');
        DB::disconnect('rag_cleanup');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function payload(array $config = [], array $credentials = []): array
    {
        $database = config('database.connections.pgsql');

        return [
            'kind' => 'pgsql',
            'label' => 'Analytics database',
            'config' => [
                'host' => $database['host'],
                'port' => $database['port'],
                'database' => $database['database'],
                'schema' => self::SCHEMA,
                'sslmode' => 'prefer',
                'read_only' => false,
                ...$config,
            ],
            'credentials' => ['username' => $database['username'], 'password' => $database['password'], ...$credentials],
        ];
    }

    protected function createConnection(): RagConnection
    {
        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.store'), $this->payload())->assertSessionHasNoErrors();

        return RagConnection::query()->where('kind', 'pgsql')->sole();
    }

    protected function activate(RagConnection $connection): void
    {
        $this->actingAs($this->owner)->post(route('admin.knowledge.activate'), ['mode' => 'external_pgsql', 'connection_id' => $connection->id])->assertSessionHasNoErrors();
        $this->assertSame(ConnectionStatus::Ready, $connection->refresh()->status, json_encode($connection->diagnostics) ?: '');
    }

    protected function schemaTable(string $table): bool
    {
        return (bool) DB::scalar('SELECT to_regclass(?) IS NOT NULL', [self::SCHEMA.'.'.$table]);
    }

    /**
     * Profile, collection and an indexed Markdown file on the external database.
     */
    protected function indexOnExternal(RagConnection $connection): Collection
    {
        $this->simulatedModel();
        $model = AiModel::query()->where('model', 'simulated-embedding')->sole();

        $this->actingAs($this->owner)->post(route('admin.knowledge.profiles.store'), ['model_id' => $model->id, 'name' => 'External 256d', 'dimensions' => 256, 'metric' => 'cosine'])
            ->assertSessionHasNoErrors();
        $profile = EmbeddingProfile::query()->where('connection_id', $connection->id)->sole();

        $this->actingAs($this->owner)->post(route('admin.knowledge.collections.store'), [
            'name' => 'Manuales',
            'embedding_profile_id' => $profile->id,
            'language' => 'es',
        ])->assertSessionHasNoErrors();
        $collection = Collection::query()->where('slug', 'manuales')->sole();

        $this->actingAs($this->owner)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload',
            'name' => 'Manual',
            'files' => [$this->knowledgeFile('bodega.md', "# Bodega\n\nLa bodega central abre de lunes a viernes de 8 a 18 horas y recibe despachos con guía.")],
        ])->assertSessionHasNoErrors();

        return $collection->refresh();
    }

    public function test_diagnostics_change_nothing_and_activation_provisions_the_external_schema(): void
    {
        $connection = $this->createConnection();

        $this->assertSame(ConnectionStatus::Pending, $connection->status);
        $this->assertSame('rag_'.$connection->id, $connection->databaseConnection());
        // Stored encrypted, never sent back...
        $this->assertStringNotContainsString((string) config('database.connections.pgsql.password'), (string) DB::table('rag_connections')->where('id', $connection->id)->value('credentials'));

        $this->actingAs($this->owner)->get(route('admin.knowledge.connections.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/knowledge/Connections')
                ->where('connections.1.kind', 'pgsql')
                ->where('connections.1.credentials.password', '••••••••')
                ->where('connections.1.config.schema', self::SCHEMA));

        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.diagnose', $connection))->assertSessionHasNoErrors();

        $checks = collect($connection->refresh()->diagnostics['checks'])->keyBy('key');
        $this->assertSame('ok', $checks['connect']['status']);
        $this->assertSame('ok', $checks['pgvector']['status']);
        $this->assertSame('skipped', $checks['schema']['status']);
        $this->assertFalse((bool) DB::scalar('SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = ?)', [self::SCHEMA]));

        $this->activate($connection);

        $this->assertTrue($this->schemaTable('rag_chunks'));
        $this->assertSame('external_pgsql', app(Knowledge::class)->mode()->value);
        $this->assertContains('ingest', $connection->capabilities);
        $this->assertSame('ok', collect($connection->diagnostics['checks'])->firstWhere('key', 'smoke')['status']);
    }

    public function test_documents_are_indexed_and_searched_in_the_external_database(): void
    {
        $connection = $this->createConnection();
        $this->activate($connection);
        $collection = $this->indexOnExternal($connection);

        $this->assertSame($connection->id, $collection->connection_id);
        $this->assertSame(1, $collection->documents()->where('status', 'indexed')->count());
        $this->assertGreaterThan(0, (int) DB::scalar('SELECT count(*) FROM '.self::SCHEMA.'.rag_chunks WHERE collection_id = ?', [$collection->id]));
        $this->assertTrue($this->schemaTable($collection->profile->table()));

        // Nothing of it in the internal data plane...
        if (DB::scalar("SELECT to_regclass('public.rag_chunks') IS NOT NULL")) {
            $this->assertSame(0, (int) DB::scalar('SELECT count(*) FROM public.rag_chunks WHERE collection_id = ?', [$collection->id]));
        }

        $this->actingAs($this->userWithRole('reader'))->post(route('admin.knowledge.lab.search'), ['query' => '¿Cuándo abre la bodega central?', 'method' => 'hybrid'])
            ->assertSessionHasNoErrors();
        $hits = data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), 'search.results.0.hits');
        $this->assertStringContainsString('lunes a viernes', $hits[0]['content']);
    }

    public function test_collections_only_use_profiles_of_their_connection(): void
    {
        $connection = $this->createConnection();
        $this->activate($connection);
        $this->simulatedModel();

        $internal = EmbeddingProfile::query()->create([
            'connection_id' => app(Knowledge::class)->internalConnection()->id,
            'name' => 'Internal',
            'provider' => 'simulated',
            'model' => 'simulated-embedding',
            'dimensions' => 256,
            'status' => EmbeddingProfile::READY,
        ]);

        $this->actingAs($this->owner)->post(route('admin.knowledge.collections.store'), ['name' => 'Mezcla', 'embedding_profile_id' => $internal->id, 'language' => 'es'])
            ->assertSessionHasErrors('embedding_profile_id');
    }

    public function test_read_only_connections_search_but_never_write(): void
    {
        $connection = $this->createConnection();
        $this->activate($connection);
        $collection = $this->indexOnExternal($connection);
        $source = Source::query()->where('collection_id', $collection->id)->sole();

        // Settings changes send the connection back to diagnostics...
        $this->actingAs($this->owner)->put(route('admin.knowledge.connections.update', $connection), [
            'label' => 'Analytics database (replica)',
            'config' => $this->payload(['read_only' => true])['config'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(ConnectionStatus::Pending, $connection->refresh()->status);
        $this->assertNotNull($connection->credentials['password'] ?? null);

        $this->activate($connection);
        $this->assertTrue($connection->isReadOnly());
        $this->assertNotContains('ingest', $connection->capabilities);
        $this->assertContains('query', $connection->capabilities);

        $this->actingAs($this->owner)->post(route('admin.knowledge.lab.search'), ['query' => 'bodega central despachos', 'method' => 'text'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('search.results.0.hits.0.title', 'Bodega');

        $this->actingAs($this->owner)->post(route('admin.knowledge.sources.sync', $source))->assertSessionHasErrors('rag');
        $this->actingAs($this->owner)->delete(route('admin.knowledge.sources.destroy', $source))->assertSessionHasErrors('rag');

        Sanctum::actingAs($this->owner, ['read', 'write']);
        $this->postJson(route('api.v1.rag.sources.sync', $source))->assertStatus(503);

        // The database itself refuses writes on this connection...
        $this->expectException(QueryException::class);
        DB::connection($connection->databaseConnection())->insert('INSERT INTO rag_chunks (index_version_id, collection_id, source_id, document_id, document_version_id, ordinal, content) VALUES (0, 0, 0, 0, 0, 0, ?)', ['x']);
    }

    public function test_secrets_are_references_or_encrypted_and_failures_hide_them(): void
    {
        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.store'), $this->payload(credentials: ['password' => 'env:DB_PASSWORD']))
            ->assertSessionHasErrors('credentials.password');

        putenv('STARTER_RAG_SECRET_EXTERNAL='.config('database.connections.pgsql.password'));
        $_ENV['STARTER_RAG_SECRET_EXTERNAL'] = config('database.connections.pgsql.password');

        try {
            $this->actingAs($this->owner)->post(route('admin.knowledge.connections.store'), $this->payload(credentials: ['password' => 'env:STARTER_RAG_SECRET_EXTERNAL']))
                ->assertSessionHasNoErrors();
            $connection = RagConnection::query()->where('kind', 'pgsql')->sole();

            $this->actingAs($this->owner)->post(route('admin.knowledge.connections.diagnose', $connection));
            $this->assertSame('ok', collect($connection->refresh()->diagnostics['checks'])->firstWhere('key', 'connect')['status']);

            $this->actingAs($this->owner)->get(route('admin.knowledge.connections.index'))
                ->assertInertia(fn (AssertableInertia $page) => $page->where('connections.1.credentials.password', 'env:STARTER_RAG_SECRET_EXTERNAL'));
        } finally {
            putenv('STARTER_RAG_SECRET_EXTERNAL');
            unset($_ENV['STARTER_RAG_SECRET_EXTERNAL']);
        }

        $this->actingAs($this->owner)->put(route('admin.knowledge.connections.update', $connection), [
            'label' => 'Wrong password',
            'config' => $this->payload()['config'],
            'credentials' => ['password' => 'definitely-wrong-password'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('admin.knowledge.connections.diagnose', $connection));

        $connect = collect($connection->refresh()->diagnostics['checks'])->firstWhere('key', 'connect');
        $this->assertSame('error', $connect['status']);
        $this->assertStringNotContainsString('definitely-wrong-password', json_encode($connection->diagnostics) ?: '');
    }

    public function test_connections_are_managed_with_settings_permission_and_kept_while_used(): void
    {
        $this->actingAs($this->userWithRole('editor'))->get(route('admin.knowledge.connections.index'))->assertForbidden();
        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.connections.store'), $this->payload())->assertForbidden();

        $connection = $this->createConnection();
        $this->activate($connection);

        $this->actingAs($this->owner)->delete(route('admin.knowledge.connections.destroy', $connection))->assertSessionHasErrors('connection');
        $this->actingAs($this->owner)->delete(route('admin.knowledge.connections.destroy', app(Knowledge::class)->internalConnection()))->assertSessionHasErrors('connection');

        Sanctum::actingAs($this->owner, ['read']);
        $listed = collect($this->getJson(route('api.v1.rag.connections.index'))->assertOk()->json('data'))->keyBy('kind');
        $this->assertTrue($listed['pgsql']['active']);
        $this->assertFalse($listed['internal']['active']);
        $this->assertSame('••••••••', $listed['pgsql']['credentials']['password']);
        $this->assertStringNotContainsString((string) config('database.connections.pgsql.password'), $this->getJson(route('api.v1.rag.connections.index'))->getContent() ?: '');

        Sanctum::actingAs($this->owner, ['read', 'write']);
        $this->postJson(route('api.v1.rag.connections.diagnose', $connection))->assertOk()->assertJsonPath('data.passed', true);
        $this->postJson(route('api.v1.rag.connections.activate', $connection))->assertOk()->assertJsonPath('data.status.mode', 'external_pgsql');

        $this->actingAs($this->owner)->post(route('admin.knowledge.deactivate'));
        $this->actingAs($this->owner)->delete(route('admin.knowledge.connections.destroy', $connection))->assertSessionHasNoErrors();
        $this->assertModelMissing($connection);
    }
}
