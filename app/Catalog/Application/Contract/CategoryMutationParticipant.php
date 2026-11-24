<?php

namespace App\Catalog\Application\Contract;

use App\Catalog\Application\Command\CategoryDeletion;

interface CategoryMutationParticipant
{
    // Called before a new/moved child uses this parent. Veto invalid leaf assignments here.
    public function beforeParentUsed(string $parentId): void;

    // Same SQL transaction: move dependent assignments before the category disappears.
    public function beforeDeletion(CategoryDeletion $deletion): void;
}
