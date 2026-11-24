<?php

namespace App\Tax\Application\Contract;

use App\Tax\Domain\Model\CalculationSettings;
use App\Tax\Domain\Model\RateArea;
use App\Tax\Domain\Model\TaxDefinition;

/** Read-side port: the persisted tax configuration a calculation needs. Implementations return fresh authoritative values. */
interface TaxConfiguration
{
    /**
     * @param  list<string>  $taxIds
     * @return array<string, TaxDefinition> Keyed by tax ID; unknown IDs are absent.
     */
    public function definitions(array $taxIds): array;

    /** @return array<string, RateArea> Every area, active or not, keyed by ID. */
    public function areas(): array;

    /**
     * @param  list<string>  $productIds
     * @return array<string, list<string>> Assigned tax IDs per product ID (every requested product has an entry).
     */
    public function assignments(array $productIds): array;

    public function settings(): CalculationSettings;

    /** Decimals of the primary currency, the precision of every tax amount. */
    public function scale(): int;
}
