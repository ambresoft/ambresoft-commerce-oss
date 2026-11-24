<?php

namespace App\Payment\Application\Command;

/** One recorded attempt to confirm: our reference, the provider's reference and the amount that was requested. */
final readonly class PaymentCheck
{
    public function __construct(public string $reference, public string $providerReference, public PaymentAmount $expected) {}
}
