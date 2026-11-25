<?php

namespace App\Storefront\Application\Command;

/** A canonical public content identity, not a sitemap entry or a customer-specific projection. */
final readonly class IndexableUrl
{
    public function __construct(public string $section, public string $sourceId, public string $url, public ?string $lastmod) {}
}
