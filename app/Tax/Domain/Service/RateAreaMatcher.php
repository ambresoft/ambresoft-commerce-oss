<?php

namespace App\Tax\Domain\Service;

use App\Tax\Domain\Model\Address;
use App\Tax\Domain\Model\AppliedTax;
use App\Tax\Domain\Model\RateArea;
use App\Tax\Domain\Model\ResolvedTax;
use App\Tax\Domain\Model\TaxDefinition;

/**
 * Picks the rate that applies to an address.
 *
 * Only active areas in which the tax has an explicit rate are candidates. The most specific match wins
 * (region, then whole country, then the default area); equally specific areas are decided by the lowest
 * area ID, so the result never depends on storage order. An explicit zero rate is a rate and stops the
 * search: it never falls back. A tax without any applicable rate is reported, never assumed to be zero.
 */
final class RateAreaMatcher
{
    /**
     * @param  array<string, RateArea>  $areas  Keyed by area ID.
     * @return ResolvedTax|string The resolved rate, or the AppliedTax reason code explaining why none applies.
     */
    public function select(TaxDefinition $tax, array $areas, ?Address $address): ResolvedTax|string
    {
        $candidates = [];
        foreach ($tax->rates as $rate) {
            $area = $areas[$rate->areaId] ?? null;
            $specificity = $area?->specificity($address);
            if ($area !== null && $specificity !== null) {
                $candidates[] = [$specificity, $area, $rate];
            }
        }
        if ($candidates === []) {
            return $address === null || ! $address->known() ? AppliedTax::ADDRESS_REQUIRED : AppliedTax::NO_RATE;
        }
        usort($candidates, fn (array $a, array $b): int => [$b[0], (int) $a[1]->id] <=> [$a[0], (int) $b[1]->id]);

        return new ResolvedTax($tax, $candidates[0][2], $candidates[0][1]);
    }
}
