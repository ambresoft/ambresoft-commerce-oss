<?php

namespace App\Catalog\Domain\Model;

use App\Shared\Domain\Contract\Translatable;
use App\Shared\Domain\Model\LocalizedContent;

final readonly class Category implements Translatable
{
    /** @param array<string, LocalizedContent> $translations */
    public function __construct(
        public string $id,
        public ?string $parentId,
        public int $position,
        public string $status,
        public ?string $systemKey,
        public int $version,
        private array $translations = [],
        public bool $ageVerification = false,
        public ?int $ageLimit = null,
    ) {}

    public function translation(string $locale): ?LocalizedContent
    {
        return $this->translations[$locale] ?? null;
    }
}
