<?php

namespace App\Shared\Infrastructure\Cache;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/** Only explicitly selected public data, never responses, sessions or shopping decisions. */
final class StorefrontCache
{
    public const GROUPS = ['blocks', 'products', 'categories', 'brands', 'media'];

    private ?object $state = null;

    private bool $unavailable = false;

    public function state(): object
    {
        return $this->state ??= DB::table('storefront_cache_state')->where('id', 1)->firstOrFail();
    }

    public function reset(): void
    {
        $this->state = null;
    }

    /** @template T
     * @param  array<mixed>  $identity
     * @param  callable(): T  $load
     * @return T
     */
    public function remember(string $group, array $identity, callable $load): mixed
    {
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException('Unknown storefront cache group.');
        }
        // A transaction may contain unpublished writes; never expose those via shared Redis.
        if (DB::transactionLevel() > 0 || $this->unavailable || ! $this->state()->enabled) {
            return $load();
        }
        $dependencies = match ($group) {
            'blocks' => self::GROUPS,
            'products' => ['products', 'categories', 'media'],
            'categories' => ['categories', 'media'],
            'brands' => ['brands', 'blocks', 'products', 'categories', 'media'],
            default => ['media'],
        };
        $versions = array_map(fn (string $dependency): string => $this->state()->$dependency, $dependencies);
        $key = 'storefront-content:v1:'.$group.':'.hash('sha256', json_encode([$versions, $identity], JSON_THROW_ON_ERROR));
        $lock = null;
        try {
            $store = Cache::store(config('storefront.cache_store', 'storefront'));
            $cached = $store->get($key);
            if (is_array($cached) && array_key_exists('value', $cached)) {
                return $cached['value'];
            }
            $backend = $store->getStore();
            if (! $backend instanceof LockProvider) {
                throw new InvalidArgumentException('Storefront cache requires atomic locks.');
            }
            $lock = $backend->lock($key.':lock', 30);
            $lock->block(2);
            // The lock's previous owner may have filled this miss while we waited.
            $cached = $store->get($key);
            if (is_array($cached) && array_key_exists('value', $cached)) {
                $lock->release();

                return $cached['value'];
            }
        } catch (Throwable $e) {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Redis may be disconnected; the lock has a bounded lease.
                }
            }
            $this->failed($e);

            return $load();
        }
        try {
            $value = $load();
            try {
                // Bounded entries expire naturally after invalidation; never FLUSHDB shared Redis.
                $store->put($key, ['value' => $value], 300 + random_int(0, 30));
            } catch (Throwable $e) {
                $this->failed($e);
            }

            return $value;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                $this->failed($e);
            }
        }
    }

    public function reachable(): bool
    {
        $key = 'storefront-content:health:'.bin2hex(random_bytes(12));
        try {
            $store = Cache::store(config('storefront.cache_store', 'storefront'));
            $store->put($key, 'ok', 10);
            $ok = $store->get($key) === 'ok';
            $store->forget($key);

            return $ok;
        } catch (Throwable $e) {
            $this->failed($e);

            return false;
        }
    }

    private function failed(Throwable $e): void
    {
        if (! $this->unavailable) {
            Log::warning('Storefront content cache unavailable; using live data.', ['exception' => $e::class]);
        }
        $this->unavailable = true;
    }
}
