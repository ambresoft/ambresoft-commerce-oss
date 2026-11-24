<?php

namespace App\Payment\Application\Command;

/** A provider's server-to-server notification exactly as received: the raw body (needed for signatures) and its headers. */
final readonly class PaymentWebhook
{
    /** @param array<string, string> $headers Lower-case header name => first value */
    public function __construct(public string $body, public array $headers) {}

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
