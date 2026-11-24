<?php

namespace App\Catalog\Domain\Event;

use DateTimeImmutable;

final readonly class CategoryCreated
{
    public function __construct(public string $categoryId, public string $actorId, public DateTimeImmutable $occurredAt) {}
}
