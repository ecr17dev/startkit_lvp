<?php

namespace App\Knowledge;

use Illuminate\Support\Facades\File;
use Starter\Knowledge\Ingestion\Sources\AdapterDocument;
use Starter\Knowledge\Ingestion\Sources\KnowledgeAdapter;

/**
 * Example adapter for internal content: the Markdown documentation of this
 * repository (docs/*.md). Copy it to index your own records (products,
 * tickets, policies...) with stable ids, text, metadata and access lists.
 */
class ProjectDocsAdapter implements KnowledgeAdapter
{
    public function key(): string
    {
        return 'project-docs';
    }

    public function label(): string
    {
        return (string) __('Project documentation (docs/*.md)');
    }

    public function documents(array $config): iterable
    {
        $directory = realpath(base_path('../../docs')) ?: base_path('docs');

        if (! is_dir($directory)) {
            return;
        }

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $text = (string) file_get_contents($file->getPathname());
            $title = preg_match('/^#\s+(.+)$/m', $text, $match) ? trim($match[1]) : $file->getFilenameWithoutExtension();

            yield new AdapterDocument(
                id: $file->getFilename(),
                title: $title,
                text: $text,
                metadata: ['path' => 'docs/'.$file->getFilename()],
                version: hash('sha256', $text),
            );
        }
    }
}
