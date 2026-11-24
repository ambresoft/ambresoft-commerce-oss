<?php

namespace App\Catalog\Application\Contract;

use App\Catalog\Application\Command\DeleteCategoryTarget;
use App\Catalog\Application\Command\SaveCategoryData;

interface CategoryAdministrationInterface
{
    public function save(string $actorId, ?string $categoryId, SaveCategoryData $data): string;

    public function saveImported(string $actorId, ?string $categoryId, SaveCategoryData $data): string;

    public function delete(string $actorId, string $categoryId, int $version): void;

    /** @param list<DeleteCategoryTarget> $targets */
    public function deleteMany(string $actorId, array $targets): void;
}
