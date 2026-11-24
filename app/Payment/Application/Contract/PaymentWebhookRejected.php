<?php

namespace App\Payment\Application\Contract;

use RuntimeException;

/** The notification cannot be trusted or understood (bad signature, malformed body, missing configuration). */
final class PaymentWebhookRejected extends RuntimeException
{
    public const INVALID_SIGNATURE = 'invalid_signature';

    public const MALFORMED = 'malformed';

    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
