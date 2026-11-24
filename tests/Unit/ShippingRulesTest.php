<?php

namespace Tests\Unit;

use App\Shipping\Domain\Service\ManualRates;
use App\Shipping\Domain\Service\ShipmentTransitions;
use App\Shipping\Domain\Service\ShippingWeights;
use PHPUnit\Framework\TestCase;

final class ShippingRulesTest extends TestCase
{
    /** @return list<array{area_id: string, specificity: int, from: string, to: ?string, base: string, per_unit: string}> */
    private static function rates(): array
    {
        return [
            ['area_id' => '3', 'specificity' => 0, 'from' => '0', 'to' => null, 'base' => '20', 'per_unit' => '0'],
            ['area_id' => '1', 'specificity' => 1, 'from' => '0', 'to' => '5', 'base' => '3.5', 'per_unit' => '1.2'],
            ['area_id' => '1', 'specificity' => 1, 'from' => '5', 'to' => null, 'base' => '10', 'per_unit' => '0.5'],
            ['area_id' => '2', 'specificity' => 1, 'from' => '0', 'to' => null, 'base' => '99', 'per_unit' => '0'],
        ];
    }

    public function test_manual_rates_use_the_most_specific_area_and_the_weight_bracket(): void
    {
        // Equal specificity: the lowest area ID wins; brackets include their start and exclude their end.
        self::assertSame(['1', '6.50'], array_values(array_intersect_key(ManualRates::select(self::rates(), '2.5', 2) ?? [], array_flip(['area_id', 'amount']))));
        self::assertSame('10.00', ManualRates::select(self::rates(), '5', 2)['amount'] ?? null);
        self::assertSame('3.50', ManualRates::select(self::rates(), '0', 2)['amount'] ?? null);
        self::assertSame('11', ManualRates::select(self::rates(), '7.333', 0)['amount'] ?? null, 'Rounded to the currency scale');
        // Only the default area matches: its rate applies.
        self::assertSame('20.00', ManualRates::select([self::rates()[0]], '40', 2)['amount'] ?? null);
        // The most specific area has no bracket for the weight: not offered, never a fallback to a less specific area.
        self::assertNull(ManualRates::select([self::rates()[0], ['area_id' => '1', 'specificity' => 1, 'from' => '0', 'to' => '1', 'base' => '1', 'per_unit' => '0']], '2', 2));
        self::assertNull(ManualRates::select([], '1', 2));
    }

    public function test_weights_leave_out_free_shipping_lines_and_unknown_weights(): void
    {
        $lines = [
            ['weight' => '1.5', 'quantity' => 2, 'free_shipping' => false, 'freight' => '1'],
            ['weight' => '4', 'quantity' => 1, 'free_shipping' => true, 'freight' => '3'],
            ['weight' => null, 'quantity' => 5, 'free_shipping' => false, 'freight' => null],
        ];
        self::assertSame(['physical' => '7', 'chargeable' => '3', 'chargeable_known' => true, 'chargeable_items' => 7, 'freight' => '2'], ShippingWeights::measure($lines, true));
        self::assertSame(['physical' => '7', 'chargeable' => '7', 'chargeable_known' => true, 'chargeable_items' => 8, 'freight' => '5'], ShippingWeights::measure($lines, false));
        self::assertFalse(ShippingWeights::measure([$lines[2]], true)['chargeable_known']);
        self::assertSame('2.5', ShippingWeights::toKilograms('2500', '1'));
        self::assertSame('2.267962', ShippingWeights::toKilograms('5', '453.5924'));
        self::assertSame('5', ShippingWeights::fromKilograms('2.5', '500'));
    }

    public function test_shipment_events_change_the_order_status_once_and_only_when_expected(): void
    {
        self::assertSame('7', ShipmentTransitions::target('shipment_created', '7', '1', null, []));
        self::assertNull(ShipmentTransitions::target('shipment_created', null, '1', null, []), 'Nothing configured');
        self::assertNull(ShipmentTransitions::target('delivered', '3', '7', '7', ['delivered']), 'Already applied');
        self::assertNull(ShipmentTransitions::target('delivered', '3', '3', '7', []), 'Already in the target status');
        self::assertNull(ShipmentTransitions::target('delivered', '3', '2', '7', []), 'Changed by staff meanwhile');
        self::assertSame('3', ShipmentTransitions::target('delivered', '3', '7', '7', ['shipment_created']));
    }
}
