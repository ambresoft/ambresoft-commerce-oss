<?php

namespace App\Payment\Application\Command;

/** Everything a redirect provider needs to open a payment for one attempt, all calculated by the server. */
final readonly class PaymentStart
{
    public function __construct(
        public string $reference,
        public PaymentAmount $amount,
        public string $orderNumber,
        public string $locale,
        public string $returnUrl,
        public string $cancelUrl,
        public string $storeName,
        /** The order's e-mail, so the provider need not ask for it again; optional for contract-2 add-ons. */
        public ?string $email = null,
    ) {}
}
