<?php

namespace Tests\Feature\Knowledge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Starter\Knowledge\Enums\IndexVersionStatus;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Ingestion\Chunker;
use Starter\Knowledge\Jobs\ProcessRagJob;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\IndexVersion;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Starter\Knowledge\Store\Provisioner;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function seedDocuments(Collection $collection, int $count = 2): void
    {
        $files = [];

        for ($i = 1; $i <= $count; $i++) {
            $files[] = $this->knowledgeFile("doc{$i}.md", "# Documento {$i}\n\nEl procedimiento número {$i} describe cómo solicitar reembolsos de gastos.");
        }

        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.sources.store', $collection), ['type' => 'upload', 'name' => 'Docs', 'files' => $files])->assertSessionHasNoErrors();
    }

    public function test_the_chunker_keeps_sections_offsets_overlap_and_pages(): void
    {
        $text = "# Manual\n\n## Viajes\n\n".str_repeat('Los viajes nacionales se reservan con la agencia autorizada. ', 30)."\n\n## Gastos\n\nLos gastos se rinden en cinco días.";
        $chunks = app(Chunker::class)->chunk($text, ['target_tokens' => 100, 'overlap_tokens' => 20, 'max_tokens' => 150], [['page' => 1, 'start' => 0, 'end' => 900], ['page' => 2, 'start' => 900, 'end' => strlen($text)]]);

        $this->assertGreaterThan(3, count($chunks));
        $this->assertSame('Manual › Viajes', $chunks[0]['metadata']['section']);
        $this->assertSame('Manual › Gastos', end($chunks)['metadata']['section']);
        $this->assertStringContainsString('cinco días', end($chunks)['content']);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(150 + 25, $chunk['token_count']);
            $this->assertSame(trim(substr($text, $chunk['metadata']['start'], $chunk['metadata']['end'] - $chunk['metadata']['start'])), $chunk['content']);
        }

        // Consecutive chunks of a section overlap...
        $this->assertLessThan($chunks[0]['metadata']['end'], $chunks[1]['metadata']['start']);
        $this->assertSame(1, $chunks[0]['metadata']['page_start']);
        $this->assertSame(2, end($chunks)['metadata']['page_end']);
    }

    public function test_index_strategies_follow_dimensions_and_pgvector(): void
    {
        $provisioner = app(Provisioner::class);

        $this->assertSame(['index' => 'hnsw', 'exact' => false], $provisioner->indexStrategy(1536, '0.6.0'));
        $this->assertSame(['index' => null, 'exact' => true], $provisioner->indexStrategy(3072, '0.6.0'));
        $this->assertSame(['index' => 'hnsw_halfvec', 'exact' => false], $provisioner->indexStrategy(3072, '0.8.1'));
        $this->assertSame(['index' => null, 'exact' => true], $provisioner->indexStrategy(8000, '0.8.1'));
        $this->assertSame(['index' => null, 'exact' => true], $provisioner->indexStrategy(768, '0.4.4'));
    }

    public function test_reindex_builds_a_new_version_and_publishes_it_when_complete(): void
    {
        $collection = $this->knowledgeCollection();
        $this->seedDocuments($collection);

        $old = $collection->refresh()->activeVersion;
        $this->assertNotNull($old);
        $oldChunks = DB::table('rag_chunks')->where('index_version_id', $old->id)->count();

        // New profile (another vector space) and chunking...
        $profile = $this->embeddingProfile(128);
        $collection->forceFill(['embedding_profile_id' => $profile->id, 'chunking' => ['target_tokens' => 200, 'overlap_tokens' => 0, 'max_tokens' => 400]])->save();

        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.collections.reindex', $collection))->assertSessionHasNoErrors();

        $job = RagJob::query()->where('type', JobType::Reindex->value)->sole();
        $this->assertSame(JobStatus::Succeeded, $job->status, (string) $job->error);

        $new = $collection->refresh()->activeVersion;
        $this->assertNotSame($old->id, $new?->id);
        $this->assertSame($profile->id, $new?->embedding_profile_id);
        $this->assertSame(IndexVersionStatus::Superseded, $old->refresh()->status);
        $this->assertNotNull($old->purged_at);
        $this->assertSame(0, DB::table('rag_chunks')->where('index_version_id', $old->id)->count());
        $this->assertGreaterThan(0, $oldChunks);
        $this->assertSame(2, $new?->documents);
        $this->assertSame(DB::table('rag_chunks')->where('index_version_id', $new?->id)->count(), DB::table('rag_embeddings_p'.$profile->id)->count());
    }

    public function test_a_reindex_keeps_the_active_version_until_it_completes(): void
    {
        $collection = $this->knowledgeCollection();
        $this->seedDocuments($collection);
        $active = $collection->refresh()->activeVersion;

        Queue::fake();
        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.collections.reindex', $collection));

        $building = IndexVersion::query()->where('status', IndexVersionStatus::Building->value)->sole();
        $this->assertSame($active?->id, $collection->refresh()->active_index_version_id);
        $this->assertNotSame($active?->id, $building->id);

        // A second reindex is refused while one is building...
        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.collections.reindex', $collection))->assertSessionHasErrors('collection');

        // Cancelling discards the building version; the active one stays...
        $job = RagJob::query()->where('type', JobType::Reindex->value)->sole();
        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.jobs.cancel', $job))->assertSessionHasNoErrors();
        $this->assertSame(JobStatus::Cancelled, $job->refresh()->status);
        Queue::assertPushed(ProcessRagJob::class);
    }

    public function test_stale_jobs_are_recovered_by_the_scheduler_task(): void
    {
        $collection = $this->knowledgeCollection();
        $source = Source::query()->create(['collection_id' => $collection->id, 'type' => 'upload', 'name' => 'S', 'config' => ['files' => []], 'status' => 'active']);
        $job = RagJob::query()->create(['type' => JobType::SyncSource, 'status' => JobStatus::Running, 'collection_id' => $collection->id, 'source_id' => $source->id, 'attempts' => 1, 'heartbeat_at' => now()->subHour()]);

        Queue::fake();
        $this->artisan('starter:rag-maintain')->assertSuccessful();

        $this->assertSame(JobStatus::Retrying, $job->refresh()->status);
        Queue::assertPushed(ProcessRagJob::class, fn (ProcessRagJob $queued) => $queued->ragJobId === $job->id);
    }

    public function test_scheduled_syncs_are_dispatched_once(): void
    {
        config(['starter.rag.indexing.automatic' => true]);
        $collection = $this->knowledgeCollection();
        Source::query()->create(['collection_id' => $collection->id, 'type' => 'upload', 'name' => 'S', 'config' => ['files' => []], 'status' => 'active', 'schedule' => 'daily', 'next_sync_at' => now()->subMinute()]);

        Queue::fake();
        $this->artisan('starter:rag-maintain')->assertSuccessful();
        $this->artisan('starter:rag-maintain')->assertSuccessful();

        $this->assertSame(1, RagJob::query()->where('trigger', 'schedule')->count());
        $this->assertTrue(Source::query()->sole()->next_sync_at?->isFuture());
    }

    public function test_access_list_changes_reach_the_stored_chunks(): void
    {
        $collection = $this->knowledgeCollection();
        $this->seedDocuments($collection, 1);
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->put(route('admin.knowledge.collections.update', $collection), [
            'name' => $collection->name,
            'embedding_profile_id' => $collection->embedding_profile_id,
            'language' => 'es',
            'chunking' => $collection->chunking,
            'search' => $collection->search,
            'acl' => ['role:admin', 'role:owner'],
        ])->assertSessionHasNoErrors();

        $acl = DB::table('rag_chunks')->where('document_id', Document::query()->sole()->id)->value('acl');
        $this->assertSame('{role:admin,role:owner}', $acl);

        $this->actingAs($owner)->put(route('admin.knowledge.collections.update', $collection), [
            'name' => $collection->name, 'embedding_profile_id' => $collection->embedding_profile_id, 'language' => 'es',
            'chunking' => $collection->chunking, 'search' => $collection->search, 'acl' => ['role:ghost'],
        ])->assertSessionHasErrors('acl.0');
    }

    public function test_documents_are_isolated_by_their_access_list(): void
    {
        $collection = $this->knowledgeCollection(['acl' => ['role:editor', 'role:owner']]);
        $this->seedDocuments($collection, 1);
        $document = Document::query()->sole();

        $this->actingAs($this->userWithRole('editor'))->get(route('admin.knowledge.documents.show', $document))->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/knowledge/documents/Show')->has('chunks', 1));

        $this->actingAs($this->userWithRole('reader'))->get(route('admin.knowledge.documents.show', $document))->assertForbidden();
        $this->actingAs($this->userWithRole('reader'))->get(route('admin.knowledge.documents.original', $document))->assertForbidden();
        $this->actingAs($this->userWithRole('reader'))->get(route('admin.knowledge.collections.show', $collection))->assertForbidden();
    }
}
