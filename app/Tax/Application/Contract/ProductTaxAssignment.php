<?php

namespace App\Tax\Application\Contract;

/** The Catalog's view of product tax assignments. Called inside the product's own transaction. */
interface ProductTaxAssignment
{
    /** @return list<string> */
    public function assigned(string $productId): array;

    /**
     * Replaces the product's assignments. IDs must exist; a disabled tax is accepted only when already assigned.
     *
     * @param  list<string>  $taxIds
     * @return array{added: list<string>, removed: list<string>}
     */
    public function sync(string $productId, array $taxIds): array;

    /** Gives the target the same assignments as the source (duplication). */
    public function copy(string $fromProductId, string $toProductId): void;
}
