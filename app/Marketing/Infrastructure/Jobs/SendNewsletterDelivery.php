<?php

namespace App\Marketing\Infrastructure\Jobs;

use App\Marketing\Infrastructure\NewsletterDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends one newsletter delivery; the delivery record itself guards against double sending. */
final class SendNewsletterDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public string $deliveryId) {}

    public function handle(NewsletterDelivery $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
