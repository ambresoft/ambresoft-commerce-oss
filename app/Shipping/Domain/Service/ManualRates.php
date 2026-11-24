<?php

namespace App\Shipping\Domain\Service;

use App\Shared\Domain\Service\Decimal;

/**
 * Manual rate selection. Candidate rates belong to areas that match the destination; the most specific area wins
 * (region > country > default), ties go to the lowest area ID. Within that area the weight bracket containing the
 * weight applies (from ≤ weight < to, open-ended when `to` is empty). Charge = base + per unit × (weight − from):
 * the per-unit amount applies to the weight above the bracket's start. No matching bracket means not offered.
 */
final class ManualRates
{
    /**
     * @param  list<array{area_id: string, specificity: int, from: string, to: ?string, base: string, per_unit: string}>  $rates
     * @return array{area_id: string, from: string, to: ?string, base: string, per_unit: string, amount: string}|null
     */
    public static function select(array $rates, string $weight, int $scale): ?array
    {
        if ($rates === []) {
            return null;
        }
        usort($rates, fn (array $a, array $b): int => [$b['specificity'], (int) $a['area_id']] <=> [$a['specificity'], (int) $b['area_id']]);
        $area = $rates[0]['area_id'];
        foreach ($rates as $rate) {
            if ($rate['area_id'] !== $area) {
                continue;
            }
            $inside = Decimal::compare($weight, $rate['from']) >= 0 && ($rate['to'] === null || Decimal::compare($weight, $rate['to']) < 0);
            if ($inside) {
                $above = Decimal::subtract($weight, $rate['from']);
                $amount = Decimal::round(Decimal::add($rate['base'], Decimal::multiply($rate['per_unit'], $above)), $scale);

                return [...array_intersect_key($rate, array_flip(['area_id', 'from', 'to', 'base', 'per_unit'])), 'amount' => $amount];
            }
        }

        return null;
    }
}
