<?php

namespace App\Pricing\Infrastructure\Persistence;

use App\Pricing\Domain\Model\Currency;
use App\Pricing\Domain\Repository\CurrencyRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class EloquentCurrencyRepository implements CurrencyRepository
{
    public const CACHE_KEY = 'pricing.currencies.v1';

    // Bounds staleness if an after-commit invalidation ever fails; writes never read this cache.
    private const CACHE_SECONDS = 600;

    public function __construct(private CurrencyMapper $mapper) {}

    public function all(): array
    {
        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => CurrencyRecord::orderByDesc('is_primary')->orderBy('code')->get()->map(fn (CurrencyRecord $r): array => $this->mapper->row($r))->all());

        return array_map(fn (array $row): Currency => $this->mapper->toDomain($row), $rows);
    }

    public function currentPrimary(): Currency
    {
        return $this->mapper->toDomain($this->mapper->row(CurrencyRecord::where('is_primary', true)->sole()));
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable $e) {
            // The committed change stands; the short TTL bounds display staleness.
            report($e);
        }
    }
}
