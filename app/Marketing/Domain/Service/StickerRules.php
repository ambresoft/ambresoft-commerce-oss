<?php

namespace App\Marketing\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/** Product predicates use Promotions' bounded conjunction and exact inclusive ranges; never cart benefits. */
final class StickerRules
{
    public const TYPES = ['price', 'discount_percent', 'discount_amount', 'category', 'feature', 'in_stock', 'out_of_stock', 'quantity', 'purchasable', 'new', 'sales', 'free_shipping'];

    /** @param array<string, mixed> $condition
     * @param  array<string, mixed>  $facts
     */
    public function condition(array $condition, array $facts): bool
    {
        $type = $condition['type'];
        $value = $facts[$type] ?? null;
        if (in_array($type, ['price', 'discount_percent', 'discount_amount', 'quantity', 'sales'], true)) {
            return $value !== null && (! str_starts_with($type, 'discount_') || Decimal::compare($value, '0') > 0)
                && (new PromotionRules)->range((string) $value, $condition['minimum'], $condition['maximum']);
        }

        return match ($type) {
            'in_stock', 'purchasable', 'free_shipping' => $value === true,
            'out_of_stock' => ($facts['in_stock'] ?? null) === false,
            'new' => ($facts['age_seconds'] ?? -1) >= 0 && $facts['age_seconds'] < (int) $condition['maximum'] * 86400,
            default => false,
        };
    }
}
