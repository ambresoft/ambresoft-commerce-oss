<?php

namespace App\Tax\Application\Contract;

use App\Tax\Application\Command\SaveTaxData;

interface TaxAdministration
{
    /** Creates the tax with its rates atomically and returns its ID. */
    public function create(string $actor, SaveTaxData $data): string;

    /** Replaces the definition and its complete rate list. Fails when the version is stale. */
    public function update(string $actor, string $id, int $version, SaveTaxData $data): void;

    /** Refused while any product still references the tax; disabling is the safe alternative. */
    public function delete(string $actor, string $id, int $version): void;

    /**
     * Adds the taxes to every product (including Trash) that lacks them; existing assignments are untouched.
     *
     * @param  list<string>  $taxIds
     * @return array{products: int, assignments: int} Products considered and assignments actually created.
     */
    public function assignToAllProducts(string $actor, array $taxIds): array;
}
