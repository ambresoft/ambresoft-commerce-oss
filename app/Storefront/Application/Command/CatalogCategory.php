<?php

namespace App\Storefront\Application\Command;

final readonly class CatalogCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $url,
        public ?string $parentId,
    ) {}
}
