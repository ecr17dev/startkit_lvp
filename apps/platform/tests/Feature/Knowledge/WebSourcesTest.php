<?php

namespace Tests\Feature\Knowledge;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Starter\Knowledge\Enums\DocumentStatus;
use Starter\Knowledge\Enums\JobStatus;
use Starter\Knowledge\Enums\JobType;
use Starter\Knowledge\Ingestion\Fetch\BlockedUrlException;
use Starter\Knowledge\Ingestion\Fetch\HostResolver;
use Starter\Knowledge\Ingestion\Fetch\SafeFetcher;
use Starter\Knowledge\Models\Document;
use Starter\Knowledge\Models\RagJob;
use Starter\Knowledge\Models\Source;
use Tests\Concerns\CreatesAiCatalog;
use Tests\Concerns\CreatesKnowledge;
use Tests\TestCase;

class WebSourcesTest extends TestCase
{
    use CreatesAiCatalog, CreatesKnowledge, RefreshDatabase;

    /**
     * @var array<string, list<string>>
     */
    protected array $dns = [
        'docs.example.com' => ['93.184.216.34'],
        'evil.example.com' => ['10.0.0.5'],
        'rebind.example.com' => ['93.184.216.35', '127.0.0.1'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $dns = &$this->dns;
        $this->app->instance(HostResolver::class, new class($dns) extends HostResolver
        {
            /**
             * @param  array<string, list<string>>  $dns
             */
            public function __construct(protected array &$dns) {}

            public function resolve(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->dns[$host] ?? []);
            }
        });
    }

    protected function page(string $title, string $body, array $links = []): string
    {
        $anchors = implode('', array_map(fn ($link) => "<a href=\"{$link}\">link</a>", $links));

        return "<html><head><title>{$title}</title><script>alert('x')</script></head><body><nav>Menú principal</nav><main><h1>{$title}</h1><p>{$body}</p>{$anchors}</main><footer>Pie</footer></body></html>";
    }

    public function test_the_ssrf_guard_blocks_private_destinations(): void
    {
        $fetcher = app(SafeFetcher::class);

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data', 'http://[::1]/', 'http://10.1.2.3/', 'http://100.64.0.1/', 'ftp://docs.example.com/', 'http://docs.example.com:8080/',
            'http://user:pass@docs.example.com/', 'http://evil.example.com/', 'http://rebind.example.com/', 'http://localhost/', 'http://metadata.google.internal/', 'http://[::ffff:127.0.0.1]/'] as $url) {
            try {
                $fetcher->validate($url);
                $this->fail("{$url} was not blocked");
            } catch (BlockedUrlException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(['docs.example.com', 443, '93.184.216.34'], $fetcher->validate('https://docs.example.com/guia'));
        $this->assertTrue(SafeFetcher::isPublic('8.8.8.8'));
        $this->assertFalse(SafeFetcher::isPublic('192.168.1.10'));
        $this->assertFalse(SafeFetcher::isPublic('fd00::1'));
    }

    public function test_redirects_are_validated_hop_by_hop(): void
    {
        Http::fake([
            'https://docs.example.com/salida' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        ]);

        $this->expectException(BlockedUrlException::class);

        app(SafeFetcher::class)->fetch('https://docs.example.com/salida');
    }

    public function test_url_sources_crawl_the_same_host_and_extract_readable_text(): void
    {
        Http::fake([
            'https://docs.example.com/' => Http::response($this->page('Inicio', 'Bienvenido al portal de ayuda.', ['/guia', 'https://otro.example.org/x', '/logo.png']), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'https://docs.example.com/guia' => Http::response($this->page('Guía de compras', 'Las compras mayores requieren dos cotizaciones.'), 200, ['Content-Type' => 'text/html']),
        ]);

        $collection = $this->knowledgeCollection();

        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'url', 'name' => 'Portal', 'config' => ['urls' => ['https://docs.example.com/'], 'depth' => 1, 'max_pages' => 10],
        ])->assertSessionHasNoErrors();

        $job = RagJob::query()->where('type', JobType::SyncSource->value)->sole();
        $this->assertSame(JobStatus::Succeeded, $job->status, (string) $job->error);

        $documents = Document::query()->orderBy('external_id')->get();
        $this->assertSame(['https://docs.example.com/', 'https://docs.example.com/guia'], $documents->pluck('external_id')->all());
        $this->assertSame('Guía de compras', $documents[1]->title);

        $content = (string) $documents[1]->currentVersion?->content;
        $this->assertStringContainsString('# Guía de compras', $content);
        $this->assertStringNotContainsString('alert', $content);
        $this->assertStringNotContainsString('Menú principal', $content);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'otro.example.org') || str_contains($request->url(), 'logo.png'));

        // The page disappears at the origin: the next sync deletes it...
        Http::swap(new Factory);
        Http::fake([
            'https://docs.example.com/' => Http::response($this->page('Inicio', 'Bienvenido al portal de ayuda (nuevo).', ['/guia']), 200, ['Content-Type' => 'text/html']),
            'https://docs.example.com/guia' => Http::response('Not found', 404),
        ]);

        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.sources.sync', Source::query()->sole()));

        $this->assertSame(DocumentStatus::Deleted, $documents[1]->refresh()->status);
        $this->assertSame(0, DB::table('rag_chunks')->where('document_id', $documents[1]->id)->count());
        $this->assertSame(DocumentStatus::Indexed, $documents[0]->refresh()->status);
    }

    public function test_sitemaps_skip_unchanged_pages(): void
    {
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://docs.example.com/a</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://docs.example.com/b</loc><lastmod>2026-01-02</lastmod></url></urlset>';

        Http::fake([
            'https://docs.example.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
            'https://docs.example.com/a' => Http::response($this->page('A', 'Contenido de la página A sobre reembolsos.'), 200, ['Content-Type' => 'text/html']),
            'https://docs.example.com/b' => Http::response('# B\n\nTexto plano de la página B.', 200, ['Content-Type' => 'text/plain']),
        ]);

        $collection = $this->knowledgeCollection();
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'sitemap', 'name' => 'Sitemap', 'config' => ['url' => 'https://docs.example.com/sitemap.xml', 'max_pages' => 10],
        ])->assertSessionHasNoErrors();

        $job = RagJob::query()->where('type', JobType::SyncSource->value)->sole();
        $this->assertSame(JobStatus::Succeeded, $job->status, (string) $job->error.json_encode(Document::query()->get(['external_id', 'status', 'error'])));
        $this->assertSame(2, Document::query()->where('status', DocumentStatus::Indexed->value)->count());

        Http::swap(new Factory);
        Http::fake([
            'https://docs.example.com/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
            '*' => Http::response('should not be fetched', 500),
        ]);

        $this->actingAs($editor)->post(route('admin.knowledge.sources.sync', Source::query()->sole()));

        $job = RagJob::query()->find(Source::query()->sole()->last_job_id);
        $this->assertSame(JobStatus::Succeeded, $job?->status);
        $this->assertSame(2, $job?->units['unchanged'] ?? 0);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/a'));
    }

    public function test_url_sources_reject_invalid_configuration(): void
    {
        $collection = $this->knowledgeCollection();

        $this->actingAs($this->userWithRole('editor'))->post(route('admin.knowledge.sources.store', $collection), [
            'type' => 'url', 'name' => 'Mal', 'config' => ['urls' => ['javascript:alert(1)'], 'depth' => 9],
        ])->assertSessionHasErrors(['config.urls.0', 'config.depth']);
    }
}
