<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Application\Command\CategoryDeletion;
use App\Catalog\Application\Contract\CategoryMutationParticipant;
use App\Catalog\Application\Contract\CategoryStatusParticipant;
use App\Catalog\Domain\Exception\InvalidCategoryChange;
use Illuminate\Support\Facades\DB;

final class ProductCategoryParticipant implements CategoryMutationParticipant, CategoryStatusParticipant
{
    public function afterCategorySave(string $categoryId, string $actorId): void
    {
        app(ProductEligibility::class)->refresh($actorId, $categoryId);
        app(ProductFeatures::class)->reconcile();
        DB::table('feature_catalog_state')->where('id', 1)->increment('version');
    }

    public function beforeParentUsed(string $parentId): void
    {
        if (DB::table('products')->where('category_id', $parentId)->exists()) {
            throw new InvalidCategoryChange('products.assigned_leaf');
        }
    }

    public function beforeDeletion(CategoryDeletion $deletion): void
    {
        if (DB::table('feature_categories')->where('category_id', $deletion->categoryId)->exists()) {
            throw new InvalidCategoryChange('features.category_dependency');
        }
        DB::table('products')->where('category_id', $deletion->categoryId)->orderBy('id')->chunkById(100, function ($products) use ($deletion): void {
            foreach ($products as $product) {
                DB::table('products')->where('id', $product->id)->update(['category_id' => $deletion->uncategorizedId, 'status' => 'disabled', 'lock_version' => DB::raw('lock_version + 1'), 'updated_by' => $deletion->actorId, 'updated_at' => now()]);
                app(ProductFeatures::class)->reconcile(product: (string) $product->id, bumpProduct: false);
                app(ProductAudit::class)->record((string) $product->id, $deletion->actorId, 'category_recovery', ['from' => $deletion->categoryId, 'to' => $deletion->uncategorizedId]);
            }
        });
    }
}
