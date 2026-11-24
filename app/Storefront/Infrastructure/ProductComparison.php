<?php

namespace App\Storefront\Infrastructure;

use App\Catalog\Infrastructure\PublicCatalog;
use App\Settings\Infrastructure\GeneralSettings;
use Illuminate\Validation\ValidationException;

/**
 * The visitor's product comparison. Guests and signed-in customers alike keep the list in their session (nothing is
 * stored in the database), capped by the store's limit. Only the General setting "allow product comparison" turns the
 * feature on; products the visitor can no longer see are dropped from the list.
 */
final class ProductComparison
{
    private const KEY = 'comparison.products';

    public function enabled(): bool
    {
        return (bool) app(GeneralSettings::class)->all()['allow_product_comparison'];
    }

    public function max(): int
    {
        return (int) app(GeneralSettings::class)->all()['comparison_max_products'];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_unique(array_map(strval(...), (array) session(self::KEY, []))));
    }

    /** What a product page needs to draw its comparison button.
     * @return array{enabled:bool,max:int,ids:list<string>,in_list:bool}|null Null while comparison is switched off.
     */
    public function state(string $product): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $ids = $this->ids();

        return ['enabled' => true, 'max' => $this->max(), 'ids' => $ids, 'in_list' => in_array($product, $ids, true)];
    }

    /** @return list<string> The list after adding the product.
     * @throws ValidationException When the list is full or the product cannot be seen.
     */
    public function add(string $product, ?string $customer): array
    {
        $ids = $this->ids();
        if (in_array($product, $ids, true)) {
            return $ids;
        }
        if (count($ids) >= $this->max()) {
            throw ValidationException::withMessages(['product' => __('storefront.comparison_full', ['max' => $this->max()])]);
        }
        if (! app(PublicCatalog::class)->products($customer)->where('products.id', $product)->exists()) {
            throw ValidationException::withMessages(['product' => __('storefront.wishlist_product_unavailable')]);
        }
        $ids[] = $product;
        session([self::KEY => $ids]);

        return $ids;
    }

    /** @return list<string> */
    public function remove(string $product): array
    {
        $ids = array_values(array_diff($this->ids(), [$product]));
        session([self::KEY => $ids]);

        return $ids;
    }

    public function clear(): void
    {
        session()->forget(self::KEY);
    }

    /**
     * What the comparison page shows: the compared products as storefront cards and a feature table whose rows say
     * whether every product has the same value (similar) or not (different).
     *
     * @param  array{locale:string,customer:?string}  $context
     * @return array<string,mixed>
     */
    public function page(array $context): array
    {
        $ids = $this->ids();
        ['cards' => $cards, 'format' => $format] = app(BlockContent::class)->productCards($ids, $context, app(PublicCatalog::class)->products($context['customer']));
        $items = array_values(array_filter(array_map(fn (string $id) => $cards[$id] ?? null, $ids)));
        // A product that became unavailable leaves the list for good.
        if (count($items) !== count($ids)) {
            session([self::KEY => array_column($items, 'id')]);
        }
        $features = [];
        foreach ($items as $index => $item) {
            $features[$index] = app(ProductPage::class)->features($item['id'], $context['locale'], $format['decimal']);
        }

        return ['items' => $items, 'groups' => $this->groups($features), 'max' => $this->max(), 'currency_format' => $format];
    }

    /**
     * @param  array<int,list<array{name:string,features:list<array{id:string,name:string,value:string}>}>>  $features  Each product's feature groups, in the order of the compared products.
     * @return list<array{name:string,rows:list<array{id:string,name:string,values:list<string|null>,same:bool}>}>
     */
    private function groups(array $features): array
    {
        $count = count($features);
        $groups = [];
        foreach ($features as $index => $productGroups) {
            foreach ($productGroups as $group) {
                foreach ($group['features'] as $feature) {
                    $groups[$group['name']]['rows'][$feature['id']] ??= ['id' => $feature['id'], 'name' => $feature['name'], 'values' => array_fill(0, $count, null)];
                    $groups[$group['name']]['rows'][$feature['id']]['values'][$index] = $feature['value'];
                }
            }
        }
        $result = [];
        foreach ($groups as $name => $group) {
            $rows = [];
            foreach ($group['rows'] as $row) {
                // Similar means every compared product has the feature with one and the same value.
                $row['same'] = $count > 1 && ! in_array(null, $row['values'], true) && count(array_unique($row['values'])) === 1;
                $rows[] = $row;
            }
            $result[] = ['name' => (string) $name, 'rows' => $rows];
        }

        return $result;
    }
}
