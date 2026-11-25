<?php

namespace App\Search\Presentation\Console;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Builds a new search index from the whole catalog and makes it live once it is complete; the current index serves
 * searches meanwhile. Queued for the worker by default; `--now` builds in this process (first setup, large catalogs).
 */
final class RebuildSearchIndexCommand extends Command
{
    protected $signature = 'cms:search-reindex {--now : Build in this process instead of the queue}';

    protected $description = 'Rebuild the product search index without interrupting search';

    public function handle(SearchIndex $index): int
    {
        try {
            $run = $index->startRebuild(null);
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }
        if (! $this->option('now')) {
            $this->info("Rebuild #$run queued; follow it in Settings → Search.");

            return self::SUCCESS;
        }
        while (! $index->rebuildStep($run, 30)) {
            $row = DB::table('search_index_runs')->where('id', $run)->first();
            $this->line(sprintf('%d / %d products indexed…', $row->indexed, $row->expected));
        }
        $row = DB::table('search_index_runs')->where('id', $run)->first();
        if ($row->status !== 'completed') {
            $this->error("Rebuild #$run $row->status: $row->message");

            return self::FAILURE;
        }
        $this->info("Rebuild #$run completed: $row->indexed products, now live.");

        return self::SUCCESS;
    }
}
