<?php

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Contract\CategoryAdministrationInterface;

final readonly class ManageCategoriesHandler
{
    public function __construct(private CategoryAdministrationInterface $administration) {}

    public function save(string $actorId, ?string $categoryId, SaveCategoryData $data): string
    {
        return $this->administration->save($actorId, $categoryId, $data);
    }

    public function saveImported(string $actorId, ?string $categoryId, SaveCategoryData $data): string
    {
        return $this->administration->saveImported($actorId, $categoryId, $data);
    }

    public function delete(string $actorId, string $categoryId, int $version): void
    {
        $this->administration->delete($actorId, $categoryId, $version);
    }

    /** @param list<DeleteCategoryTarget> $targets */
    public function deleteMany(string $actorId, array $targets): void
    {
        $this->administration->deleteMany($actorId, $targets);
    }
}
