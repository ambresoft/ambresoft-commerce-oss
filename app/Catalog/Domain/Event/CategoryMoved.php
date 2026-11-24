<?php

namespace App\Catalog\Domain\Event;

use DateTimeImmutable;

final readonly class CategoryMoved
{
    public function __construct(public string $categoryId, public ?string $oldParentId, public ?string $newParentId, public int $position, public string $actorId, public DateTimeImmutable $occurredAt) {}
}
