<?php

namespace Ambresoft\StripeCheckout\Infrastructure;

use Stripe\StripeClient;

/**
 * Builds the official SDK client for a method's decrypted settings. The SDK retries connection failures itself and
 * reuses the request's idempotency key for those retries, so a timeout never creates a second payable session.
 */
final class StripeClients
{
    public const NETWORK_RETRIES = 2;

    /** @param array<string, string> $values */
    public function make(array $values): StripeClient
    {
        return new StripeClient(['api_key' => $values['secret_key'] ?? '', 'max_network_retries' => self::NETWORK_RETRIES]);
    }
}
