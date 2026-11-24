<?php

namespace Tests\Unit;

use App\Shipping\Domain\Service\ShipmentValue;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShipmentValueTest extends TestCase
{
    public function test_the_value_is_the_sum_of_the_priced_line_totals(): void
    {
        // Line totals already hold each line's discount and its added tax (OrderPricing): 100 - 10 + 21.6 VAT; 50 tax included.
        self::assertSame('161.60', ShipmentValue::of([['subtotal' => '100.00', 'discount_total' => '10.00', 'tax_total' => '21.60', 'tax_included' => '0.00', 'total' => '111.60'], ['subtotal' => '50.00', 'total' => '50.00']]));
        // Shipping and payment fees are not lines, so they never count.
        self::assertSame('0', ShipmentValue::of([]));
        self::assertSame('0.00', ShipmentValue::of([['total' => '0.00']]));
    }

    public function test_missing_or_invalid_line_totals_are_refused(): void
    {
        foreach ([[['subtotal' => '10']], [['total' => null]], [['total' => 'abc']], [['total' => '1e3']], [['total' => 12.5]]] as $lines) {
            try {
                ShipmentValue::of($lines);
                self::fail('Accepted '.json_encode($lines));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_limit_allows_values_up_to_and_including_it(): void
    {
        self::assertTrue(ShipmentValue::allowed('499.99', true, '500'));
        self::assertTrue(ShipmentValue::allowed('500.00', true, '500'));
        self::assertFalse(ShipmentValue::allowed('500.01', true, '500'));
        // Zero is a real limit; switched off or empty is no limit.
        self::assertTrue(ShipmentValue::allowed('0.00', true, '0'));
        self::assertFalse(ShipmentValue::allowed('0.01', true, '0'));
        self::assertTrue(ShipmentValue::allowed('99999', false, '0'));
        self::assertTrue(ShipmentValue::allowed('99999', true, null));
    }
}
