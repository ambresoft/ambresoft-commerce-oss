<?php

namespace App\Catalog\Application\Command;

final readonly class CategoryDeletion
{
    public function __construct(public string $categoryId, public string $uncategorizedId, public string $actorId) {}
}
