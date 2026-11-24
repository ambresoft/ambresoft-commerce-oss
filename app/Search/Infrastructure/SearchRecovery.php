<?php

namespace App\Search\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Search health comes from current queued work, not historical errors that have already recovered. */
final class SearchRecovery
{
    /** @return array{status:string,pending:int,hidden:int,errors:int,failed:int,stalled:int}|null */
    public function status(): ?array
    {
        if (app(SearchSettings::class)->provider() === null) {
            return null;
        }
        $row = DB::table('search_outbox')->selectRaw("count(*) AS pending,
            count(*) FILTER (WHERE critical) AS hidden,
            count(*) FILTER (WHERE last_error IS NOT NULL OR failed_at IS NOT NULL) AS errors,
            count(*) FILTER (WHERE failed_at IS NOT NULL) AS failed,
            count(*) FILTER (WHERE queued_at < clock_timestamp() - interval '5 minutes') AS stalled")->first();

        return ['status' => ((int) $row->errors > 0 || (int) $row->stalled > 0) ? 'attention' : ((int) $row->pending > 0 ? 'pending' : 'healthy'),
            'pending' => (int) $row->pending, 'hidden' => (int) $row->hidden, 'errors' => (int) $row->errors, 'failed' => (int) $row->failed, 'stalled' => (int) $row->stalled];
    }

    /** Refresh supervised workers and index a bounded batch with the current web process's code.
     * @return array{indexed:int,removed:int,failed:int,busy:bool,complete:bool} */
    public function repair(string $actor): array
    {
        $settings = app(SearchSettings::class);
        $provider = $settings->provider();
        if ($provider === null) {
            throw ValidationException::withMessages(['search' => __('search.no_provider')]);
        }
        $lock = Cache::lock('search-admin-recovery', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['search' => __('search.repair_busy')]);
        }
        try {
            $health = $provider->health($settings->values($provider), $settings->languages());
            if (! $health->reachable) {
                throw ValidationException::withMessages(['search' => __('search.repair_unreachable')]);
            }
            // Laravel's graceful signal lets the current job finish before supervised workers restart.
            Artisan::call('queue:restart');
            DB::table('search_outbox')->update(['failed_at' => null, 'attempts' => 0, 'available_at' => DB::raw('clock_timestamp()')]);

            $index = app(SearchIndex::class);
            $running = DB::table('search_index_runs')->where('status', 'running')->where('provider', $provider->id())->value('id');
            if ($running !== null || ! $health->ready || ! $health->current || ! $index->built($provider)) {
                $run = $running === null ? $index->startRebuild($actor) : (int) $running;
                $index->rebuildStep($run, 5);
            }

            $result = $index->sync(5);
            $health = $provider->health($settings->values($provider), $settings->languages());

            return [...$result, 'complete' => $health->ready && $health->current && $index->built($provider)
                && ! DB::table('search_outbox')->exists() && ! DB::table('search_index_runs')->where('status', 'running')->exists()];
        } finally {
            $lock->release();
        }
    }
}
