<?php

namespace App\Order\Application\Command;

/**
 * What the transport reported. For PHP mail(), "accepted" only means the local mail system took the message for
 * delivery; it does not prove arrival in the inbox.
 */
final readonly class MailDelivery
{
    public function __construct(public bool $accepted, public ?string $error = null) {}
}
