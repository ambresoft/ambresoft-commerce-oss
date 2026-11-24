<?php

namespace App\Search\Presentation\Console;

use App\Search\Infrastructure\SearchSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Deletes old search indexes: never the live one or the most recent previous one, never during a rebuild. */
final class CleanupSearchIndexes extends Command
{
    protected $signature = 'cms:search-cleanup';

    protected $description = 'Delete search indexes that are neither live nor the previous one';

    public function handle(SearchSettings $settings): int
    {
        $provider = $settings->provider();
        if ($provider === null || DB::table('search_index_runs')->where('status', 'running')->exists()) {
            $this->warn($provider === null ? 'No search provider is enabled.' : 'A rebuild is running; clean up after it.');

            return self::SUCCESS;
        }
        $deleted = $provider->cleanup($settings->values($provider));
        $this->info($deleted === [] ? 'Nothing to delete.' : 'Deleted: '.implode(', ', $deleted));

        return self::SUCCESS;
    }
}
