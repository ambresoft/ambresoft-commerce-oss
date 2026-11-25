<?php

namespace App\Catalog\Domain\Service;

final class ProductRules
{
    /** N: purchase is not allowed at zero stock (default). B: backorders are allowed. */
    public const OUT_OF_STOCK_ACTIONS = ['N', 'B'];

    /** Open Graph object type of a product page with none chosen (the types themselves are the pages' list). */
    public const FACEBOOK_TYPE_DEFAULT = 'product';

    public const DEFAULT_OUT_OF_STOCK_ACTION = 'N';

    public function incomplete(?string $sku, ?string $price, string $defaultName, bool $validCategory): bool
    {
        return trim($sku ?? '') === '' || $price === null || trim($defaultName) === '' || ! $validCategory;
    }

    /** A product's own action overrides the general setting; NULL follows it. */
    public function effectiveOutOfStockAction(?string $override, string $general): string
    {
        return $override ?? $general;
    }

    /** An unset limit never restricts; with both set the maximum must not be below the minimum. */
    public function limitsConsistent(?int $min, ?int $max): bool
    {
        return $min === null || $max === null || $max >= $min;
    }

    /** Box packaging is enabled by a per-box minimum or maximum item count; empty or zero counts disable it. */
    public function packagingEnabled(?int $minItems, ?int $maxItems): bool
    {
        return ($minItems ?? 0) > 0 || ($maxItems ?? 0) > 0;
    }

    /**
     * Whether a requested order quantity satisfies the configured ordering rules. Unset limits
     * impose nothing. With a step, valid quantities are the minimum plus whole steps
     * (min, min+step, …), or whole multiples of the step when no minimum is set.
     */
    public function quantityAllowed(int $requested, ?int $min, ?int $max, ?int $step): bool
    {
        if ($requested < 1 || ($min !== null && $requested < $min) || ($max !== null && $requested > $max)) {
            return false;
        }

        return $step === null || ($requested - ($min ?? 0)) % $step === 0;
    }

    /** Stock check alone: backorders (B) lift it, anything else requires enough stock. Never changes status or visibility. */
    public function stockAllows(int $stock, string $outOfStockAction, int $requested, bool $trackInventory = true): bool
    {
        return ! $trackInventory || $outOfStockAction === 'B' || $requested <= $stock;
    }

    public function canPurchase(int $requested, int $stock, string $outOfStockAction, ?int $min, ?int $max, ?int $step, bool $trackInventory = true): bool
    {
        return $this->quantityAllowed($requested, $min, $max, $step) && $this->stockAllows($stock, $outOfStockAction, $requested, $trackInventory);
    }
}
