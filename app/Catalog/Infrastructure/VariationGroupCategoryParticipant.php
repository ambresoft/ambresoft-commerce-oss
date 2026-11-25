<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use App\Catalog\Application\Contract\CategoryStatusParticipant;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use Illuminate\Validation\ValidationException;

/**
 * Category hierarchy, status and deletion must keep every Variation Group
 * valid. Registered before the product participant so deletion is refused
 * before any product is moved to Uncategorized.
 */
final class VariationGroupCategoryParticipant implements CategoryMutationParticipant, CategoryStatusParticipant
{
    public function __construct(private VariationGroupIntegrity $integrity, private CategoryHierarchy $hierarchy) {}

    // Leaf protection already exists: a category holding products (grouped or not) cannot gain children.
    public function beforeParentUsed(string $parentId): void {}

    public function beforeDeletion(CategoryDeletion $deletion): void
    {
        $groups = $this->integrity->groupsInCategories([$deletion->categoryId]);
        if ($groups !== []) {
            throw new InvalidCategoryChange(__('variations.category_delete_blocked', ['groups' => $this->labels($groups)]));
        }
    }

    // Runs after a create/update/move/status change of the category, inside its transaction.
    public function afterCategorySave(string $categoryId, string $actorId): void
    {
        $groups = $this->integrity->groupsInCategories($this->hierarchy->descendantIds($categoryId));
        foreach ($groups as $group) {
            try {
                $this->integrity->assert($group);
            } catch (ValidationException $e) {
                // Each problem already names its group and product.
                throw new InvalidCategoryChange(__('variations.category_change_blocked', ['problems' => implode(' ', array_merge(...array_values($e->errors())))]));
            }
        }
    }

    /** @param list<string> $groups */
    private function labels(array $groups): string
    {
        return implode(', ', array_map($this->integrity->groupLabel(...), $groups));
    }
}
