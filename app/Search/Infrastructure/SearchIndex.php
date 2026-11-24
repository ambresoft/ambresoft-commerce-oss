<?php

namespace App\Search\Infrastructure;

use App\Search\Application\Contract\SearchProvider;
use App\Search\Application\Contract\SearchUnavailable;
use App\Search\Infrastructure\Jobs\ProcessSearchOutbox;
use App\Search\Infrastructure\Jobs\RebuildSearchIndex;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the active provider's index in step with the catalog.
 *
 * - Changes: catalog triggers queue products in `search_outbox` within the changing transaction; sync() indexes or
 *   removes them in batches and deletes a row only for the version it indexed, so a change made meanwhile is picked up
 *   next time. Refused documents are retried with growing delays and give up after MAX_ATTEMPTS (kept as failed for the
 *   administration and reconciliation); an unreachable engine is retried every OUTAGE_DELAY seconds without giving up.
 * - Rebuilds: a new index is filled batch by batch (resumable), verified complete and only then made live; queued
 *   changes are written to both indexes meanwhile. All writes share one lock, so a rebuild batch never overwrites a
 *   newer change with data it read before that change.
 * - Reconciliation compares the live index with the catalog and queues what is missing or should not be there.
 */
final class SearchIndex
{
    public const BATCH = 250;

    public const MAX_ATTEMPTS = 8;

    /** Seconds between retries while the engine cannot be reached. */
    public const OUTAGE_DELAY = 30;

    private const LOCK = 'search-index-write';

    public function __construct(private SearchSettings $settings, private SearchDocuments $documents) {}

    /** Queues index work when changes are due; called by the scheduler every few seconds. */
    public function dispatchDue(): void
    {
        if ($this->settings->provider() !== null && DB::table('search_outbox')->whereNull('failed_at')->whereRaw('available_at <= clock_timestamp()')->exists()) {
            ProcessSearchOutbox::dispatch();
        }
    }

