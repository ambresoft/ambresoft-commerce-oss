<?php

namespace App\Tax\Domain\Model;

/** Who is buying and where. A null method uses the configured calculation method. */
final readonly class TaxContext
{
    public function __construct(
        public ?Address $shipping = null,
        public ?Address $billing = null,
        public bool $exempt = false,
        public ?string $method = null,
    ) {}
}
