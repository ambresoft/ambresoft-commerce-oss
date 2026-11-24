<?php

namespace App\Shipping\Application\Contract;

use RuntimeException;

/**
 * The carrier refused the request (invalid data, credentials or business rule). Nothing was created; the message is
 * safe for administrators (the carrier's own wording or a translated reason, never credentials).
 */
final class ShippingProviderRejected extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message === '' ? $reason : $message);
    }
}
