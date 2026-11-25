<?php

namespace Tests\Unit;

use App\Order\Infrastructure\OrderLineVat;
use PHPUnit\Framework\TestCase;

final class OrderLineVatTest extends TestCase
{
    public function test_discount_reduces_net_base_without_changing_the_vat_rate(): void
    {
        $item = (object) ['subtotal' => '124', 'discount_total' => '24', 'tax_included' => '19.35', 'tax_total' => '19.35', 'total' => '100', 'quantity' => 1];
        $taxes = [(object) ['rate_type' => 'percent', 'rate_value' => '0.24']];
        $line = (new OrderLineVat)->line($item, $taxes);
        self::assertSame('80.65', $line['net']);
        self::assertSame('24', $line['vat_rate']);
        self::assertSame('100', $line['gross']);
        $item->tax_included = '0';
        $item->tax_total = '24';
        $item->total = '124';
        $line = (new OrderLineVat)->line($item, $taxes);
        self::assertSame('100', $line['net']);
        self::assertSame('24', $line['vat_rate']);
    }

    public function test_a_fully_discounted_line_retains_its_snapshotted_rate(): void
    {
        $item = (object) ['subtotal' => '124', 'discount_total' => '124', 'tax_included' => '0', 'tax_total' => '0', 'total' => '0', 'quantity' => 2, 'pricing_basis' => json_encode(['tax_rates' => [['type' => 'percent', 'value' => '0.24', 'included' => true]]])];
        $line = (new OrderLineVat)->line($item, []);
        self::assertSame('0', $line['net']);
        self::assertSame('24', $line['vat_rate']);
    }

    public function test_compound_tax_keeps_its_effective_rate_instead_of_adding_the_percentages(): void
    {
        $item = (object) ['subtotal' => '130.2', 'discount_total' => '0', 'tax_included' => '30.2', 'tax_total' => '30.2', 'total' => '130.2', 'quantity' => 1, 'pricing_basis' => json_encode(['tax_rates' => [['type' => 'percent', 'value' => '0.05'], ['type' => 'percent', 'value' => '0.24']]])];
        $line = (new OrderLineVat)->line($item, [(object) ['rate_type' => 'percent', 'rate_value' => '0.05'], (object) ['rate_type' => 'percent', 'rate_value' => '0.24']]);
        self::assertSame('100.0', $line['net']);
        self::assertSame('30.2', $line['vat_rate']);
    }
}
