<?php

namespace App\Pricing\Domain\Repository;

use App\Pricing\Domain\Model\Currency;

interface CurrencyRepository
{
    /**
     * Configuration snapshot for display and conversion; may be served from cache.
     *
     * @return list<Currency>
     */
    public function all(): array;

    /** Authoritative primary currency read from storage; use inside write transactions. */
    public function currentPrimary(): Currency;
}
