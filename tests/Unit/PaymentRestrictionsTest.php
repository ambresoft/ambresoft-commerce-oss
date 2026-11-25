<?php

namespace Tests\Unit;

use App\Payment\Domain\Service\PaymentRestrictions;
use PHPUnit\Framework\TestCase;

final class PaymentRestrictionsTest extends TestCase
{
    public function test_a_method_pays_unless_a_product_has_an_excluded_type_or_sits_under_an_excluded_category(): void
    {
        $ebook = ['id' => '1', 'type' => 'digital', 'categories' => ['10', '11']];
        $chair = ['id' => '2', 'type' => 'physical', 'categories' => ['20']];

        self::assertNull(PaymentRestrictions::blocked(false, false, [], [$ebook, $chair]));
        self::assertSame(['product' => '1', 'reason' => 'digital'], PaymentRestrictions::blocked(false, true, [], [$ebook, $chair]));
        self::assertSame(['product' => '2', 'reason' => 'physical'], PaymentRestrictions::blocked(true, false, [], [$ebook, $chair]));
        // Excluding a parent category excludes the products of its subcategories.
        self::assertSame(['product' => '1', 'reason' => 'category'], PaymentRestrictions::blocked(false, false, ['10'], [$ebook, $chair]));
        self::assertNull(PaymentRestrictions::blocked(false, false, ['99'], [$ebook, $chair]));
        self::assertNull(PaymentRestrictions::blocked(true, true, ['10'], []));
    }
}
