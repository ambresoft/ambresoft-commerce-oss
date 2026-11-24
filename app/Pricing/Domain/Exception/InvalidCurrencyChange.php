<?php

namespace App\Pricing\Domain\Exception;

use DomainException;

/** Message is a translation key; parameters are its placeholder values. */
final class InvalidCurrencyChange extends DomainException
{
    /** @param array<string, string> $parameters */
    public function __construct(string $key, public readonly array $parameters = [])
    {
        parent::__construct($key);
    }
}
