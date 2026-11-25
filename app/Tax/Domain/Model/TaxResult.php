<?php

namespace App\Tax\Domain\Model;

use App\Shared\Domain\Service\Decimal;

/** Structured outcome for one taxable amount. Every amount is a canonical decimal string at the currency's precision. */
final readonly class TaxResult
{
    /**
     * @param  list<AppliedTax>  $taxes  Ordered by priority, then tax ID.
     * @param  array<int, string>  $priorities  Tax amount per priority group.
     */
    public function __construct(
        public string $base,
        public string $net,
        public string $includedTax,
        public string $addedTax,
        public string $totalTax,
        public string $final,
        public array $taxes,
        public array $priorities,
        public bool $exempt,
    ) {}

    /** True when every active assigned tax could be resolved to a rate. */
    public function complete(): bool
    {
        foreach ($this->taxes as $tax) {
            if ($tax->status === AppliedTax::UNRESOLVED) {
                return false;
            }
        }

        return true;
    }

    /** @return list<AppliedTax> */
    public function unresolved(): array
    {
        return array_values(array_filter($this->taxes, fn (AppliedTax $tax): bool => $tax->status === AppliedTax::UNRESOLVED));
    }

    /** The amount a price list shows: with every tax in the price, or net of all taxes. */
    public function display(bool $withTax): string
    {
        return $withTax ? $this->final : $this->net;
    }

    /** The same result for a whole number of identical units (unit-price method keeps per-unit rounding). */
    public function times(int $quantity): self
    {
        $q = (string) $quantity;
        $scale = fn (string $value): string => Decimal::multiply($value, $q);

        return new self($scale($this->base), $scale($this->net), $scale($this->includedTax), $scale($this->addedTax), $scale($this->totalTax), $scale($this->final),
            array_map(fn (AppliedTax $tax): AppliedTax => $tax->withAmount($scale($tax->amount)), $this->taxes),
            array_map($scale, $this->priorities), $this->exempt);
    }
}
