<?php

namespace App\Catalog\Infrastructure;

use App\Catalog\Domain\Service\ProductRules;
use App\Catalog\Infrastructure\Persistence\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\ProductRecord;
use App\Localization\Infrastructure\LocaleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductState
{
    public function validCategory(?string $id): bool
    {
        // Landing page categories show subcategories, never products.
        return $id !== null && app(CategoryHierarchy::class)->isLeaf($id) && CategoryRecord::whereKey($id)->where('status', 'active')->where('is_landing_page', false)->exists();
    }

    /** @return array<string, string> */
    public function requiredErrors(ProductRecord $p, bool $activation = false): array
    {
        $default = app(LocaleCatalog::class)->defaultCode();
        $translation = $p->translations()->where('locale_id', app(LocaleCatalog::class)->active()->firstWhere('code', $default)->id)->first();
        $errors = [];
        $validCategory = $this->validCategory($p->category_id === null ? null : (string) $p->category_id);
        $incomplete = $this->incomplete($p, $translation->name ?? '', $validCategory);
        if (trim($translation->name ?? '') === '') {
            $errors['translations.'.$default.'.name'] = __('products.name_required');
        }
        if (trim($p->sku ?? '') === '') {
            $errors['sku'] = __('products.sku_required');
        }
        if ($p->price === null || bccomp($p->price, '0', 6) < 0) {
            $errors['price'] = __('products.price_required');
        }
        if ($incomplete && ! $validCategory) {
            $errors['category_id'] = __('products.leaf_required');
        }
        if ($p->sku !== null && ProductRecord::withTrashed()->whereRaw('lower(sku) = lower(?)', [$p->sku])->whereKeyNot($p->id)->exists()) {
            $errors['sku'] = __('products.sku_conflict');
        }
        if ($activation && ($translation->slug ?? '') === '') {
            $errors['translations.'.$default.'.slug'] = __('products.slug_required');
        }
        if ($activation && $p->type === 'digital' && ! DB::table('product_files')->where('product_id', $p->id)->where('purpose', 'download')->exists()) {
            $errors['files'] = __('products.file_required');
        }

        return $errors;
    }

    public function assertComplete(ProductRecord $p, bool $activation = false): void
    {
        if (($errors = $this->requiredErrors($p, $activation)) !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function incomplete(ProductRecord $p, string $defaultName, bool $validCategory): bool
    {
        return (new ProductRules)->incomplete($p->sku, $p->price, $defaultName, $validCategory);
    }
}
