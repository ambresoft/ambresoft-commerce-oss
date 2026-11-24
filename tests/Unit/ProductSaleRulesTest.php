<?php

namespace Tests\Unit;

use App\Pricing\Domain\Service\ProductSaleRules;
use PHPUnit\Framework\TestCase;

final class ProductSaleRulesTest extends TestCase
{
    public function test_percentage_prices_use_exact_decimal_math_and_currency_rounding(): void
    {
        $rules = new ProductSaleRules;
        self::assertSame('70.00', $rules->price('100', 'percentage', '30', 2));
        self::assertSame('6.99', $rules->price('9.99', 'percentage', '30', 2));
        self::assertSame('0.00', $rules->price('100', 'percentage', '100', 2));
        self::assertSame('15.75', $rules->price('100', 'fixed', '15.75', 2));
        self::assertSame('7', $rules->price('9.99', 'percentage', '30', 0));
    }

    public function test_quantity_priority_and_effective_price_select_one_non_stacking_offer(): void
    {
        $rules = new ProductSaleRules;
        $offer = ['id' => '1', 'kind' => 'fixed', 'amount' => '80', 'minimum_quantity' => 1, 'priority' => 1, 'starts_on' => null, 'ends_on' => null, 'remaining' => null];
        $offers = [$offer, [...$offer, 'id' => '2', 'kind' => 'percentage', 'amount' => '30', 'priority' => 2], [...$offer, 'id' => '3', 'amount' => '90', 'minimum_quantity' => 5], [...$offer, 'id' => '4', 'amount' => '60', 'priority' => 1]];
        self::assertSame(['id' => '4', 'price' => '60.00'], $rules->choose('100', 1, '2026-10-13', $offers, 2));
        self::assertSame(['id' => '3', 'price' => '90.00'], $rules->choose('100', 5, '2026-10-13', $offers, 2));
        self::assertSame(['id' => '2', 'price' => '70.00'], $rules->choose('100', 1, '2026-10-13', [[...$offer, 'priority' => 3], $offers[1]], 2));
    }

    public function test_dates_are_inclusive_and_limited_stock_must_cover_the_whole_quantity(): void
    {
        $rules = new ProductSaleRules;
        $offer = ['id' => '1', 'kind' => 'fixed', 'amount' => '80', 'minimum_quantity' => 1, 'priority' => 1, 'starts_on' => '2026-10-13', 'ends_on' => '2026-10-13', 'remaining' => 2];
        self::assertNotNull($rules->choose('100', 2, '2026-10-13', [$offer], 2));
        foreach (['2026-10-12', '2026-10-14'] as $date) {
            self::assertNull($rules->choose('100', 1, $date, [$offer], 2));
        }
        self::assertNull($rules->choose('100', 3, '2026-10-13', [$offer], 2));
        self::assertNotNull($rules->choose('100', 100, '2026-10-13', [[...$offer, 'remaining' => null]], 2));
        self::assertNull($rules->choose('70', 1, '2026-10-13', [$offer], 2), 'Sale rules never raise a product price');
    }

    public function test_sale_times_narrow_a_day_and_default_to_the_whole_day(): void
    {
        $rules = new ProductSaleRules;
        $offer = ['id' => '1', 'kind' => 'fixed', 'amount' => '80', 'minimum_quantity' => 1, 'priority' => 1, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-23', 'starts_time' => '09:00:00', 'ends_time' => '12:00:00', 'remaining' => null];
        self::assertNull($rules->choose('100', 1, '2026-10-22 08:59:59', [$offer], 2));
        self::assertNotNull($rules->choose('100', 1, '2026-10-22 09:00:00', [$offer], 2));
        self::assertNotNull($rules->choose('100', 1, '2026-10-23 12:00:00', [$offer], 2));
        self::assertNull($rules->choose('100', 1, '2026-10-23 12:00:01', [$offer], 2));
        $whole = [...$offer, 'starts_time' => null, 'ends_time' => null];
        self::assertNotNull($rules->choose('100', 1, '2026-10-22 00:00:00', [$whole], 2));
        self::assertNotNull($rules->choose('100', 1, '2026-10-23 23:59:59', [$whole], 2));
    }
}
