<?php

namespace App\Payment\Application\Contract;

use RuntimeException;

/** The provider could not be reached or answered unexpectedly; nothing was decided and the attempt can be retried. */
final class PaymentProviderUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason = 'provider_unavailable')
    {
        parent::__construct($reason);
    }
}
