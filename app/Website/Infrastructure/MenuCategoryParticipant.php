<?php

namespace App\Website\Infrastructure;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MenuCategoryParticipant implements CategoryMutationParticipant
{
    public function beforeParentUsed(string $parentId): void {}

    public function beforeDeletion(CategoryDeletion $deletion): void
    {
        if (DB::table('website_menu_items')->where('category_id', $deletion->categoryId)->exists()) {
            throw ValidationException::withMessages(['category' => __('menus.category_referenced')]);
        }
    }
}
