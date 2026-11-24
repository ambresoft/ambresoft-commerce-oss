<?php

namespace App\Search\Infrastructure\Jobs;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Indexes queued catalog changes. One at a time (unique); the scheduler queues it again while changes are due. */
final class ProcessSearchOutbox implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 90;

    public function handle(SearchIndex $index): void
    {
        $index->sync(40);
    }
}
