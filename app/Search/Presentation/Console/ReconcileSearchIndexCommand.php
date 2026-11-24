<?php

namespace App\Search\Presentation\Console;

use App\Search\Infrastructure\SearchIndex;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ReconcileSearchIndexCommand extends Command
{
    protected $signature = 'cms:search-reconcile';

    protected $description = 'Compare the search index with the catalog and queue missing or stale products';

    public function handle(SearchIndex $index): int
    {
        try {
            $summary = $index->reconcile();
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }
        $this->info(sprintf('Indexed %d of %d publishable products; queued %d missing and %d to remove; retried %d.', $summary['indexed'], $summary['eligible'], $summary['missing'], $summary['extra'], $summary['retried']));

        return self::SUCCESS;
    }
}
