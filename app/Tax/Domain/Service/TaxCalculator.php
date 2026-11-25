<?php

namespace App\Tax\Domain\Service;

use App\Shared\Domain\Service\Decimal;
use App\Tax\Domain\Model\AppliedTax;
use App\Tax\Domain\Model\ResolvedTax;
use App\Tax\Domain\Model\TaxResult;

/**
 * Exact tax arithmetic for one taxable amount.
 *
 * Taxes are grouped by priority (ascending), then ordered by tax ID inside a group; storage order never matters.
 *
 *  1. Price-included taxes are extracted from the configured price from the highest priority group down. A group
 *     with percentages R and fixed amounts F turns an amount A into (A - F) / (1 + R), so taxes of one priority
 *     share the same base and a higher priority is "tax on tax". What remains after every group is the net amount.
 *  2. Added (excluded) taxes are computed from the lowest priority up. A group's base is the net amount plus every
 *     tax of a lower priority group, whether included or added; taxes within one group share that base.
 *  3. Each tax amount is rounded once, half away from zero, to the currency's decimals, and later groups use the
 *     rounded amounts, so the parts always reconcile with the totals. A price-included tax never changes because
 *     of an added tax: the configured price is authoritative.
 *
 * Exempt customers pay none of the taxes: added taxes are not charged and the included component is removed from
 * the price, so they pay the net amount. Callers needing a different policy must pass the net price themselves.
 */
final class TaxCalculator
{
    /**
     * @param  list<ResolvedTax>  $resolved
     * @param  list<AppliedTax>  $unresolved
     */
    public function calculate(string $price, array $resolved, array $unresolved, bool $exempt, int $scale): TaxResult
    {
        $base = Decimal::atLeastScale(Decimal::trim($price), $scale);
        usort($resolved, fn (ResolvedTax $a, ResolvedTax $b): int => [$a->tax->priority, (int) $a->tax->id] <=> [$b->tax->priority, (int) $b->tax->id]);
        /** @var array<int, list<ResolvedTax>> $groups */
        $groups = [];
        foreach ($resolved as $item) {
            $groups[$item->tax->priority][] = $item;
        }
        ksort($groups);

        $amounts = $this->included($base, $groups, $scale);
        $extracted = '0';
        foreach ($amounts as $amount) {
            $extracted = Decimal::add($extracted, $amount);
        }
        $net = Decimal::atLeastScale(Decimal::trim(Decimal::subtract($base, $extracted)), $scale);

        $priorities = [];
        $lower = '0';
        foreach ($groups as $priority => $items) {
            $before = Decimal::add($net, $lower);
            $sum = '0';
            foreach ($items as $item) {
                if (! $item->tax->priceIncludesTax) {
                    $amounts[$item->tax->id] = $this->amount($item, $before, $scale);
                }
                $sum = Decimal::add($sum, $amounts[$item->tax->id]);
            }
            $priorities[$priority] = Decimal::round($sum, $scale);
            $lower = Decimal::add($lower, $sum);
        }

        $applied = [];
        $included = '0';
        $added = '0';
        foreach ($groups as $items) {
            foreach ($items as $item) {
                $amount = $amounts[$item->tax->id];
                if ($item->tax->priceIncludesTax) {
                    $included = Decimal::add($included, $amount);
                } else {
                    $added = Decimal::add($added, $amount);
                }
                $applied[] = new AppliedTax($item->tax->id, $item->tax->name, $item->tax->priority, $item->tax->priceIncludesTax, $exempt ? AppliedTax::EXEMPT : AppliedTax::APPLIED,
                    $exempt ? Decimal::round('0', $scale) : $amount, $item->area->id, $item->area->name, $item->rate->type, $item->rate->value);
            }
        }
        $applied = [...$applied, ...$unresolved];
        usort($applied, fn (AppliedTax $a, AppliedTax $b): int => [$a->priority, (int) $a->taxId] <=> [$b->priority, (int) $b->taxId]);
        $zero = Decimal::round('0', $scale);
        if ($exempt) {
            return new TaxResult($base, $net, $zero, $zero, $zero, $net, $applied, array_map(fn (): string => $zero, $priorities), true);
        }

        return new TaxResult($base, $net, Decimal::round($included, $scale), Decimal::round($added, $scale), Decimal::round(Decimal::add($included, $added), $scale),
            Decimal::round(Decimal::add($base, $added), $scale), $applied, $priorities, false);
    }

    /**
     * Extracts price-included taxes, highest priority first.
     *
     * @param  array<int, list<ResolvedTax>>  $groups
     * @return array<string, string> Amount per included tax ID.
     */
    private function included(string $price, array $groups, int $scale): array
    {
        $amounts = [];
        $remaining = $price;
        foreach (array_reverse($groups, true) as $items) {
            $members = array_values(array_filter($items, fn (ResolvedTax $item): bool => $item->tax->priceIncludesTax));
            if ($members === []) {
                continue;
            }
            $percent = '0';
            $fixed = '0';
            foreach ($members as $item) {
                $item->rate->isPercent() ? $percent = Decimal::add($percent, $item->rate->value) : $fixed = Decimal::add($fixed, $item->rate->value);
            }
            $before = Decimal::divide(Decimal::subtract($remaining, $fixed), Decimal::add('1', $percent), 12);
            if (Decimal::compare($before, '0') < 0) {
                $before = '0';
            }
            $left = $remaining;
            foreach ($members as $item) {
                $amount = $this->amount($item, $before, $scale);
                // Included taxes can never exceed the price they are part of.
                if (Decimal::compare($amount, $left) > 0) {
                    $amount = $left;
                }
                $amounts[$item->tax->id] = $amount;
                $left = Decimal::subtract($left, $amount);
            }
            $remaining = $left;
        }

        return $amounts;
    }

    private function amount(ResolvedTax $item, string $base, int $scale): string
    {
        return Decimal::round($item->rate->isPercent() ? Decimal::multiply($item->rate->value, $base) : $item->rate->value, $scale);
    }
}
