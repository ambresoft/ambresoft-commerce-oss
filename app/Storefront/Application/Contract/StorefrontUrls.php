<?php

namespace App\Storefront\Application\Contract;

use App\Storefront\Application\Command\IndexableUrl;

/** Read-only discovery for add-ons; default-language canonical URLs an anonymous crawler can actually index. */
interface StorefrontUrls
{
    public function origin(): string;

    /** @return iterable<IndexableUrl> Source filter supports validating an individual override without a catalog scan. */
    public function urls(string $section, ?string $sourceId = null): iterable;
}
