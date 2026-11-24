<?php

namespace App\Tax\Domain\Model;

final readonly class TaxDefinition
{
    public const SHIPPING = 'shipping';

    public const BILLING = 'billing';

    /** @param list<TaxRate> $rates */
    public function __construct(
        public string $id,
        public string $name,
        public int $priority,
        public bool $active,
        public bool $priceIncludesTax,
        public string $addressBasis,
        public array $rates = [],
    ) {}
}
