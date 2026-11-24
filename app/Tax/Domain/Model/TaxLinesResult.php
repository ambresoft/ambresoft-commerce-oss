<?php

namespace App\Tax\Domain\Model;

/**
 * Result for several lines. `groups` hold one result per line (unit-price method) or one per distinct tax set
 * (subtotal method); `lines` maps each input line index to its group.
 */
final readonly class TaxLinesResult
{
    /**
     * @param  list<TaxResult>  $groups
     * @param  array<int, int>  $lines
     * @param  list<AppliedTax>  $taxes  Amounts aggregated per tax across groups.
     */
    public function __construct(
        public string $method,
        public array $groups,
        public array $lines,
        public array $taxes,
        public string $subtotal,
        public string $includedTax,
        public string $addedTax,
        public string $totalTax,
        public string $total,
    ) {}
}
