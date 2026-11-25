<?php

namespace App\Catalog\Application\Contract;

interface CategoryStatusParticipant
{
    public function afterCategorySave(string $categoryId, string $actorId): void;
}
