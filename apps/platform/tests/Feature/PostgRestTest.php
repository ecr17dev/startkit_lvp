<?php

namespace Tests\Feature;

use App\PostgRest\JwtSigner;
use App\PostgRest\PostgRest;
use App\PostgRest\SchemaInstaller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Starter\Administration\Health\HealthChecker;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Support\Principals;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

/**
 * Optional PostgREST: signed short JWTs, the api schema filtering by their
 * claims (real PostgreSQL, the way PostgREST runs each request), minimal
 * roles, the server to server client and the feature flag.
 */
class PostgRestTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected const SECRET = 'test-postgrest-secret-with-32-characters!';

    protected const URL = 'http://postgrest.test';

    protected function enable(bool $directTokens = false): void
    {
        config([
            'starter.features.postgrest' => true,
            'starter.postgrest.url' => self::URL,
            'starter.postgrest.jwt_secret' => self::SECRET,
            'starter.postgrest.direct_tokens' => $directTokens,
        ]);
    }

    /**
     * Rows visible through a view with the given JWT claims (as PostgREST
     * sets them), inside a savepoint that is rolled back.
     *
     * @param  array<string, mixed>|null  $claims
     * @return list<object>
     */
    protected function asClaims(?array $claims, string $sql, ?string $role = null): array
    {
        DB::beginTransaction();

        try {
            if ($role !== null) {
                DB::statement('SET LOCAL ROLE '.$role);
            }

            if ($claims !== null) {
                DB::select("SELECT set_config('request.jwt.claims', ?, true)", [json_encode($claims)]);
            }

            return DB::select($sql);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * A collection with one public and one restricted indexed document.
     *
     * @param  list<string>  $acl
     */
    protected function knowledge(array $acl = ['everyone']): Collection
    {
        config(['starter.features.rag' => true]);
        $collection = $this->knowledgeCollection(['acl' => $acl]);
        $source = Source::query()->create(['collection_id' => $collection->id, 'type' => 'upload', 'name' => 'Manual', 'config' => ['files' => []]]);

        foreach (['Public guide' => null, 'Board minutes' => ['role:owner']] as $title => $documentAcl) {
            Document::query()->create([
                'collection_id' => $collection->id,
                'source_id' => $source->id,
                'external_id' => $title,
                'external_hash' => hash('sha256', $title),
                'title' => $title,
                'status' => 'indexed',
                'acl' => $documentAcl,
                'indexed_at' => now(),
            ]);
        }

        app(SchemaInstaller::class)->install();

        return $collection;
    }

    public function test_the_jwt_carries_the_identity_and_principals_of_the_user(): void
    {
        $this->enable();
        $reader = $this->userWithRole('reader');
        $postgrest = app(PostgRest::class);

        $token = $postgrest->token($reader);
        $claims = $postgrest->signer()->verify($token);

        $this->assertNotNull($claims);
        $this->assertSame('starter_postgrest_user', $claims['role']);
        $this->assertSame((string) $reader->id, $claims['sub']);
        $this->assertSame(Principals::for($reader), $claims['principals']);
        $this->assertLessThanOrEqual(time() + 300, $claims['exp']);

        // Tampering or another secret invalidates it...
        $parts = explode('.', $token);
        $forged = $parts[0].'.'.rtrim(strtr(base64_encode((string) json_encode([...$claims, 'principals' => ['permission:rag.collections.manage']])), '+/', '-_'), '=').'.'.$parts[2];
        $this->assertNull($postgrest->signer()->verify($forged));
        $this->assertNull((new JwtSigner(str_repeat('x', 40)))->verify($token));
        $this->assertFalse((new JwtSigner('short'))->configured());
    }

    public function test_the_api_schema_only_returns_what_the_claims_may_read(): void
    {
        $collection = $this->knowledge();
        $reader = $this->userWithRole('reader');
        $readerClaims = ['sub' => (string) $reader->id, 'principals' => Principals::for($reader)];

        $collections = $this->asClaims($readerClaims, 'SELECT slug, documents FROM api.knowledge_collections');
        $this->assertSame([$collection->slug], array_column($collections, 'slug'));
        $this->assertSame(2, (int) $collections[0]->documents);

        // The restricted document follows its own access list...
        $documents = $this->asClaims($readerClaims, 'SELECT title FROM api.knowledge_documents ORDER BY title');
        $this->assertSame(['Public guide'], array_column($documents, 'title'));

        $owner = $this->userWithRole('owner');
        $this->assertCount(2, $this->asClaims(['principals' => Principals::for($owner)], 'SELECT id FROM api.knowledge_documents'));

        // Without the permission, with no claims or with an empty list, nothing...
        $this->assertSame([], $this->asClaims(['principals' => ['everyone', 'permission:rag.collections.read']], 'SELECT id FROM api.knowledge_documents'));
        $this->assertSame([], $this->asClaims(null, 'SELECT id FROM api.knowledge_collections'));
        $this->assertSame([], $this->asClaims(['principals' => 'everyone'], 'SELECT id FROM api.knowledge_collections'));

        $whoami = $this->asClaims($readerClaims, 'SELECT api.whoami() AS who');
        $this->assertSame((string) $reader->id, json_decode($whoami[0]->who, true)['user_id']);
    }

    public function test_private_collections_are_hidden_from_other_principals(): void
    {
        $this->knowledge(['role:editor']);
        $reader = $this->userWithRole('reader');

        $this->assertSame([], $this->asClaims(['principals' => Principals::for($reader)], 'SELECT id FROM api.knowledge_collections'));
        $this->assertSame([], $this->asClaims(['principals' => Principals::for($reader)], 'SELECT id FROM api.knowledge_documents'));
        $this->assertCount(1, $this->asClaims(['principals' => Principals::for($this->userWithRole('editor'))], 'SELECT id FROM api.knowledge_collections'));
    }

    public function test_the_roles_only_reach_the_published_objects(): void
    {
        $installer = app(SchemaInstaller::class);
        [, $anon, $user] = $installer->roles();
        $canCreate = (bool) DB::scalar('SELECT rolcreaterole OR rolsuper FROM pg_roles WHERE rolname = current_user');
        $member = $installer->rolesExist() && (bool) DB::scalar("SELECT pg_has_role(current_user, ?, 'MEMBER') AND pg_has_role(current_user, ?, 'MEMBER')", [$user, $anon]);

        if (! $canCreate && ! $member) {
            $this->markTestSkipped('The test database role can neither create the PostgREST roles nor switch to them.');
        }

        $this->knowledge();
        $result = $installer->install($canCreate ? 'Test-authenticator-2026' : null);
        $this->assertTrue($result['grants'], json_encode($result) ?: '');

        $status = $installer->status();
        $this->assertTrue($status['user_can_select']);
        $this->assertFalse($status['anon_can_select']);

        $reader = $this->userWithRole('reader');
        $this->assertCount(1, $this->asClaims(['principals' => Principals::for($reader)], 'SELECT id FROM api.knowledge_documents', $user));

        foreach ([[$user, 'SELECT count(*) FROM users'], [$user, 'SELECT count(*) FROM login_challenges'], [$user, 'SELECT count(*) FROM rag_documents'], [$anon, 'SELECT count(*) FROM api.knowledge_collections']] as [$role, $sql]) {
            try {
                $this->asClaims(['principals' => ['permission:rag.collections.manage']], $sql, $role);
                $this->fail("{$role} could run: {$sql}");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('permission denied', $exception->getMessage());
            }
        }
    }

    public function test_the_status_page_tests_through_postgrest_with_a_signed_identity(): void
    {
        $this->enable();
        $this->knowledge();
        $owner = $this->userWithRole('owner');

        Http::fake([
            self::URL.'/rpc/whoami' => fn (Request $request) => Http::response(['role' => 'starter_postgrest_user', 'user_id' => (string) $owner->id, 'principals' => ['everyone']]),
            self::URL.'/knowledge_collections*' => Http::response([['id' => 1, 'slug' => 'handbook', 'name' => 'Handbook', 'documents' => 2]]),
        ]);

        $this->actingAs($this->userWithRole('editor'))->get(route('admin.integrations.postgrest'))->assertForbidden();

        $this->actingAs($owner)->get(route('admin.integrations.postgrest'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/integrations/PostgRest')
                ->where('enabled', true)
                ->where('secretConfigured', true)
                ->where('install.schema', true)
                ->has('install.objects', 3));

        $this->actingAs($owner)->post(route('admin.integrations.postgrest.test'))->assertRedirect();
        $result = app('session.store')->get(SessionKey::FLASH_DATA, [])['postgrest'];
        $this->assertTrue($result['ok']);
        $this->assertSame('handbook', $result['collections'][0]['slug']);

        Http::assertSent(function (Request $request) use ($owner) {
            $claims = app(PostgRest::class)->signer()->verify(substr($request->header('Authorization')[0] ?? '', 7));

            return str_starts_with($request->url(), self::URL.'/knowledge_collections')
                && $claims !== null
                && $claims['sub'] === (string) $owner->id
                && in_array('role:owner', $claims['principals'], true);
        });
    }

    public function test_disabled_postgrest_is_never_required_and_tokens_are_opt_in(): void
    {
        $owner = $this->userWithRole('owner');
        $check = fn () => collect(app(HealthChecker::class)->run())->firstWhere('name', 'postgrest');

        $this->assertFalse($check()->required);
        $this->assertTrue(app(HealthChecker::class)->ready(app(HealthChecker::class)->run()));

        Sanctum::actingAs($owner, ['read']);
        $this->postJson(route('api.v1.postgrest.token'))->assertNotFound();

        $this->enable();
        $this->postJson(route('api.v1.postgrest.token'))->assertNotFound();

        // Enabled, it is required: a failing PostgREST blocks readiness...
        Http::fake([self::URL.'/rpc/whoami' => Http::response(['message' => 'down'], 503)]);
        $this->assertTrue($check()->required);
        $this->assertSame('failed', $check()->status);
        $this->assertFalse(app(HealthChecker::class)->ready(app(HealthChecker::class)->run()));

        $this->enable(directTokens: true);
        $token = $this->postJson(route('api.v1.postgrest.token'))->assertOk()->json('data.token');
        $claims = app(PostgRest::class)->signer()->verify($token);
        $this->assertSame((string) $owner->id, $claims['sub'] ?? null);
        $this->assertDatabaseHas('audit_events', ['action' => 'postgrest.token_issued', 'actor_id' => $owner->id]);
    }
}
