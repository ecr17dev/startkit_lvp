<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Illuminate\Validation\Rule;
use RuntimeException;
use Starter\Knowledge\Models\Source;

class AdapterDriver implements SourceDriver
{
    public function __construct(protected AdapterRegistry $adapters) {}

    public function rules(): array
    {
        return ['config.adapter' => ['required', 'string', Rule::in(array_keys($this->adapters->options()))]];
    }

    public function discover(Source $source, SyncContext $context): iterable
    {
        $adapter = $this->adapters->get((string) ($source->config['adapter'] ?? ''))
            ?? throw new RuntimeException((string) __('The adapter of this source is not registered.'));

        foreach ($adapter->documents($source->config) as $document) {
            $context->checkpoint();

            yield new SourceItem(
                externalId: $adapter->key().':'.$document->id,
                title: $document->title,
                uri: $document->uri,
                content: new RawContent($document->text, $document->mimeType, $document->title.($document->mimeType === 'text/markdown' ? '.md' : '.txt')),
                acl: $document->acl,
                metadata: $document->metadata,
                marker: $document->version ?? hash('sha256', $document->text),
            );
        }
    }
}
