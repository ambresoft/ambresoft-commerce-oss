<?php

namespace Tests\Fixtures;

use App\Storefront\Application\Command\IndexableUrl;
use App\Storefront\Application\Contract\StorefrontUrls;
use RuntimeException;

/** Deterministic content feed for exercising publication failures independently of catalog fixtures. */
final class MutableSitemapUrls implements StorefrontUrls
{
    /** @var array<string,list<IndexableUrl>> */
    public array $entries = [];

    public bool $fail = false;

    public function origin(): string
    {
        return 'https://store.example';
    }

    public function urls(string $section, ?string $sourceId = null): iterable
    {
        if ($this->fail) {
            throw new RuntimeException('Provider failed');
        }
        foreach ($this->entries[$section] ?? [] as $entry) {
            if ($sourceId === null || $sourceId === $entry->sourceId) {
                yield $entry;
            }
        }
    }
}
