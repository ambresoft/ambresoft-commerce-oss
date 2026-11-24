<?php

namespace Tests\Unit\Marketing;

use App\Marketing\Domain\Service\CouponAmounts;
use PHPUnit\Framework\TestCase;

final class CouponAmountsTest extends TestCase
{
    public function test_exact_percentages_caps_and_minor_unit_splitting(): void
    {
        $amounts = new CouponAmounts;
        self::assertSame('3.33', $amounts->discount('percentage', '33.33', '10', 2));
        self::assertSame('10.00', $amounts->discount('fixed', '100', '10', 2));
        self::assertSame([['price' => '9.66', 'quantity' => 1], ['price' => '9.67', 'quantity' => 2]], $amounts->units('29', 3, 2));
        self::assertSame([['price' => '0', 'quantity' => 2], ['price' => '1', 'quantity' => 1]], $amounts->units('1', 3, 0));
        self::assertSame([['price' => '0.00', 'quantity' => 3]], $amounts->units('0', 3, 2));
    }
}
