<?php

namespace Starter\Knowledge\Enums;

enum SourceType: string
{
    case Upload = 'upload';
    case Url = 'url';
    case Sitemap = 'sitemap';
    case Adapter = 'adapter';
    case Disk = 'disk';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Files (TXT, Markdown, PDF)',
            self::Url => 'Web pages (URLs)',
            self::Sitemap => 'Sitemap',
            self::Adapter => 'Internal content (adapter)',
            self::Disk => 'Storage disk (S3 compatible)',
        };
    }

    /**
     * Whether a sync may detect deletions by comparing with the origin.
     */
    public function detectsDeletions(): bool
    {
        return $this !== self::Upload;
    }
}
