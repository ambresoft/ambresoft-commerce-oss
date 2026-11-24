<?php

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Exception\InvalidCategoryChange;
use App\Catalog\Domain\Model\Category;

final class CategoryRules
{
    public function assertEditable(Category $category): void
    {
        if ($category->systemKey !== null) {
            throw new InvalidCategoryChange('categories.errors.system');
        }
    }

    public function assertPosition(int $position): void
    {
        if ($position < 1 || $position > 2147483647) {
            throw new InvalidCategoryChange('categories.errors.position');
        }
    }

    /** @param list<string> $parentAncestors */
    public function assertParent(?string $categoryId, array $parentAncestors, bool $systemParent): void
    {
        if ($systemParent || ($categoryId !== null && in_array($categoryId, $parentAncestors, true))) {
            throw new InvalidCategoryChange('categories.errors.parent');
        }
    }

    public function assertDeletable(Category $category, bool $hasChildren): void
    {
        $this->assertEditable($category);
        if ($hasChildren) {
            throw new InvalidCategoryChange('categories.errors.children');
        }
    }
}
