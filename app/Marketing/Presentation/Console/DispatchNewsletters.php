<?php

namespace App\Marketing\Presentation\Console;

use App\Marketing\Infrastructure\NewsletterDelivery;
use Illuminate\Console\Command;

final class DispatchNewsletters extends Command
{
    protected $signature = 'cms:newsletters-dispatch';

    protected $description = 'Queue waiting, retryable and interrupted newsletter deliveries and close finished newsletters';

    public function handle(NewsletterDelivery $deliveries): int
    {
        $count = $deliveries->dispatchPending();
        $this->info($count === 0 ? 'No newsletter deliveries are waiting.' : "Queued $count newsletter deliveries.");

        return self::SUCCESS;
    }
}
