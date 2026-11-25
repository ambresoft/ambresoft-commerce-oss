<?php

namespace App\Search\Infrastructure\Jobs;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One slice of a full rebuild; queues the next slice until the run is over. A slice that fails is retried from the
 * run's cursor; after the last attempt the run fails and the live index stays as it was.
 */
final class RebuildSearchIndex implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $runId)
    {
        $this->onQueue('maintenance');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(SearchIndex $index): void
    {
        if (! $index->rebuildStep($this->runId)) {
            self::dispatch($this->runId);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(SearchIndex::class)->failRun($this->runId, __('search.rebuild_gave_up', ['message' => mb_substr((string) $e?->getMessage(), 0, 300)]));
    }
}
