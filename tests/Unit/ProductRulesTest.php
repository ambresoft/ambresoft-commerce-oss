<?php

namespace Tests\Unit;

use App\Catalog\Domain\Service\ProductRules;
use PHPUnit\Framework\TestCase;

final class ProductRulesTest extends TestCase
{
    public function test_unset_limits_never_restrict_and_a_maximum_cannot_undercut_the_minimum(): void
    {
        $rules = new ProductRules;
        self::assertTrue($rules->limitsConsistent(null, null));
        self::assertTrue($rules->limitsConsistent(3, null));
        self::assertTrue($rules->limitsConsistent(null, 3));
        self::assertTrue($rules->limitsConsistent(3, 3));
        self::assertFalse($rules->limitsConsistent(4, 3));
        foreach ([1, 2, 1000] as $quantity) {
            self::assertTrue($rules->quantityAllowed($quantity, null, null, null));
        }
        self::assertFalse($rules->quantityAllowed(0, null, null, null));
    }

    public function test_minimum_maximum_and_step_define_the_allowed_order_quantities(): void
    {
        $rules = new ProductRules;
        // Minimum 3, maximum 11, step 4: 3, 7, 11.
        $allowed = array_values(array_filter(range(0, 14), fn (int $q): bool => $rules->quantityAllowed($q, 3, 11, 4)));
        self::assertSame([3, 7, 11], $allowed);
        // Without a minimum the step means whole multiples of the step.
        $multiples = array_values(array_filter(range(0, 13), fn (int $q): bool => $rules->quantityAllowed($q, null, null, 5)));
        self::assertSame([5, 10], $multiples);
        // A step of 1 or no step restricts nothing beyond min/max.
        self::assertTrue($rules->quantityAllowed(6, 2, 8, 1));
        self::assertFalse($rules->quantityAllowed(9, 2, 8, null));
    }

    public function test_default_action_blocks_purchase_at_zero_stock_and_backorders_lift_only_the_stock_check(): void
    {
        $rules = new ProductRules;
        self::assertSame('N', ProductRules::DEFAULT_OUT_OF_STOCK_ACTION);
        self::assertSame(['N', 'B'], ProductRules::OUT_OF_STOCK_ACTIONS);
        self::assertFalse($rules->stockAllows(0, 'N', 1));
        self::assertTrue($rules->stockAllows(5, 'N', 5));
        self::assertFalse($rules->stockAllows(5, 'N', 6));
        self::assertTrue($rules->stockAllows(0, 'B', 3));
        // Backorders do not bypass min/max/step.
        self::assertFalse($rules->canPurchase(3, 0, 'B', 5, null, null));
        self::assertTrue($rules->canPurchase(5, 0, 'B', 5, null, null));
        self::assertFalse($rules->canPurchase(5, 0, 'N', 5, null, null));
    }

    public function test_a_product_value_overrides_the_general_setting_and_null_follows_it(): void
    {
        $rules = new ProductRules;
        self::assertSame('N', $rules->effectiveOutOfStockAction(null, 'N'));
        self::assertSame('B', $rules->effectiveOutOfStockAction(null, 'B'));
        self::assertSame('B', $rules->effectiveOutOfStockAction('B', 'N'));
        self::assertSame('N', $rules->effectiveOutOfStockAction('N', 'B'));
    }

    public function test_box_packaging_is_enabled_only_by_a_positive_item_count(): void
    {
        $rules = new ProductRules;
        self::assertFalse($rules->packagingEnabled(null, null));
        self::assertFalse($rules->packagingEnabled(0, 0));
        self::assertTrue($rules->packagingEnabled(1, null));
        self::assertTrue($rules->packagingEnabled(null, 12));
    }

    public function test_disabled_inventory_tracking_lifts_stock_checks_but_preserves_ordering_limits(): void
    {
        $rules = new ProductRules;
        self::assertTrue($rules->stockAllows(0, 'N', 10, false));
        self::assertTrue($rules->canPurchase(10, 0, 'N', null, null, null, false));
        self::assertFalse($rules->canPurchase(10, 0, 'N', null, 5, null, false));
        self::assertFalse($rules->canPurchase(3, 0, 'N', null, null, 2, false));
    }
}
