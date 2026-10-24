<?php

namespace Tests\Concerns;

use Illuminate\Http\UploadedFile;
use Starter\Ai\Models\AiModel;
use Starter\Knowledge\Enums\Metric;
use Starter\Knowledge\Enums\RagMode;
use Starter\Knowledge\Knowledge;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\EmbeddingProfile;
use Starter\Knowledge\Models\RagConnection;

trait CreatesKnowledge
{
    protected function activateKnowledge(): RagConnection
    {
        config(['starter.features.rag' => true]);

        $report = app(Knowledge::class)->activate(RagMode::Internal);
        $this->assertTrue($report->passed(), json_encode($report->toArray()) ?: '');

        return app(Knowledge::class)->connection() ?? throw new \RuntimeException('No connection');
    }

    protected function embeddingProfile(int $dimensions = 256, ?AiModel $model = null): EmbeddingProfile
    {
        $connection = app(Knowledge::class)->connection() ?? $this->activateKnowledge();

        if ($model === null) {
            $this->simulatedModel();
            $model = AiModel::query()->with('connection')->where('model', 'simulated-embedding')->sole();
        }

        $profile = EmbeddingProfile::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Profile '.$model->model.' '.$dimensions,
            'model_id' => $model->id,
            'provider' => $model->connection->driver->value,
            'model' => $model->model,
            'dimensions' => $dimensions,
            'metric' => Metric::Cosine,
            'normalize' => true,
            'batch_size' => 16,
        ]);

        app(Knowledge::class)->provisionProfile($profile);

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function knowledgeCollection(array $attributes = [], ?EmbeddingProfile $profile = null): Collection
    {
        $profile ??= $this->embeddingProfile();

        return Collection::query()->create([
            'slug' => 'handbook',
            'name' => 'Handbook',
            'connection_id' => $profile->connection_id,
            'embedding_profile_id' => $profile->id,
            'language' => 'es',
            'chunking' => ['target_tokens' => 120, 'overlap_tokens' => 20, 'max_tokens' => 300],
            'search' => Collection::defaultSearch(),
            'acl' => ['everyone'],
            ...$attributes,
        ]);
    }

    /**
     * A real upload (content based MIME detection).
     */
    protected function knowledgeFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rag');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Minimal PDF with one text line per page (Helvetica, WinAnsi).
     *
     * @param  list<string>  $pages
     */
    protected function pdf(array $pages): string
    {
        $objects = [];
        $kids = [];
        $count = count($pages);

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $next = 4;

        foreach ($pages as $text) {
            $lines = '';
            $y = 750;

            foreach (explode("\n", wordwrap($text, 80)) as $line) {
                $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) mb_convert_encoding($line, 'Windows-1252', 'UTF-8'));
                $lines .= "BT /F1 11 Tf 50 {$y} Td ({$escaped}) Tj ET\n";
                $y -= 14;
            }

            $contentId = $next++;
            $pageId = $next++;
            $objects[$contentId] = '<< /Length '.strlen($lines)." >>\nstream\n{$lines}endstream";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R /Resources << /Font << /F1 3 0 R >> >> >>";
            $kids[] = "{$pageId} 0 R";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$count} >>";
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
