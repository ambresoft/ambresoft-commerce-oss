<?php

namespace App\Payment\Application\Command;

/** The provider's identifier for the opened payment and the URL where the customer approves it. */
final readonly class PaymentRedirect
{
    public function __construct(public string $providerReference, public string $url) {}
}
