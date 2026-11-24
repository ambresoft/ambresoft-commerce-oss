<?php

namespace Tests\Unit\Marketing;

use App\Marketing\Domain\Service\PromotionRules;
use App\Marketing\Domain\Service\StickerRules;
use PHPUnit\Framework\TestCase;

final class StickerRulesTest extends TestCase
{
    public function test_product_rules_reuse_exact_ranges_and_do_not_grant_zero_discounts(): void
    {
        $rules = new StickerRules;
        $c = ['type' => 'price', 'minimum' => '0.10', 'maximum' => '0.30'];
        self::assertTrue($rules->condition($c, ['price' => '0.30']));
        self::assertFalse($rules->condition($c, ['price' => '0.300001']));
        self::assertFalse($rules->condition(['type' => 'discount_amount', 'minimum' => null, 'maximum' => null], ['discount_amount' => '0']));
        self::assertFalse($rules->condition(['type' => 'quantity', 'minimum' => '1', 'maximum' => null], ['quantity' => null]));
    }

    public function test_new_age_boundary_is_exclusive_and_future_products_do_not_match(): void
    {
        $rules = new StickerRules;
        $c = ['type' => 'new', 'maximum' => '14'];
        self::assertTrue($rules->condition($c, ['age_seconds' => 14 * 86400 - 1]));
        self::assertFalse($rules->condition($c, ['age_seconds' => 14 * 86400]));
        self::assertFalse($rules->condition($c, ['age_seconds' => -1]));
        self::assertTrue((new PromotionRules)->matches('all', [true, true]));
        self::assertFalse((new PromotionRules)->matches('all', [true, false]));
        self::assertTrue((new PromotionRules)->matches('any', [true, false]));
    }
}
