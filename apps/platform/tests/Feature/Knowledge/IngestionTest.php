<?php

namespace Tests\Feature\Knowledge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Starter\Ai\Enums\Feature;
use Starter\Ai\Models\AiRequest;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function chunks(?int $documentId = null): int
    {
        $query = DB::table('rag_chunks');

        return $documentId === null ? $query->count() : $query->where('document_id', $documentId)->count();
    }

    public function test_activation_runs_the_diagnostics_and_provisions_the_schema(): void
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->post(route('admin.knowledge.activate'), ['mode' => 'internal'])->assertRedirect();

        $knowledge = app(Knowledge::class);
        $this->assertSame(RagMode::Internal, $knowledge->mode());
        $this->assertTrue($knowledge->available());
        $this->assertTrue((bool) DB::scalar("SELECT to_regclass('rag_chunks') IS NOT NULL"));

        $checks = collect($knowledge->connection()?->diagnostics['checks'] ?? [])->pluck('status', 'key');
        $this->assertSame('ok', $checks['pgvector']);
        $this->assertSame('ok', $checks['smoke']);

        // Disabling keeps the data...
        $this->actingAs($owner)->post(route('admin.knowledge.deactivate'))->assertRedirect();
        $this->assertSame(RagMode::Disabled, $knowledge->mode());
        $this->assertTrue((bool) DB::scalar("SELECT to_regclass('rag_chunks') IS NOT NULL"));
    }

    public function test_disabled_rag_needs_no_pgvector_and_blocks_ingestion(): void
    {
        config(['starter.features.rag' => false]);

        $this->assertSame(RagMode::Disabled, app(Knowledge::class)->mode());
        $this->assertFalse((bool) DB::scalar("SELECT to_regclass('rag_chunks') IS NOT NULL"));

        $this->actingAs($this->userWithRole('owner'))->get(route('admin.knowledge.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('status.feature', false)->where('status.available', false));
    }

    public function test_markdown_text_and_pdf_files_become_searchable_chunks(): void
    {
        $collection = $this->knowledgeCollection();
        $editor = $this->userWithRole('editor');

        $markdown = "# Manual de vacaciones\n\n## Solicitud\n\nLas vacaciones se solicitan con diez días de anticipación en el portal interno.\n\n## Aprobación\n\nEl jefe directo aprueba la solicitud en un plazo de tres días hábiles.";

        $this->actingAs($editor)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload',
            'name' => 'Políticas',
            'files' => [
                $this->knowledgeFile('vacaciones.md', $markdown),
                $this->knowledgeFile('horario.txt', 'El horario de oficina es de nueve a dieciocho horas de lunes a viernes.'),
                $this->knowledgeFile('beneficios.pdf', $this->pdf(['Seguro complementario de salud para todos los colaboradores.', 'Bono de alimentacion mensual pagado con la remuneracion.'])),
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $job = RagJob::query()->where('type', JobType::SyncSource->value)->sole();
        $this->assertSame(JobStatus::Succeeded, $job->status, (string) $job->error);
        $this->assertSame(3, $job->children()->where('status', JobStatus::Succeeded->value)->count());
        $this->assertArrayHasKey('extract', $job->timings ?? []);

        $documents = Document::query()->orderBy('title')->get();
        $this->assertCount(3, $documents);
        $this->assertTrue($documents->every(fn (Document $document) => $document->status === DocumentStatus::Indexed));

        $pdf = $documents->firstWhere('mime_type', 'application/pdf');
        $this->assertSame([1, 2], array_column($pdf->currentVersion->pages ?? [], 'page'));
        $this->assertStringContainsString('Bono de alimentacion', $pdf->currentVersion->content);

        $md = $documents->firstWhere('mime_type', 'text/markdown');
        $this->assertSame('Manual de vacaciones', $md->title);
        $sections = DB::table('rag_chunks')->where('document_id', $md->id)->pluck('metadata')->map(fn ($meta) => json_decode($meta, true)['section'] ?? null)->all();
        $this->assertContains('Manual de vacaciones › Solicitud', $sections);

        // Vectors and full text are stored; the cost of every embedding call is in the ledger...
        $this->assertSame($this->chunks(), DB::table('rag_embeddings_p'.$collection->embedding_profile_id)->count());
        $this->assertSame(1, DB::table('rag_chunks')->whereRaw("search @@ websearch_to_tsquery('spanish', 'solicitud anticipación')")->count());
        $this->assertGreaterThanOrEqual(3, AiRequest::query()->where('feature', Feature::Embedding->value)->count());
        $this->assertTrue($job->refresh()->cost_known);

        $version = $collection->refresh()->activeVersion;
        $this->assertSame(3, $version?->documents);
        $this->assertSame($this->chunks(), $version?->chunks);
    }

    public function test_resyncs_are_idempotent_and_changes_replace_the_chunks(): void
    {
        $collection = $this->knowledgeCollection();
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'Docs', 'files' => [$this->knowledgeFile('guia.md', "# Guía\n\nPrimera versión del procedimiento de compras.")],
        ]);

        $source = Source::query()->sole();
        $document = Document::query()->sole();
        $before = $this->chunks($document->id);

        // Same content: nothing new...
        $this->actingAs($editor)->post(route('admin.knowledge.sources.sync', $source))->assertRedirect();
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame($before, $this->chunks($document->id));

        // Same name, new content: a new version replaces the chunks...
        $this->actingAs($editor)->post(route('admin.knowledge.sources.files', $source), [
            'files' => [$this->knowledgeFile('guia.md', "# Guía\n\nSegunda versión: las compras sobre un millón requieren dos cotizaciones.")],
        ])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(2, $document->versions()->count());
        $this->assertSame(1, Document::query()->count());
        $this->assertSame($document->latest_version_id, $document->current_version_id);
        $this->assertSame(0, DB::table('rag_chunks')->where('document_id', $document->id)->where('content', 'like', '%Primera versión%')->count());
        $this->assertSame(1, DB::table('rag_chunks')->where('document_id', $document->id)->where('content', 'like', '%dos cotizaciones%')->count());
    }

    public function test_unreadable_and_disguised_files_are_reported(): void
    {
        $collection = $this->knowledgeCollection();
        $editor = $this->userWithRole('editor');

        // Text with a .pdf extension is rejected by content...
        $this->actingAs($editor)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'Falsos', 'files' => [$this->knowledgeFile('falso.pdf', 'no soy un pdf')],
        ])->assertSessionHasErrors();

        // A PDF without extractable text (scanned) is "empty", never indexed...
        $this->actingAs($editor)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'Escaneos', 'files' => [$this->knowledgeFile('escaneo.pdf', $this->pdf(['']))],
        ])->assertSessionHasNoErrors();

        $document = Document::query()->sole();
        $this->assertSame(DocumentStatus::Empty, $document->status);
        $this->assertStringContainsString('OCR', (string) $document->error);
        $this->assertSame(0, $this->chunks());
    }

    public function test_large_ingestions_wait_for_a_confirmed_budget(): void
    {
        config(['starter.rag.indexing.confirm_above_tokens' => 10]);
        $collection = $this->knowledgeCollection();
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'Grande', 'files' => [$this->knowledgeFile('grande.txt', str_repeat('Contenido extenso del manual operativo. ', 20))],
        ]);

        $job = RagJob::query()->where('type', JobType::SyncSource->value)->sole();
        $this->assertSame(JobStatus::Paused, $job->status);
        $this->assertGreaterThan(10, $job->estimate['tokens']);
        $this->assertSame('0.0000000000', $job->estimate['cost']);
        $this->assertSame(0, $this->chunks());
        $this->assertSame(DocumentStatus::Pending, Document::query()->sole()->status);

        $this->actingAs($owner)->post(route('admin.knowledge.jobs.resume', $job), ['max_cost' => 1])->assertSessionHasNoErrors();

        $this->assertSame(JobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame('1.0000000000', (string) $job->max_cost);
        $this->assertGreaterThan(0, $this->chunks());
    }

    public function test_deleting_a_document_removes_it_from_queries_and_purges_the_original(): void
    {
        $collection = $this->knowledgeCollection();
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'Docs', 'files' => [$this->knowledgeFile('a.md', "# A\n\nContenido de prueba para borrar.")],
        ]);

        $document = Document::query()->with('currentVersion')->sole();
        $path = (string) $document->currentVersion?->storage_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($owner)->delete(route('admin.knowledge.documents.destroy', $document))->assertRedirect();

        $this->assertSame(0, $this->chunks());
        $this->assertSame(DocumentStatus::Deleted, $document->refresh()->status);
        $this->assertSame(0, $document->versions()->count());
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(JobStatus::Succeeded, RagJob::query()->where('type', JobType::PurgeDocument->value)->sole()->status);
    }

    public function test_readers_cannot_manage_sources(): void
    {
        $collection = $this->knowledgeCollection();

        $this->actingAs($this->userWithRole('reader'))->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'upload', 'name' => 'X', 'files' => [$this->knowledgeFile('a.txt', 'hola mundo de prueba')],
        ])->assertForbidden();
    }
}
