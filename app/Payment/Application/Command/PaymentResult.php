<?php

namespace App\Payment\Application\Command;

use InvalidArgumentException;

/**
 * A provider-verified outcome. `open`: not approved yet; `captured`: money received; `pending`: the provider holds the
 * capture for review; `failed` / `cancelled`: no money moved. `detail` is a short machine code, never provider text.
 */
final readonly class PaymentResult
{
    public const STATES = ['open', 'captured', 'pending', 'failed', 'cancelled'];

    public function __construct(
        public string $state,
        public ?string $captureReference = null,
        public ?string $amount = null,
        public ?string $currency = null,
        public ?string $detail = null,
    ) {
        if (! in_array($state, self::STATES, true) || ($detail !== null && preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $detail) !== 1)) {
            throw new InvalidArgumentException('Invalid payment result.');
        }
    }
}
