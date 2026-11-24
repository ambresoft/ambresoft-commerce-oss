<?php

namespace App\Payment\Infrastructure;

use App\Catalog\Infrastructure\CategoryHierarchy;
use App\Catalog\Infrastructure\ProductCategories;
use App\Payment\Domain\Service\PaymentRestrictions;
use Illuminate\Support\Facades\DB;

/**
 * The payment restrictions of every method applied to a set of products. Checkout must offer, and accept, only the
 * methods this leaves unblocked for the products in the cart; staff choosing a method for an order in the admin are
 * not restricted.
 */
final class PaymentMethodRestrictions
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, array{product:string,reason:string}|null> By method code: the first blocked product and why, or null when the method can pay
     */
    public function forProducts(array $productIds): array
    {
        $hierarchy = app(CategoryHierarchy::class);
        $paths = $products = [];
        // Every category of a product (main and extra) with its ancestors can exclude a method.
        $all = app(ProductCategories::class)->all($productIds);
        foreach (DB::table('products')->whereIn('id', $productIds)->orderBy('id')->get(['id', 'type']) as $p) {
            $categories = [];
            foreach ($all[(string) $p->id] ?? [] as $category) {
                $paths[$category] ??= $hierarchy->pathIds($category);
                $categories = [...$categories, ...$paths[$category]];
            }
            $products[] = ['id' => (string) $p->id, 'type' => (string) $p->type, 'categories' => array_values(array_unique($categories))];
        }
        $excluded = DB::table('payment_method_excluded_categories')->get(['method_id', 'category_id'])->groupBy('method_id');
        $out = [];
        foreach (DB::table('payment_methods')->orderBy('position')->orderBy('id')->get(['id', 'code', 'exclude_physical', 'exclude_digital']) as $method) {
            $categories = ($excluded[$method->id] ?? collect())->pluck('category_id')->map(strval(...))->all();
            $out[$method->code] = PaymentRestrictions::blocked((bool) $method->exclude_physical, (bool) $method->exclude_digital, $categories, $products);
        }

        return $out;
    }

    /** @return list<string> The categories a method excludes (each with its subcategories). */
    public function excludedCategories(string $methodId): array
    {
        return DB::table('payment_method_excluded_categories')->where('method_id', $methodId)->orderBy('category_id')->pluck('category_id')->map(strval(...))->all();
    }

    /**
     * Every category staff can exclude, named by its full path ("Technology › Gaming") in the admin language.
     *
     * @return list<array{id:string,name:string}>
     */
    public function categoryOptions(string $locale, string $defaultLocale): array
    {
        $rows = DB::table('categories')->whereNull('system_key')->get(['id', 'parent_id', 'position'])->keyBy('id');
        $names = DB::table('category_translations as t')->join('locales as l', 'l.id', '=', 't.locale_id')->whereIn('l.code', [$locale, $defaultLocale])
            ->get(['t.category_id', 'l.code', 't.name'])->groupBy('category_id');
        $name = function (int|string $id) use ($names, $locale, $defaultLocale): string {
            $texts = ($names[$id] ?? collect())->keyBy('code');

            return trim((string) ($texts[$locale]->name ?? '')) ?: (trim((string) ($texts[$defaultLocale]->name ?? '')) ?: '#'.$id);
        };
        $options = [];
        foreach ($rows as $id => $row) {
            $path = [];
            for ($at = $row; $at !== null; $at = $at->parent_id === null ? null : ($rows[$at->parent_id] ?? null)) {
                array_unshift($path, $name($at->id));
            }
            $options[] = ['id' => (string) $id, 'name' => implode(' › ', $path)];
        }
        usort($options, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $options;
    }
}
