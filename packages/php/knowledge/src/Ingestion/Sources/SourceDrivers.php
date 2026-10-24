<?php

namespace Starter\Knowledge\Ingestion\Sources;

use Illuminate\Contracts\Container\Container;
use Starter\Knowledge\Enums\SourceType;

class SourceDrivers
{
    public function __construct(protected Container $container) {}

    public function for(SourceType $type): SourceDriver
    {
        return $this->container->make(match ($type) {
            SourceType::Upload => UploadDriver::class,
            SourceType::Url => UrlDriver::class,
            SourceType::Sitemap => SitemapDriver::class,
            SourceType::Adapter => AdapterDriver::class,
            SourceType::Disk => DiskDriver::class,
        });
    }
}
