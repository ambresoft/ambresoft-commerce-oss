<?php

namespace App\Catalog\Application\Service;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use App\Catalog\Application\Contract\CategoryStatusParticipant;
use LogicException;

final class CategoryMutationParticipants
{
    /** @var array<string, array{priority: int, participant: CategoryMutationParticipant}> */
    private array $entries = [];

    public function add(string $id, CategoryMutationParticipant $participant, int $priority = 100): void
    {
        if (! str_contains($id, '.') || isset($this->entries[$id])) {
            throw new LogicException('Category participants require unique namespaced IDs.');
        }
        $this->entries[$id] = compact('priority', 'participant');
        uksort($this->entries, fn (string $a, string $b): int => [$this->entries[$a]['priority'], $a] <=> [$this->entries[$b]['priority'], $b]);
    }

    public function beforeParentUsed(string $parentId): void
    {
        foreach ($this->entries as $entry) {
            $entry['participant']->beforeParentUsed($parentId);
        }
    }

    public function beforeDeletion(CategoryDeletion $deletion): void
    {
        foreach ($this->entries as $entry) {
            $entry['participant']->beforeDeletion($deletion);
        }
    }

    public function afterCategorySave(string $categoryId, string $actorId): void
    {
        foreach ($this->entries as $entry) {
            if ($entry['participant'] instanceof CategoryStatusParticipant) {
                $entry['participant']->afterCategorySave($categoryId, $actorId);
            }
        }
    }
}
