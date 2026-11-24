<?php

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Contract\Translatable;
use App\Shared\Domain\Model\LocalizedContent;

final readonly class Product implements Translatable
{
    /** @param array<string, LocalizedContent> $translations */
    public function __construct(public string $id, private array $translations) {}

    public function translation(string $locale): ?LocalizedContent
    {
        return $this->translations[$locale] ?? null;
    }
}
