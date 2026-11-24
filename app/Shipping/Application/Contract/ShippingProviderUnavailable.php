<?php

namespace App\Shipping\Application\Contract;

use RuntimeException;

/** The carrier could not be reached or answered unexpectedly. For non-idempotent calls the outcome is unknown. */
final class ShippingProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason = 'provider_unavailable')
    {
        parent::__construct($reason);
    }
}
