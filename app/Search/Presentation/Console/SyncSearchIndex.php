<?php

namespace App\Search\Presentation\Console;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Console\Command;

final class SyncSearchIndex extends Command
{
    protected $signature = 'cms:search-sync {--seconds=50 : How long to keep indexing}';

    protected $description = 'Index queued catalog changes in the search engine now';

    public function handle(SearchIndex $index): int
    {
        $result = $index->sync(max(1, (int) $this->option('seconds')));
        if ($result['busy']) {
            $this->warn('Another worker is indexing; try again shortly.');

            return self::SUCCESS;
        }
        $this->info(sprintf('Indexed %d, removed %d, refused %d.', $result['indexed'], $result['removed'], $result['failed']));

        return self::SUCCESS;
    }
}
