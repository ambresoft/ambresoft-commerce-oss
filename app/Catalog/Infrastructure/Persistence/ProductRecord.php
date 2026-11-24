<?php

namespace App\Catalog\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property ?int $brand_id
 * @property ?string $price
 * @property ?string $list_price
 * @property int $quantity
 * @property ?int $min_qty
 * @property ?int $max_qty
 * @property ?int $qty_step
 * @property ?int $in_stock_status_id
 * @property ?int $out_of_stock_status_id
 * @property ?string $out_of_stock_actions
 * @property ?string $weight
 * @property ?string $length
 * @property ?string $width
 * @property ?string $height
 * @property ?string $isbn
 * @property ?string $upc
 * @property ?string $mpn
 * @property ?string $ean
 * @property bool $returnable
 * @property bool $exclude_from_sitemap
 * @property bool $free_shipping
 * @property ?string $shipping_cost
 * @property ?CarbonImmutable $available_from
 * @property ?int $box_min_items
 * @property ?int $box_max_items
 * @property ?string $box_length
 * @property ?string $box_width
 * @property ?string $box_height
 * @property bool $complete
 */
final class ProductRecord extends Model
{
    use SoftDeletes;

    protected $table = 'products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['brand_id' => 'integer', 'in_stock_status_id' => 'integer', 'out_of_stock_status_id' => 'integer', 'returnable' => 'boolean', 'quantity' => 'integer', 'min_qty' => 'integer', 'max_qty' => 'integer', 'qty_step' => 'integer', 'list_price' => 'decimal:6', 'price' => 'decimal:6', 'weight' => 'decimal:6', 'length' => 'decimal:6', 'width' => 'decimal:6', 'height' => 'decimal:6', 'lock_version' => 'integer', 'free_shipping' => 'boolean', 'shipping_cost' => 'decimal:6', 'available_from' => 'immutable_date', 'box_min_items' => 'integer', 'box_max_items' => 'integer', 'box_length' => 'decimal:6', 'box_width' => 'decimal:6', 'box_height' => 'decimal:6', 'similar_products' => 'boolean', 'reviews_enabled' => 'boolean', 'exclude_from_sitemap' => 'boolean'];
    }

    /** @return HasMany<ProductTranslationRecord, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslationRecord::class, 'product_id');
    }
}
