<?php

namespace App\Search\Infrastructure\Jobs;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Compares the live index with the catalog and queues what differs. */
final class ReconcileSearchIndex implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Below the Redis queue's 90-second retry_after, so a slow run is never started twice.
    public int $timeout = 80;

    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(SearchIndex $index): void
    {
        $index->reconcile();
    }
}
