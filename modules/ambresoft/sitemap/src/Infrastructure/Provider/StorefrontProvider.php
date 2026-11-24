<?php

namespace Ambresoft\Sitemap\Infrastructure\Provider;

use App\Storefront\Application\Command\IndexableUrl;
use App\Storefront\Application\Contract\StorefrontUrls;

abstract class StorefrontProvider
{
    protected const SECTION = '';

    public function __construct(protected StorefrontUrls $source) {}

    public function section(): string
    {
        return static::SECTION;
    }

    /** @return iterable<IndexableUrl> */
    public function urls(?string $id = null): iterable
    {
        return $this->source->urls($this->section(), $id);
    }
}
