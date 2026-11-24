<?php

namespace App\Shipping\Application\Command;

use InvalidArgumentException;

/**
 * The latest known state of a shipment. `state` is normalized; `code` is the carrier's own status code. Only
 * `delivered` and `returned` can trigger order status changes, and only when the carrier reported them explicitly.
 */
final readonly class TrackingResult
{
    public const STATES = ['created', 'in_transit', 'delivered', 'returning', 'returned', 'exception', 'unknown'];

    /** @param list<array{at: ?string, action: string, location: string, notes: string}> $events */
    public function __construct(
        public string $state,
        public ?string $code = null,
        public string $summary = '',
        public ?string $deliveredAt = null,
        public ?string $returnedAt = null,
        public array $events = [],
    ) {
        if (! in_array($state, self::STATES, true)) {
            throw new InvalidArgumentException('Unknown tracking state.');
        }
    }
}
