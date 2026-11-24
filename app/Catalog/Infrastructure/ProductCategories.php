<?php

namespace App\Catalog\Infrastructure;

use Illuminate\Support\Facades\DB;

/**
 * A product's categories: its main category (products.category_id), which alone sets its address, breadcrumb,
 * features and report category, plus extra categories it is also listed in (product_categories, CS-Cart's
 * "additional categories"). "Is this product in category X?" asks both, through these helpers.
 */
final class ProductCategories
{
    /**
     * SQL condition on the `products` table: the product's main or any extra category is one of `$categories`.
     *
     * @param  list<int|string>  $categories
     * @return array{0: string, 1: list<int>}
     */
    public static function in(array $categories, string $products = 'products'): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $categories)));
        if ($ids === []) {
            return ['FALSE', []];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));

        return ["($products.category_id IN ($marks) OR EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = $products.id AND pc.category_id IN ($marks)))", [...$ids, ...$ids]];
    }

    /**
     * Same condition with the category list given as a SQL subquery (e.g. a recursive descendant query).
     *
     * @param  list<mixed>  $bindings
     * @return array{0: string, 1: list<mixed>}
     */
    public static function inQuery(string $categoriesSql, array $bindings = [], string $products = 'products'): array
    {
        return ["($products.category_id IN ($categoriesSql) OR EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = $products.id AND pc.category_id IN ($categoriesSql)))", [...$bindings, ...$bindings]];
    }

    /**
     * Every category of each product, main first, as strings.
     *
     * @param  list<int|string>  $productIds
     * @return array<string, list<string>>
     */
    public function all(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $result = [];
        foreach (DB::table('products')->whereIn('id', $productIds)->get(['id', 'category_id']) as $p) {
            $result[(string) $p->id] = $p->category_id === null ? [] : [(string) $p->category_id];
        }
        foreach (DB::table('product_categories')->whereIn('product_id', $productIds)->orderBy('position')->get(['product_id', 'category_id']) as $link) {
            $result[(string) $link->product_id][] = (string) $link->category_id;
        }

        return array_map(fn (array $ids): array => array_values(array_unique($ids)), $result);
    }

    /**
     * The extra categories of one product, in their order.
     *
     * @return list<string>
     */
    public function extras(string $productId): array
    {
        return DB::table('product_categories')->where('product_id', $productId)->orderBy('position')->pluck('category_id')->map(strval(...))->all();
    }

    /**
     * Replaces a product's extra categories (the main category is never stored twice).
     *
     * @param  list<int|string>  $categories
     */
    public function replace(string $productId, ?string $main, array $categories): void
    {
        $wanted = array_values(array_filter(array_unique(array_map(strval(...), $categories)), fn (string $id): bool => $id !== $main));
        DB::table('product_categories')->where('product_id', $productId)->whereNotIn('category_id', $wanted)->delete();
        $current = DB::table('product_categories')->where('product_id', $productId)->pluck('position', 'category_id');
        foreach ($wanted as $n => $category) {
            if (! isset($current[$category])) {
                DB::table('product_categories')->insert(['product_id' => $productId, 'category_id' => $category, 'position' => $n + 1]);
            } elseif ((int) $current[$category] !== $n + 1) {
                DB::table('product_categories')->where('product_id', $productId)->where('category_id', $category)->update(['position' => $n + 1]);
            }
        }
    }
}
