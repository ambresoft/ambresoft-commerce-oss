<?php

namespace App\Shipping\Application\Command;

/** One named carrier account with its decrypted values; only ever passed to the provider that owns it. */
final readonly class CarrierAccount
{
    /** @param array<string, string> $values */
    public function __construct(public string $id, public string $name, public array $values) {}
}
