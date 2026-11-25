<?php

namespace App\Payment\Application\Command;

use InvalidArgumentException;

/**
 * A verified provider event translated for the CMS. `providerReference` identifies the attempt (the reference the
 * provider returned when it was opened) and `reference` is the attempt reference we sent as the provider's own
 * identifier; the CMS checks both against its records. `result` is null for events that need no payment change.
 */
final readonly class PaymentWebhookEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $providerReference = null,
        public ?string $reference = null,
        public ?PaymentResult $result = null,
    ) {
        if ($id === '' || strlen($id) > 255 || $type === '' || strlen($type) > 100) {
            throw new InvalidArgumentException('Invalid payment webhook event.');
        }
    }
}