    /**
     * Indexes due queued changes for up to `$seconds`.
     *
     * @return array{indexed: int, removed: int, failed: int, busy: bool}
     */
    public function sync(int $seconds = 40): array
    {
        $totals = ['indexed' => 0, 'removed' => 0, 'failed' => 0, 'busy' => false];
        $provider = $this->settings->provider();
        if ($provider === null) {
            return $totals;
        }
        $lock = Cache::lock(self::LOCK, 120);
        if (! $lock->get()) {
            return [...$totals, 'busy' => true];
        }
        try {
            $values = $this->settings->values($provider);
            $provider->prepare($values, $this->settings->languages());
            $deadline = microtime(true) + $seconds;
            do {
                $rows = $this->due();
                if ($rows->isEmpty()) {
                    break;
                }
                $result = $this->batch($provider, $values, $rows, $this->runningTarget($provider));
                foreach ($result as $key => $count) {
                    $totals[$key] += $count;
                }
            } while (microtime(true) < $deadline);
        } catch (SearchUnavailable $e) {
            // Whatever is due waits (without using up attempts) until the engine answers again.
            if ($e->reason !== 'rejected') {
                DB::table('search_outbox')->whereNull('failed_at')->whereRaw('available_at <= clock_timestamp()')
                    ->update(['available_at' => now()->addSeconds(self::OUTAGE_DELAY), 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
            $this->error($e->getMessage());
        } finally {
            $lock->release();
        }

        return $totals;
    }

    /**
     * Starts a full rebuild into a new index and queues its first batch.
     */
    public function startRebuild(?string $actor): int
    {
        $provider = $this->settings->provider() ?? throw ValidationException::withMessages(['search' => __('search.no_provider')]);
        if (DB::table('search_index_runs')->where('status', 'running')->exists()) {
            throw ValidationException::withMessages(['search' => __('search.rebuild_running')]);
        }
        try {
            $target = $provider->startRebuild($this->settings->values($provider), $this->settings->languages());
        } catch (SearchUnavailable $e) {
            throw ValidationException::withMessages(['search' => __('search.unavailable_detail', ['message' => $e->getMessage()])]);
        }
        $id = (int) DB::table('search_index_runs')->insertGetId([
            'provider' => $provider->id(), 'target' => mb_substr($target, 0, 120), 'status' => 'running', 'expected' => $this->documents->eligible()->count(),
            'started_by' => $actor, 'started_at' => now(), 'updated_at' => now(),
        ]);
        RebuildSearchIndex::dispatch($id);

        return $id;
    }

    /**
     * Indexes the next batches of a rebuild for up to `$seconds`; finishes it after the last one.
     *
     * @return bool Whether the run is over (finished, failed or cancelled)
     */
    public function rebuildStep(int $runId, int $seconds = 30): bool
    {
        $run = DB::table('search_index_runs')->where('id', $runId)->first();
        $provider = $this->settings->provider();
        if ($run === null || $run->status !== 'running') {
            return true;
        }
        if ($provider === null || $provider->id() !== $run->provider) {
            $this->endRun($runId, 'failed', __('search.rebuild_provider_changed'));

            return true;
        }
        $lock = $this->lock(15, $seconds + 60);
        try {
            $values = $this->settings->values($provider);
            $deadline = microtime(true) + $seconds;
            do {
                $run = DB::table('search_index_runs')->where('id', $runId)->first();
                if ($run->status !== 'running') {
                    return true;
                }
                $ids = $this->documents->eligible()->where('products.id', '>', $run->cursor)->orderBy('products.id')->limit(self::BATCH)->pluck('products.id')->map(strval(...))->all();
                if ($ids === []) {
                    $this->finishRun($provider, $values, $run);

                    return true;
                }
                $documents = $this->documents->build($ids)['documents'];
                $failures = $documents === [] ? [] : $provider->index($documents, $values, $run->target);
                $errors = json_decode((string) $run->errors, true) ?: [];
                foreach ($failures as $product => $message) {
                    if (count($errors) < 20) {
                        $errors[] = ['product' => (string) $product, 'message' => mb_substr($message, 0, 300)];
                    }
                }
                DB::table('search_index_runs')->where('id', $runId)->update([
                    'cursor' => (int) end($ids), 'indexed' => (int) $run->indexed + count($documents) - count($failures), 'failed' => (int) $run->failed + count($failures),
                    'errors' => json_encode($errors, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            } while (microtime(true) < $deadline);

            return false;
        } catch (SearchUnavailable $e) {
            // The run stays resumable from its cursor; the job retries it.
            DB::table('search_index_runs')->where('id', $runId)->update(['message' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function cancelRebuild(int $runId): void
    {
        $run = DB::table('search_index_runs')->where('id', $runId)->where('status', 'running')->first() ?? throw ValidationException::withMessages(['search' => __('search.rebuild_not_running')]);
        $provider = $this->settings->provider();
        if ($provider !== null && $provider->id() === $run->provider) {
            rescue(fn () => $provider->discardRebuild($run->target, $this->settings->values($provider)), report: false);
        }
        $this->endRun($runId, 'cancelled', __('search.rebuild_cancelled'));
    }

    /** Marks a run failed after its job gave up (the live index is untouched). */
    public function failRun(int $runId, string $message): void
    {
        $run = DB::table('search_index_runs')->where('id', $runId)->where('status', 'running')->first();
        if ($run === null) {
            return;
        }
        $provider = $this->settings->provider();
        if ($provider !== null && $provider->id() === $run->provider) {
            rescue(fn () => $provider->discardRebuild($run->target, $this->settings->values($provider)), report: false);
        }
        $this->endRun($runId, 'failed', $message);
    }

    /**
     * Compares the live index with the catalog: queues products missing from it, queues (as critical, so they are
     * hidden at once) documents that should not be there, and retries changes that had given up.
     *
     * @return array{indexed: int, eligible: int, missing: int, extra: int, retried: int}
     */
    public function reconcile(): array
    {
        $provider = $this->settings->provider() ?? throw ValidationException::withMessages(['search' => __('search.no_provider')]);
        $values = $this->settings->values($provider);
        $summary = DB::transaction(function () use ($provider, $values): array {
            DB::statement('CREATE TEMPORARY TABLE search_indexed (id bigint PRIMARY KEY) ON COMMIT DROP');
            $chunk = [];
            $indexed = 0;
            foreach ($provider->indexedIds($values) as $id) {
                if (preg_match('/^[1-9]\d{0,18}$/D', $id) !== 1) {
                    continue;
                }
                $chunk[] = (int) $id;
                $indexed++;
                if (count($chunk) === 5000) {
                    DB::insert('INSERT INTO search_indexed (id) SELECT DISTINCT unnest(?::bigint[]) ON CONFLICT DO NOTHING', ['{'.implode(',', $chunk).'}']);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                DB::insert('INSERT INTO search_indexed (id) SELECT DISTINCT unnest(?::bigint[]) ON CONFLICT DO NOTHING', ['{'.implode(',', $chunk).'}']);
            }
            $eligible = $this->documents->eligible();
            $missing = (clone $eligible)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('search_indexed')->whereColumn('search_indexed.id', 'products.id'))->pluck('products.id')->all();
            $extra = DB::table('search_indexed')->whereNotIn('id', (clone $eligible)->select('products.id'))->pluck('id')->all();
            $this->enqueue($missing, false);
            $this->enqueue($extra, true);
            $retried = DB::table('search_outbox')->whereNotNull('failed_at')->update(['failed_at' => null, 'attempts' => 0, 'available_at' => now()]);

            return ['indexed' => $indexed, 'eligible' => $eligible->count(), 'missing' => count($missing), 'extra' => count($extra), 'retried' => $retried];
        });
        DB::table('search_index_state')->where('id', 1)->update(['last_reconcile_at' => now(), 'last_reconcile' => json_encode($summary, JSON_THROW_ON_ERROR)]);

        return $summary;
    }

    /** @param list<int|string> $ids */
    public function enqueue(array $ids, bool $critical): void
    {
        foreach (array_chunk($ids, 5000) as $chunk) {
            DB::select('SELECT cms_search_enqueue(?::bigint[], ?)', ['{'.implode(',', array_map(intval(...), $chunk)).'}', $critical]);
        }
    }

    /** Whether the active provider's index has been built completely at least once. */
    public function built(SearchProvider $provider): bool
    {
        return DB::table('search_index_state')->where('id', 1)->value('built_provider') === $provider->id();
    }

    /**
     * Rows whose time has come, by the database clock: triggers write microsecond times, which PHP's whole-second
     * timestamps would see as not yet due within the same second.
     *
     * @return Collection<int, \stdClass>
     */
    private function due(): Collection
    {
        return DB::table('search_outbox')->whereNull('failed_at')->whereRaw('available_at <= clock_timestamp()')
            ->orderByDesc('critical')->orderBy('version')->limit(self::BATCH)->get(['product_id', 'version', 'attempts']);
    }

    /**
     * @param  array<string, string>  $values
     * @param  Collection<int, \stdClass>  $rows
     * @return array{indexed: int, removed: int, failed: int}
     */
    private function batch(SearchProvider $provider, array $values, Collection $rows, ?string $target): array
    {
        ['documents' => $documents, 'remove' => $remove] = $this->documents->build($rows->pluck('product_id')->map(strval(...))->all());
        try {
            $failures = [];
            // During a rebuild the new index gets every change too, so it is current when it goes live.
            foreach (array_values(array_unique([null, $target])) as $index) {
                try {
                    if ($documents !== []) {
                        $failures += $provider->index($documents, $values, $index);
                    }
                    if ($remove !== []) {
                        $provider->remove($remove, $values, $index);
                    }
                } catch (SearchUnavailable $e) {
                    // Before the first build completes there is no live index yet; the new one gets the change.
                    if ($index !== null || $target === null || $e->reason !== 'not_initialized') {
                        throw $e;
                    }
                }
            }
        } catch (SearchUnavailable $e) {
            $this->delay($rows, $e);
            throw $e;
        }
        $failed = $rows->filter(fn (object $r): bool => isset($failures[(string) $r->product_id]));
        foreach ($failed as $row) {
            $this->retry($row, $failures[(string) $row->product_id]);
        }
        $this->done($rows->reject(fn (object $r): bool => isset($failures[(string) $r->product_id])));
        DB::table('search_index_state')->where('id', 1)->update(['last_sync_at' => now()]);

        return ['indexed' => count($documents) - count($failures), 'removed' => count($remove), 'failed' => $failed->count()];
    }

    /** @param Collection<int, \stdClass> $rows */
    private function done(Collection $rows): void
    {
        foreach ($rows->chunk(500) as $chunk) {
            $pairs = $chunk->map(fn (object $r): string => '('.(int) $r->product_id.','.(int) $r->version.')')->implode(',');
            DB::delete("DELETE FROM search_outbox WHERE (product_id, version) IN ($pairs)");
        }
    }

    /** A refused document: retried with growing delays, then kept as failed. */
    private function retry(object $row, string $message): void
    {
        $attempts = (int) $row->attempts + 1;
        DB::table('search_outbox')->where('product_id', $row->product_id)->where('version', $row->version)->update([
            'attempts' => $attempts, 'available_at' => now()->addSeconds(min(3600, 15 * 2 ** ($attempts - 1))),
            'failed_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null, 'last_error' => mb_substr($message, 0, 500),
        ]);
    }

    /**
     * The engine could not be reached or refused the request: the batch waits and is tried again. A refusal counts as an
     * attempt (it may never succeed); an outage does not.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    private function delay(Collection $rows, SearchUnavailable $e): void
    {
        if ($e->reason === 'rejected') {
            foreach ($rows as $row) {
                $this->retry($row, $e->getMessage());
            }

            return;
        }
        foreach ($rows->chunk(500) as $chunk) {
            $pairs = $chunk->map(fn (object $r): string => '('.(int) $r->product_id.','.(int) $r->version.')')->implode(',');
            DB::update("UPDATE search_outbox SET available_at = now() + interval '".self::OUTAGE_DELAY." seconds', last_error = ? WHERE (product_id, version) IN ($pairs)", [mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /**
     * Verifies a rebuild before it goes live: every queued change written, nothing refused, and as many documents as
     * the catalog has publishable products. Otherwise the new index is dropped and the live one stays.
     *
     * @param  array<string, string>  $values
     */
    private function finishRun(SearchProvider $provider, array $values, object $run): void
    {
        // Changes queued during the rebuild reach the new index before it is counted.
        while (($rows = $this->due())->isNotEmpty()) {
            $this->batch($provider, $values, $rows, $run->target);
        }
        $expected = $this->documents->eligible()->count();
        $count = $provider->count($run->target, $values);
        $problem = match (true) {
            (int) $run->failed > 0 => __('search.rebuild_refused', ['count' => (int) $run->failed]),
            $count !== $expected => __('search.rebuild_incomplete', ['indexed' => $count, 'expected' => $expected]),
            default => null,
        };
        if ($problem !== null) {
            rescue(fn () => $provider->discardRebuild($run->target, $values), report: false);
            $this->endRun((int) $run->id, 'failed', $problem);

            return;
        }
        $provider->configure($values, $this->settings->languages());
        $provider->finishRebuild($run->target, $values);
        DB::table('search_index_runs')->where('id', $run->id)->update(['expected' => $expected, 'status' => 'completed', 'message' => null, 'finished_at' => now(), 'updated_at' => now()]);
        DB::table('search_index_state')->where('id', 1)->update(['built_provider' => $provider->id(), 'built_at' => now()]);
    }

    private function endRun(int $runId, string $status, string $message): void
    {
        DB::table('search_index_runs')->where('id', $runId)->update(['status' => $status, 'message' => mb_substr($message, 0, 500), 'finished_at' => now(), 'updated_at' => now()]);
        if ($status === 'failed') {
            $this->error($message);
        }
    }

    private function runningTarget(SearchProvider $provider): ?string
    {
        $run = DB::table('search_index_runs')->where('status', 'running')->where('provider', $provider->id())->first(['target']);

        return $run?->target;
    }

    /** Waits up to `$wait` seconds for the write lock (another batch may be finishing); throws when it stays taken. */
    private function lock(int $wait, int $hold): Lock
    {
        $lock = Cache::lock(self::LOCK, $hold);
        $lock->block($wait);

        return $lock;
    }

    private function error(string $message): void
    {
        Log::warning('search_index_error', ['message' => mb_substr($message, 0, 300)]);
        DB::table('search_index_state')->where('id', 1)->update(['last_error_at' => now(), 'last_error' => mb_substr($message, 0, 500)]);
    }
}
