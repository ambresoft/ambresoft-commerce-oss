<?php

namespace App\Tax\Application\Contract;

use App\Tax\Domain\Model\TaxContext;

/**
 * The tax view of a customer (Phase 9): default billing and shipping addresses and the tax-exemption flag.
 * Pass the result to TaxCalculationService; the customer record stays the single source of these values.
 */
interface CustomerTaxProfiles
{
    /** Null when the customer does not exist. A customer without addresses yields a context without addresses. */
    public function context(string $customerId, ?string $method = null): ?TaxContext;
}
