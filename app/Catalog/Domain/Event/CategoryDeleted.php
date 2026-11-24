<?php

namespace App\Catalog\Domain\Event;

use DateTimeImmutable;

final readonly class CategoryDeleted
{
    public function __construct(public string $categoryId, public string $uncategorizedId, public string $actorId, public DateTimeImmutable $occurredAt) {}
}
