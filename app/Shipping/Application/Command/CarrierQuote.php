<?php

namespace App\Shipping\Application\Command;

/**
 * A carrier's answer for one shipment: net amount, its VAT and the gross amount in `currency` (decimal strings), or
 * unavailable with a short machine reason and an optional safe message. `details` is a safe summary for the order.
 */
final readonly class CarrierQuote
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public bool $available,
        public string $net = '0',
        public string $tax = '0',
        public string $gross = '0',
        public string $currency = '',
        public array $details = [],
        public ?string $reason = null,
        public ?string $message = null,
    ) {}

    /** @param array<string, mixed> $details */
    public static function priced(string $net, string $tax, string $gross, string $currency, array $details = []): self
    {
        return new self(true, $net, $tax, $gross, $currency, $details);
    }

    public static function unavailable(string $reason, ?string $message = null): self
    {
        return new self(false, reason: $reason, message: $message);
    }
}
