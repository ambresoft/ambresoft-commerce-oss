<?php

namespace App\Pricing\Domain\Exception;

use DomainException;

/** The requested target is unknown or disabled; callers must never treat it as a valid display currency. */
final class CurrencyUnavailable extends DomainException
{
    public function __construct(public readonly string $currencyCode)
    {
        parent::__construct('Currency '.$currencyCode.' is not an active configured currency.');
    }
}
