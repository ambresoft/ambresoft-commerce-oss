<?php

namespace App\Catalog\Infrastructure\Import;

use App\Catalog\Infrastructure\ProductInput;
use App\Localization\Infrastructure\LocaleCatalog;

final class ProductImportFields
{
    public const SCALARS = ['sku', 'type', 'status', 'price', 'category_id', 'brand_id', 'ean', 'mpn', 'isbn', 'upc', 'returnable', 'quantity', 'list_price', 'min_qty', 'max_qty', 'qty_step', 'out_of_stock_actions', 'in_stock_status_id', 'out_of_stock_status_id', 'weight', 'length', 'width', 'height', 'free_shipping', 'shipping_cost', 'available_from', 'box_min_items', 'box_max_items', 'box_length', 'box_width', 'box_height', 'similar_products', 'reviews_enabled', 'facebook_type', 'exclude_from_sitemap'];

    public const BOOLEANS = ['returnable', 'free_shipping', 'similar_products', 'reviews_enabled', 'exclude_from_sitemap', 'remove_image'];

    /** @return list<string> */
    public function columns(): array
    {
        $columns = [...self::SCALARS, 'tax_ids', 'customer_groups', 'image_url', 'remove_image'];
        foreach (app(LocaleCatalog::class)->active() as $locale) {
            foreach (ProductInput::TEXT as $field) {
                $columns[] = $field.'.'.$locale->code;
            }
        }

        return $columns;
    }

    /** @return array<string, string> */
    public function example(): array
    {
        $row = array_fill_keys($this->columns(), '');

        return array_replace($row, ['sku' => 'EXAMPLE-001', 'type' => 'physical', 'status' => 'disabled', 'price' => '10', 'category_id' => 'REPLACE_WITH_LEAF_CATEGORY_ID', 'name.'.app(LocaleCatalog::class)->defaultCode() => 'Example product']);
    }
}
