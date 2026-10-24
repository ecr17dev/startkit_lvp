<?php

namespace Tests\Feature\Knowledge;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\DocumentVersion;
use Starter\Knowledge\Models\EvaluationRun;
use Starter\Knowledge\Models\EvaluationSet;
use Starter\Knowledge\Models\RetrievalHit;
use Starter\Knowledge\Models\RetrievalRun;
use Starter\Knowledge\Pipeline\Retention;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

/**
 * Operation and quality of the knowledge base: evaluation sets, human
 * judgements, answer feedback, the dashboard and retention.
 */
class QualityTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    protected Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->collection = $this->knowledgeCollection();

        $this->actingAs($this->userWithRole('owner'))->post(route('admin.knowledge.sources.store', $this->collection), [
            'type' => 'upload',
            'name' => 'Políticas',
            'files' => [
                $this->knowledgeFile('viajes.md', "# Política de viajes\n\n## Viáticos\n\nEl viático diario nacional es de 45.000 pesos y se rinde en cinco días hábiles con boletas.\n\n## Reservas\n\nLos pasajes se compran con la agencia autorizada con diez días de anticipación."),
                $this->knowledgeFile('vacaciones.md', "# Vacaciones\n\nCada colaborador tiene quince días hábiles de vacaciones al año. Se solicitan en el portal interno."),
            ],
        ])->assertSessionHasNoErrors();
    }

    protected function document(string $title): Document
    {
        return Document::query()->where('title', $title)->sole();
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

    public function test_an_evaluation_set_measures_recall_mrr_and_hit_rate(): void
    {
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.store'), [
            'name' => 'Preguntas frecuentes',
            'collection_ids' => [$this->collection->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $set = EvaluationSet::query()->sole();

        // A set without questions cannot run...
        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.run', $set))->assertSessionHasErrors('set');

        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.items.store', $set), [
            'question' => '¿Cuánto es el viático diario nacional?',
            'expected_document_ids' => [$this->document('Política de viajes')->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.items.store', $set), [
            'question' => '¿Cuántos días de vacaciones tengo al año?',
            'expected_document_ids' => [$this->document('Vacaciones')->id],
        ])->assertSessionHasNoErrors();

        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.run', $set), ['label' => 'híbrido k=3', 'method' => 'hybrid', 'top_k' => 3])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = EvaluationRun::query()->sole();
        $this->assertSame(EvaluationRun::COMPLETED, $run->status);
        $this->assertEquals(['method' => 'hybrid', 'top_k' => 3], $run->settings);
        $this->assertSame(2, $run->metrics['questions']);
        $this->assertEquals(1.0, $run->metrics['recall_at_k']);
        $this->assertEquals(1.0, $run->metrics['hit_rate']);
        $this->assertEquals(1.0, $run->metrics['mrr']);
        $this->assertEquals(0.0, $run->metrics['no_result_rate']);
        $this->assertSame('0.0000000000', $run->metrics['cost']);
        $this->assertSame(2, $run->results()->count());
        $this->assertSame(2, RetrievalRun::query()->where('surface', 'evaluation')->count());

        $this->actingAs($editor)->get(route('admin.knowledge.evaluations.show', $set))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/knowledge/evaluations/Show')
                ->has('items', 2)
                ->where('current.id', $run->id)
                ->where('current.metrics.questions', 2)
                ->has('documents', 2));

        $this->actingAs($editor)->get(route('admin.knowledge.evaluations.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('sets.0.items', 2)->where('sets.0.latest.status', 'completed'));
    }

    public function test_expected_documents_must_belong_to_the_set_and_be_readable(): void
    {
        $editor = $this->userWithRole('editor');
        $other = $this->knowledgeCollection(['slug' => 'otra', 'name' => 'Otra'], $this->collection->profile);
        $set = EvaluationSet::query()->create(['name' => 'Set', 'collection_ids' => [$other->id], 'created_by' => $editor->id]);

        $this->actingAs($editor)->post(route('admin.knowledge.evaluations.items.store', $set), [
            'question' => 'viático',
            'expected_document_ids' => [$this->document('Política de viajes')->id],
        ])->assertSessionHasErrors('expected_document_ids');

        // Collections the actor cannot see cannot be evaluated...
        $restricted = $this->knowledgeCollection(['slug' => 'privada', 'name' => 'Privada', 'acl' => ['role:owner']], $this->collection->profile);
        $this->actingAs($this->userWithRole('reader'))->post(route('admin.knowledge.evaluations.store'), [
            'name' => 'Privada',
            'collection_ids' => [$restricted->id],
        ])->assertSessionHasErrors('collection_ids');
    }

    public function test_evaluations_and_the_dashboard_require_their_permissions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.knowledge.evaluations.index'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.knowledge.dashboard'))->assertForbidden();
        $this->actingAs($this->userWithRole('reader'))->get(route('admin.knowledge.dashboard'))->assertForbidden();
    }

    public function test_lab_passages_are_judged_by_whoever_ran_the_search(): void
    {
        $reader = $this->userWithRole('reader');

        $this->actingAs($reader)->post(route('admin.knowledge.lab.search'), ['query' => '¿Cuánto es el viático diario?', 'method' => 'hybrid'])
            ->assertSessionHasNoErrors();

        $hitId = data_get(app('session.store')->get(SessionKey::FLASH_DATA, []), 'search.results.0.hits.0.hit_id');
        $this->assertIsInt($hitId);

        $this->actingAs($reader)->post(route('admin.knowledge.lab.judge', $hitId), ['judgement' => 1])->assertSessionHasNoErrors();
        $hit = RetrievalHit::query()->findOrFail($hitId);
        $this->assertSame(1, $hit->judgement);
        $this->assertSame($reader->id, $hit->judged_by);

        $this->actingAs($reader)->post(route('admin.knowledge.lab.judge', $hitId), ['judgement' => 0]);
        $this->assertNull($hit->refresh()->judgement);

        // Another user's trace is not theirs to judge...
        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.lab.judge', $hitId), ['judgement' => -1])->assertNotFound();
        $this->actingAs($reader)->post(route('admin.knowledge.lab.judge', $hitId), ['judgement' => 5])->assertSessionHasErrors('judgement');
    }

    public function test_users_rate_their_grounded_answers(): void
    {
        $this->simulatedModel();
        $this->aiAgent(['rag' => ['mode' => 'always', 'collections' => [$this->collection->id], 'top_k' => 3]]);
        $user = $this->userWithRole('reader');

        $events = $this->events($this->actingAs($user)->post(route('ai.chat.stream'), ['message' => '¿Cuál es el viático diario nacional?'])->assertOk());
        $knowledge = collect($events)->firstWhere('type', 'knowledge')['knowledge'];
        $conversation = collect($events)->firstWhere('type', 'summary')['conversation_id'];

        $this->assertNotNull($knowledge['message_id']);
        $this->assertNull($knowledge['feedback']);

        $this->actingAs($this->userWithRole('editor'))->postJson(route('ai.chat.feedback', $knowledge['message_id']), ['rating' => 1])->assertNotFound();
        $this->actingAs($user)->postJson(route('ai.chat.feedback', $knowledge['message_id']), ['rating' => 3])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('ai.chat.feedback', $knowledge['message_id']), ['rating' => -1, 'note' => 'Faltó el monto internacional'])->assertNoContent();

        $run = RetrievalRun::query()->where('surface', 'chat')->sole();
        $this->assertSame(-1, $run->feedback);
        $this->assertSame('Faltó el monto internacional', $run->feedback_note);
        $this->assertNotNull($run->feedback_at);

        $this->actingAs($user)->getJson(route('ai.conversations.show', $conversation))
            ->assertJsonPath('data.messages.1.knowledge.feedback', -1)
            ->assertJsonPath('data.messages.1.knowledge.message_id', $knowledge['message_id']);

        // The dashboard reports the answer, its citations and the feedback...
        $this->actingAs($this->userWithRole('editor'))->get(route('admin.knowledge.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/knowledge/Dashboard')
                ->where('report.agents.0.answers', 1)
                ->where('report.agents.0.negative', 1)
                ->where('report.outcomes.answered', 1));
    }

    public function test_the_dashboard_derives_its_figures_from_real_events(): void
    {
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->post(route('admin.knowledge.lab.search'), ['query' => 'receta de pan amasado', 'method' => 'text']);
        $this->actingAs($editor)->post(route('admin.knowledge.lab.search'), ['query' => 'vacaciones', 'method' => 'text']);

        $this->actingAs($editor)->get(route('admin.knowledge.dashboard', ['days' => 7, 'collection' => $this->collection->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.days', 7)
                ->where('report.window.days', 7)
                ->has('report.queries_daily', 7)
                ->where('report.totals.documents', 2)
                ->where('report.totals.processed', 2)
                ->where('report.totals.indexed', 2)
                ->where('report.totals.sources', 1)
                ->where('report.totals.queries', 2)
                ->where('report.totals.no_result_rate', 0.5)
                ->where('report.documents_by_status.0.counts.indexed', 2)
                ->where('report.collections.0.documents', 2)
                ->where('report.queue.open', 0)
                ->where('report.freshness.0.source', 'Políticas')
                ->where('report.errors.by_type', [])
                ->etc());

        $this->actingAs($editor)->get(route('admin.knowledge.dashboard', ['days' => 3]))->assertSessionHasErrors('days');

        Sanctum::actingAs($editor, ['read']);
        $this->getJson(route('api.v1.rag.analytics', ['days' => 7]))
            ->assertOk()
            ->assertJsonPath('data.totals.indexed', 2)
            ->assertJsonPath('data.totals.queries', 2)
            ->assertJsonCount(7, 'data.ingestion_daily');
    }

    public function test_the_api_runs_evaluations(): void
    {
        $editor = $this->userWithRole('editor');
        $set = EvaluationSet::query()->create(['name' => 'API', 'collection_ids' => [$this->collection->id], 'created_by' => $editor->id]);
        $set->items()->create(['question' => 'pasajes con la agencia autorizada', 'expected_document_ids' => [$this->document('Política de viajes')->id]]);

        Sanctum::actingAs($editor, ['read']);
        $this->getJson(route('api.v1.rag.evaluation-sets.index'))->assertOk()->assertJsonPath('data.0.items', 1);
        $this->postJson(route('api.v1.rag.evaluate', $set), ['method' => 'text'])->assertForbidden();

        Sanctum::actingAs($editor, ['read', 'write']);
        $id = $this->postJson(route('api.v1.rag.evaluate', $set), ['method' => 'text', 'top_k' => 2])
            ->assertStatus(202)
            ->json('data.id');

        $this->getJson(route('api.v1.rag.evaluations.show', $id))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.metrics.hit_rate', 1)
            ->assertJsonPath('data.results.0.first_relevant_rank', 1);
    }

    public function test_retention_purges_old_versions_but_never_the_searchable_one(): void
    {
        $document = $this->document('Vacaciones');
        $current = $document->currentVersion;
        $this->assertNotNull($current);

        $old = DocumentVersion::query()->create([
            'document_id' => $document->id,
            'number' => 99,
            'content_hash' => str_repeat('a', 64),
            'parser' => 'markdown',
            'content' => 'Versión anterior',
            'created_at' => now()->subDays(40),
        ]);
        $current->forceFill(['created_at' => now()->subDays(40)])->save();

        // Without a retention policy every version is kept...
        $this->assertSame(0, app(Retention::class)->purge());

        $this->collection->forceFill(['retention_days' => 30])->save();
        $this->artisan('starter:rag-retention')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($current);
        $this->assertSame($current->id, $document->refresh()->current_version_id);
    }
}
