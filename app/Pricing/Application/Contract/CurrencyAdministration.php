<?php

namespace App\Pricing\Application\Contract;

use App\Pricing\Application\Command\SaveCurrencyData;

interface CurrencyAdministration
{
    /** Creates a secondary currency and returns its ID. */
    public function create(string $actor, SaveCurrencyData $data): string;

    /** The code is immutable; the primary currency's rate and Active status cannot change here. */
    public function update(string $actor, string $id, int $version, SaveCurrencyData $data): void;

    /**
     * Converts every stored product price and rebases all rates atomically.
     *
     * @return array{products: int, rates: array<string, string>}
     */
    public function makePrimary(string $actor, string $id, int $version, string $confirmation): array;

    public function delete(string $actor, string $id, int $version): void;
}
